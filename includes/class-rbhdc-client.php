<?php
/**
 * Reloom proxy client.
 *
 * Talks to a Reloom (reloom.life) install's token-authenticated machine API
 * (namespace /api/v1) using a saved API base URL + bearer token. All calls run
 * server-side so the token never reaches the browser. A token is obtained
 * either by the one-click Connect wizard (OAuth-style, PKCE) or pasted manually.
 *
 * @package Reloom\HumanDesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin HTTP wrapper around the Reloom /api/v1 proxy + settings storage.
 */
class RBHDC_Client {

	const OPTION       = 'rbhdc_settings';
	const META_TTL     = 300; // seconds — cache /meta briefly.
	const DEFAULT_HOST = 'https://reloom.life';

	/**
	 * Saved settings.
	 *
	 * @return array{api_base:string,api_token:string,sync:bool,reloom_host:string}
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'api_base'    => isset( $s['api_base'] ) ? (string) $s['api_base'] : '',
			'api_token'   => isset( $s['api_token'] ) ? (string) $s['api_token'] : '',
			// Consent to sync created profiles back to Reloom — ON by default.
			'sync'        => ! isset( $s['sync'] ) || ! empty( $s['sync'] ),
			// Reloom site the Connect wizard authorizes against.
			'reloom_host'  => ! empty( $s['reloom_host'] ) ? untrailingslashit( (string) $s['reloom_host'] ) : self::DEFAULT_HOST,
			// "Powered by" branding in exported PDFs — OFF by default; the admin
			// must explicitly opt in (WP.org guideline 10: no credits without consent).
			'pdf_branding' => ! empty( $s['pdf_branding'] ),
		);
	}

	/** Has the admin opted in to "Powered by" branding in exported PDFs? */
	public static function is_pdf_branding_on() {
		$s = self::settings();
		return ! empty( $s['pdf_branding'] );
	}

	/** Persist the PDF-branding opt-in (merges, preserves the rest). */
	public static function set_pdf_branding( $on ) {
		$s                 = get_option( self::OPTION, array() );
		$s                 = is_array( $s ) ? $s : array();
		$s['pdf_branding'] = $on ? 1 : 0;
		update_option( self::OPTION, $s, false );
	}

	/** Is sync-back consent enabled? */
	public static function is_sync_on() {
		$s = self::settings();
		return ! empty( $s['sync'] );
	}

	/** Persist the sync consent flag (merges, preserves the rest). */
	public static function set_sync( $on ) {
		$s         = get_option( self::OPTION, array() );
		$s         = is_array( $s ) ? $s : array();
		$s['sync'] = $on ? 1 : 0;
		update_option( self::OPTION, $s, false );
	}

	/**
	 * Persist connection settings.
	 *
	 * @param string      $base        API base URL (…/api/v1).
	 * @param string      $token       Bearer token.
	 * @param bool|null   $sync        Sync consent, or null to leave unchanged.
	 * @param string|null $reloom_host Reloom site origin, or null to leave unchanged.
	 * @return void
	 */
	public static function save_settings( $base, $token, $sync = null, $reloom_host = null ) {
		$base  = trim( (string) $base );
		$token = trim( (string) $token );

		// Tolerant: if a full "Shareable URL" (…/api/v1/meta?key=XXX) is pasted
		// into the base field, split out the key and strip the trailing resource
		// down to the /api/v1 base.
		if ( false !== strpos( $base, '?' ) || preg_match( '#/(meta|chart|readings?|profiles?|locations)(/|\?|$)#i', $base ) ) {
			$parts = wp_parse_url( $base );
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $q );
				if ( '' === $token && ! empty( $q['key'] ) ) {
					$token = (string) $q['key'];
				}
			}
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			$path = preg_replace( '#/(meta|chart|readings?|profiles?|locations)(/.*)?$#i', '', $path );
			if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
				$base = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path;
			}
		}

		$cur = get_option( self::OPTION, array() );
		$cur = is_array( $cur ) ? $cur : array();

		$cur['api_base']  = untrailingslashit( esc_url_raw( $base ) );
		$cur['api_token'] = $token;
		if ( null !== $sync ) {
			$cur['sync'] = $sync ? 1 : 0;
		}
		if ( null !== $reloom_host ) {
			$cur['reloom_host'] = untrailingslashit( esc_url_raw( trim( (string) $reloom_host ) ) );
		}
		update_option( self::OPTION, $cur, false );
	}

	/** Are we configured (base + token present)? */
	public static function is_configured() {
		$s = self::settings();
		return '' !== $s['api_base'] && '' !== $s['api_token'];
	}

	/**
	 * Low-level GET against the proxy. Returns decoded body or WP_Error.
	 *
	 * @param string $path  e.g. '/meta', '/chart'.
	 * @param array  $query Query args.
	 * @return array|WP_Error
	 */
	public static function get( $path, array $query = array() ) {
		$s = self::settings();
		if ( '' === $s['api_base'] || '' === $s['api_token'] ) {
			return new WP_Error( 'rbhdc_unconfigured', __( 'Connect to Reloom on the Settings page first.', 'reloom-human-design' ) );
		}
		$url = $s['api_base'] . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		$resp = wp_remote_get(
			$url,
			array(
				// Readings call the AI on the server and can take 10–30s+.
				'timeout' => 90,
				'headers' => self::auth_headers( $s ),
			)
		);
		return self::handle( $resp );
	}

	/** Auth + accept headers for an authenticated call. */
	private static function auth_headers( array $s ) {
		return array(
			'Authorization' => 'Bearer ' . $s['api_token'],
			'Accept'        => 'application/json',
		);
	}

	/** Normalize a wp_remote_* response into decoded body or WP_Error. */
	private static function handle( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $body ) && isset( $body['message'] )
				? (string) $body['message']
				: sprintf( /* translators: %d: HTTP code. */ __( 'Reloom returned HTTP %d.', 'reloom-human-design' ), $code );
			return new WP_Error( 'rbhdc_http', $msg, array( 'status' => $code ) );
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'rbhdc_parse', __( 'Reloom response was not valid JSON.', 'reloom-human-design' ) );
		}
		return $body;
	}

	/**
	 * Discovery: account name + active scopes. Cached briefly.
	 *
	 * @param bool $fresh Bypass cache.
	 * @return array|WP_Error
	 */
	public static function meta( $fresh = false ) {
		$ck = 'rbhdc_meta_' . md5( wp_json_encode( self::settings() ) );
		if ( ! $fresh ) {
			$cached = get_transient( $ck );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$res = self::get( '/meta' );
		if ( ! is_wp_error( $res ) ) {
			set_transient( $ck, $res, self::META_TTL );
		}
		return $res;
	}

	/**
	 * Which scope keys are enabled (chart|quick|channels|centers|…). Empty on error.
	 *
	 * @return array<string>
	 */
	public static function active_scopes() {
		$meta = self::meta();
		if ( is_wp_error( $meta ) || empty( $meta['scopes'] ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $meta['scopes'] as $sc ) {
			if ( ! empty( $sc['enabled'] ) && isset( $sc['key'] ) ) {
				$out[] = (string) $sc['key'];
			}
		}
		return $out;
	}

	/**
	 * Birth query args from a profile row.
	 *
	 * @param array $row Profile row.
	 * @return array
	 */
	public static function birth_query( array $row ) {
		$q = array(
			'name' => trim( ( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) ) ?: ( $row['name'] ?? '' ),
			'date' => (string) ( $row['date'] ?? '' ),
			'time' => (string) ( $row['time'] ?? '' ),
		);
		if ( ! empty( $row['timezone'] ) ) {
			$q['timezone'] = (string) $row['timezone'];
		} elseif ( ! empty( $row['place'] ) ) {
			$q['place'] = (string) $row['place'];
		}
		return $q;
	}

	/**
	 * Fetch the chart for a profile row.
	 *
	 * @param array $row Profile row.
	 * @return array|WP_Error
	 */
	public static function chart( array $row ) {
		return self::get( '/chart', self::birth_query( $row ) );
	}

	/**
	 * Fetch one reading (quick|channels|centers|…) for a profile row.
	 *
	 * @param string $slot Reading slot.
	 * @param array  $row  Profile row.
	 * @return array|WP_Error
	 */
	public static function reading( $slot, array $row, $style = '' ) {
		$q = self::birth_query( $row );
		if ( in_array( $style, array( 'plain', 'hd' ), true ) ) {
			$q['style'] = $style;
		}
		return self::get( '/readings/' . rawurlencode( $slot ), $q );
	}

	/**
	 * City typeahead via the proxy. Returns [ { label, timezone, ... } ].
	 *
	 * @param string $query City query.
	 * @return array|WP_Error
	 */
	public static function locations( $query ) {
		$res = self::get( '/locations', array( 'query' => $query ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['results'] ) && is_array( $res['results'] ) ? $res['results'] : array();
	}

	/**
	 * Push a created profile into Reloom (deduplicated server-side).
	 *
	 * @param array $row Local profile row.
	 * @return array|WP_Error { status: created|duplicate, id, name }
	 */
	public static function sync_profile( array $row ) {
		$s = self::settings();
		if ( '' === $s['api_base'] || '' === $s['api_token'] ) {
			return new WP_Error( 'rbhdc_unconfigured', __( 'Not connected to Reloom.', 'reloom-human-design' ) );
		}
		$resp = wp_remote_post(
			$s['api_base'] . '/profiles',
			array(
				'timeout' => 30,
				'headers' => self::auth_headers( $s ),
				'body'    => array(
					'first_name' => (string) ( $row['first_name'] ?? '' ),
					'last_name'  => (string) ( $row['last_name'] ?? '' ),
					'name'       => (string) ( $row['name'] ?? '' ),
					'date'       => (string) ( $row['date'] ?? '' ),
					'time'       => (string) ( $row['time'] ?? '' ),
					'place'      => (string) ( $row['place'] ?? '' ),
					'timezone'   => (string) ( $row['timezone'] ?? '' ),
					'gender'     => (string) ( $row['gender'] ?? '' ),
					'email'      => (string) ( $row['email'] ?? '' ),
					'notes'      => (string) ( $row['notes'] ?? '' ),
				),
			)
		);
		$body = self::handle( $resp );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return $body ?: array( 'status' => 'created' );
	}

	/**
	 * Pull the account's existing profiles from Reloom (GET /profiles). Lets the
	 * site adopt profiles that already exist — above all the owner's own "self"
	 * profile created at sign-up — instead of re-adding them and duplicating.
	 *
	 * @return array<int,array>|WP_Error List of { id, name, first_name, last_name,
	 *         gender, date, time, place, timezone, relation, is_self }.
	 */
	public static function pull_profiles() {
		$s = self::settings();
		if ( '' === $s['api_base'] || '' === $s['api_token'] ) {
			return new WP_Error( 'rbhdc_unconfigured', __( 'Not connected to Reloom.', 'reloom-human-design' ) );
		}
		$resp = wp_remote_get(
			$s['api_base'] . '/profiles',
			array(
				'timeout' => 20,
				'headers' => self::auth_headers( $s ),
			)
		);
		$body = self::handle( $resp );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return ( isset( $body['profiles'] ) && is_array( $body['profiles'] ) ) ? $body['profiles'] : array();
	}

	// ── Connect wizard (OAuth-style, PKCE) ──────────────────────────────────

	/** A high-entropy PKCE code verifier (43–128 chars, URL-safe). */
	public static function pkce_verifier() {
		return self::base64url( random_bytes( 48 ) );
	}

	/** The S256 challenge for a verifier: base64url(sha256(verifier)). */
	public static function pkce_challenge( $verifier ) {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/** URL-safe, unpadded base64. */
	private static function base64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * The Reloom authorize screen URL the admin is sent to.
	 *
	 * @param string $state        Opaque CSRF value round-tripped back.
	 * @param string $challenge    PKCE S256 challenge.
	 * @param string $redirect_uri Where Reloom returns the browser (this settings page).
	 * @return string
	 */
	public static function connect_authorize_url( $state, $challenge, $redirect_uri ) {
		$s = self::settings();
		return add_query_arg(
			array(
				'site'           => home_url(),
				'name'           => get_bloginfo( 'name' ),
				'state'          => $state,
				'code_challenge' => $challenge,
				'redirect_uri'   => $redirect_uri,
			),
			$s['reloom_host'] . '/dashboard/api/connect'
		);
	}

	/**
	 * Exchange an authorization code (+ PKCE verifier) for a token, server-side.
	 *
	 * @param string $code     One-time code from the redirect.
	 * @param string $verifier The PKCE verifier we generated at start.
	 * @return array|WP_Error { token, api_base }
	 */
	public static function exchange( $code, $verifier ) {
		$s    = self::settings();
		$resp = wp_remote_post(
			$s['reloom_host'] . '/api/v1/connect/exchange',
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'code'          => (string) $code,
					'code_verifier' => (string) $verifier,
				),
			)
		);
		return self::handle( $resp );
	}
}
