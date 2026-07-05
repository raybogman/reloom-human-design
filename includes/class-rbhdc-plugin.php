<?php
/**
 * Lightweight Human Design client — admin UI + AJAX.
 *
 * Stores a local roster of people and pulls their Bodygraph chart + readings
 * from a Ray Bogman HD Suite install via the subscription proxy API. Fetched
 * content is cached locally (chart + readings) so it persists between visits;
 * a Refresh button re-pulls from the API. Only the content the subscription
 * token shares is shown.
 *
 * @package Reloom\HumanDesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages, profile storage and AJAX for the lightweight client.
 */
class RBHDC_Plugin {

	const MENU_SLUG       = 'rbhdc';
	const SETTINGS_SLUG   = 'rbhdc-settings';
	const PROFILES_OPTION = 'rbhdc_profiles';
	const NONCE           = 'rbhdc_ajax';

	/**
	 * Scope => label. 'chart' is the Bodygraph chart; the rest are readings.
	 *
	 * @return array<string,string>
	 */
	public static function scope_labels() {
		return array(
			'chart'    => __( 'Chart', 'reloom-human-design' ),
			'quick'    => __( 'Quick reading', 'reloom-human-design' ),
			'channels' => __( 'Channels', 'reloom-human-design' ),
			'centers'  => __( 'Centers', 'reloom-human-design' ),
			'full'     => __( 'Full reading', 'reloom-human-design' ),
			'profile'  => __( 'Profile', 'reloom-human-design' ),
			'gates'    => __( 'Gates', 'reloom-human-design' ),
			'health'   => __( 'Health', 'reloom-human-design' ),
			'sport'    => __( 'Sport', 'reloom-human-design' ),
			'sleep'    => __( 'Sleep', 'reloom-human-design' ),
			'career'   => __( 'Career', 'reloom-human-design' ),
			'lifefit'  => __( 'Life-fit', 'reloom-human-design' ),
		);
	}

	/** Reading slot keys in display order (everything in scope_labels except chart). */
	public static function reading_slots() {
		$keys = array_keys( self::scope_labels() );
		return array_values( array_filter( $keys, static function ( $k ) {
			return 'chart' !== $k;
		} ) );
	}

	/**
	 * Wire hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_legacy' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_connect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_rbhdc_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_rbhdc_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_rbhdc_save_profile', array( __CLASS__, 'ajax_save_profile' ) );
		add_action( 'wp_ajax_rbhdc_delete_profile', array( __CLASS__, 'ajax_delete_profile' ) );
		add_action( 'wp_ajax_rbhdc_content', array( __CLASS__, 'ajax_content' ) );
		add_action( 'wp_ajax_rbhdc_pdf', array( __CLASS__, 'ajax_pdf' ) );
		add_action( 'wp_ajax_rbhdc_export', array( __CLASS__, 'ajax_export' ) );
		add_action( 'wp_ajax_rbhdc_import', array( __CLASS__, 'ajax_import' ) );
		add_action( 'wp_ajax_rbhdc_locations', array( __CLASS__, 'ajax_locations' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Profile + content storage                                              */
	/* --------------------------------------------------------------------- */

	/** @return array<int,array> */
	public static function get_all() {
		$rows = get_option( self::PROFILES_OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $id Profile id.
	 * @return array|null
	 */
	public static function find( $id ) {
		foreach ( self::get_all() as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === (string) $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Insert/update from raw input.
	 *
	 * @param array $in Input.
	 * @return array Saved row.
	 */
	public static function save( array $in ) {
		$rows  = self::get_all();
		$id    = isset( $in['id'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $in['id'] ) : '';
		$first = sanitize_text_field( $in['first_name'] ?? '' );
		$last  = sanitize_text_field( $in['last_name'] ?? '' );
		$row   = array(
			'first_name' => $first,
			'last_name'  => $last,
			'name'       => trim( $first . ' ' . $last ),
			'gender'     => in_array( ( $in['gender'] ?? '' ), array( 'male', 'female' ), true ) ? $in['gender'] : '',
			'date'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', ( $in['date'] ?? '' ) ) ? $in['date'] : '',
			'time'       => preg_match( '/^\d{2}:\d{2}/', ( $in['time'] ?? '' ) ) ? substr( $in['time'], 0, 5 ) : '',
			'place'      => sanitize_text_field( $in['place'] ?? '' ),
			'timezone'   => sanitize_text_field( $in['timezone'] ?? '' ),
			'email'      => sanitize_email( $in['email'] ?? '' ),
			'notes'      => sanitize_textarea_field( $in['notes'] ?? '' ),
		);
		if ( '' === $row['name'] ) {
			$row['name'] = __( '(unnamed)', 'reloom-human-design' );
		}
		if ( '' !== $id ) {
			foreach ( $rows as $i => $existing ) {
				if ( isset( $existing['id'] ) && (string) $existing['id'] === $id ) {
					$row['id']      = $id;
					$row['created'] = $existing['created'] ?? time();
					// Birth change invalidates any cached content.
					if ( ( $existing['date'] ?? '' ) !== $row['date'] || ( $existing['time'] ?? '' ) !== $row['time']
						|| ( $existing['place'] ?? '' ) !== $row['place'] || ( $existing['timezone'] ?? '' ) !== $row['timezone'] ) {
						self::clear_content( $id );
					}
					$rows[ $i ] = $row;
					update_option( self::PROFILES_OPTION, $rows, false );
					return $row;
				}
			}
		}
		$row['id']      = bin2hex( random_bytes( 8 ) );
		$row['created'] = time();
		$rows[]         = $row;
		update_option( self::PROFILES_OPTION, $rows, false );
		return $row;
	}

	/**
	 * @param string $id Profile id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$rows = self::get_all();
		$out  = array();
		$hit  = false;
		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === (string) $id ) {
				$hit = true;
				continue;
			}
			$out[] = $row;
		}
		if ( $hit ) {
			update_option( self::PROFILES_OPTION, $out, false );
			self::clear_content( $id );
		}
		return $hit;
	}

	/** Stored chart for a profile: array{data:array,at:int}|null. */
	public static function get_chart( $id ) {
		$o = get_option( 'rbhdc_chart_' . $id, null );
		return ( is_array( $o ) && isset( $o['data'] ) ) ? $o : null;
	}
	public static function store_chart( $id, array $chart ) {
		update_option( 'rbhdc_chart_' . $id, array( 'data' => $chart, 'at' => time() ), false );
	}

	/** Stored readings for a profile: array<slot, array{text:string,at:int}>. */
	public static function get_readings( $id ) {
		$o = get_option( 'rbhdc_readings_' . $id, array() );
		return is_array( $o ) ? $o : array();
	}
	public static function store_reading( $id, $slot, $text ) {
		$o          = self::get_readings( $id );
		$o[ $slot ] = array( 'text' => (string) $text, 'at' => time() );
		update_option( 'rbhdc_readings_' . $id, $o, false );
	}

	/** Drop all cached content for a profile. */
	public static function clear_content( $id ) {
		delete_option( 'rbhdc_chart_' . $id );
		delete_option( 'rbhdc_readings_' . $id );
	}

	/* --------------------------------------------------------------------- */
	/* Menu + assets                                                          */
	/* --------------------------------------------------------------------- */

	public static function menu() {
		add_menu_page(
			__( 'Human Design', 'reloom-human-design' ),
			__( 'Human Design', 'reloom-human-design' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_profiles_page' ),
			'dashicons-chart-pie',
			58
		);
		add_submenu_page( self::MENU_SLUG, __( 'Profiles', 'reloom-human-design' ), __( 'Profiles', 'reloom-human-design' ), 'manage_options', self::MENU_SLUG, array( __CLASS__, 'render_profiles_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Settings', 'reloom-human-design' ), __( 'Settings', 'reloom-human-design' ), 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'render_settings_page' ) );
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'rbhdc-admin', RBHDC_PLUGIN_URL . 'assets/css/admin.css', array(), RBHDC_VERSION );
		wp_enqueue_script( 'rbhdc-admin', RBHDC_PLUGIN_URL . 'assets/js/client.js', array( 'jquery' ), RBHDC_VERSION, true );
		wp_localize_script( 'rbhdc-admin', 'rbhdc', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( self::NONCE ),
			'profilesUrl' => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
		) );
	}

	/* --------------------------------------------------------------------- */
	/* Settings page                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * One-time upgrade: the pre-2.0 plugin stored an HD Suite base
	 * (…/wp-json/rbhd/v1). That endpoint means nothing to Reloom, so clear the
	 * stale base + token once — the user then reconnects to Reloom. Guarded to
	 * the legacy rbhd path so a valid Reloom /api/v1 config is never touched.
	 */
	public static function maybe_migrate_legacy() {
		if ( get_option( 'rbhdc_migrated_v2' ) ) {
			return;
		}
		$s = get_option( RBHDC_Client::OPTION, array() );
		if ( is_array( $s ) && ! empty( $s['api_base'] ) && preg_match( '#/wp-json/rbhd#i', (string) $s['api_base'] ) ) {
			unset( $s['api_base'], $s['api_token'] );
			update_option( RBHDC_Client::OPTION, $s, false );
		}
		update_option( 'rbhdc_migrated_v2', 1, false );
	}

	/** Per-user transient holding the in-flight connect handshake (PKCE + state). */
	private static function connect_state_key() {
		return 'rbhdc_connect_' . get_current_user_id();
	}

	/**
	 * Drive the "Connect to Reloom" wizard. Runs on admin_init (before any output
	 * so redirects work). Two legs:
	 *  1. Start  (?rbhdc_connect=1): mint PKCE, stash it, bounce to Reloom.
	 *  2. Return (?code&state | ?error): verify, exchange the code for a token.
	 */
	public static function maybe_handle_connect() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SETTINGS_SLUG !== $page ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		// Leg 1 — start.
		if ( isset( $_GET['rbhdc_connect'] ) ) {
			check_admin_referer( 'rbhdc_connect' );
			$verifier  = RBHDC_Client::pkce_verifier();
			$challenge = RBHDC_Client::pkce_challenge( $verifier );
			$state     = wp_generate_password( 32, false );
			// 30 min, not 10 — a first-time user gets bounced through sign-up and
			// the onboarding wizard on Reloom before approving, which can easily
			// take longer than 10 minutes. If this transient expires before they
			// return with the code, the exchange fails on a missing verifier. The
			// handshake is still safe: per-user, single-use, overwritten each try,
			// and the code itself expires server-side in 10 min once minted.
			set_transient( self::connect_state_key(), array( 'verifier' => $verifier, 'state' => $state ), 30 * MINUTE_IN_SECONDS );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- deliberate external redirect to the user's own Reloom host to authorize the connection (nonce-checked above); wp_safe_redirect() would strip it.
			wp_redirect( RBHDC_Client::connect_authorize_url( $state, $challenge, $settings_url ) );
			exit;
		}

		// Leg 2 — return from Reloom.
		if ( isset( $_GET['error'] ) ) {
			delete_transient( self::connect_state_key() );
			wp_safe_redirect( add_query_arg( 'rbhdc_error', 'denied', $settings_url ) );
			exit;
		}
		if ( isset( $_GET['code'], $_GET['state'] ) ) {
			$saved = get_transient( self::connect_state_key() );
			$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
			delete_transient( self::connect_state_key() );

			if ( ! is_array( $saved ) || empty( $saved['state'] ) || ! hash_equals( (string) $saved['state'], $state ) ) {
				wp_safe_redirect( add_query_arg( 'rbhdc_error', 'state', $settings_url ) );
				exit;
			}
			$res = RBHDC_Client::exchange( $code, (string) $saved['verifier'] );
			if ( is_wp_error( $res ) || empty( $res['token'] ) ) {
				wp_safe_redirect( add_query_arg( 'rbhdc_error', 'exchange', $settings_url ) );
				exit;
			}
			$base = ! empty( $res['api_base'] ) ? (string) $res['api_base'] : ( RBHDC_Client::settings()['reloom_host'] . '/api/v1' );
			RBHDC_Client::save_settings( $base, (string) $res['token'] );
			delete_transient( 'rbhdc_meta_' . md5( wp_json_encode( RBHDC_Client::settings() ) ) );
			wp_safe_redirect( add_query_arg( 'rbhdc_connected', '1', $settings_url ) );
			exit;
		}
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s           = RBHDC_Client::settings();
		$connected   = RBHDC_Client::is_configured();
		$connect_url = wp_nonce_url( add_query_arg( 'rbhdc_connect', '1', admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ), 'rbhdc_connect' );
		?>
		<div class="wrap rbhd-wrap rbhdc-settings" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<h1><?php esc_html_e( 'Reloom — Settings', 'reloom-human-design' ); ?></h1>

			<?php if ( isset( $_GET['rbhdc_connected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connected to Reloom. You can now pull charts and readings.', 'reloom-human-design' ); ?></p></div>
			<?php elseif ( isset( $_GET['rbhdc_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Connection was not completed. Please try again, or connect manually below.', 'reloom-human-design' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Connect this site to your Reloom account. That’s all this does — it links WordPress to reloom.life so your charts and readings come straight from Reloom, with nothing else to set up here. Your Reloom plan decides what’s available.', 'reloom-human-design' ); ?>
			</p>

			<div class="rbhd-card" style="max-width:640px;padding:16px 22px;margin:16px 0;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Connection', 'reloom-human-design' ); ?></h2>
				<p>
					<?php if ( $connected ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#2c8a7d;"></span>
						<?php
						printf(
							/* translators: %s: API base URL. */
							esc_html__( 'Connected to %s', 'reloom-human-design' ),
							'<code>' . esc_html( $s['api_base'] ) . '</code>'
						);
						?>
					<?php else : ?>
						<span class="dashicons dashicons-warning" style="color:#b26a00;"></span>
						<?php esc_html_e( 'Not connected yet.', 'reloom-human-design' ); ?>
					<?php endif; ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary">
						<?php echo $connected ? esc_html__( 'Reconnect to Reloom', 'reloom-human-design' ) : esc_html__( 'Connect to Reloom', 'reloom-human-design' ); ?>
					</a>
					<button type="button" class="button rbhdc-test"><?php esc_html_e( 'Test connection', 'reloom-human-design' ); ?></button>
					<span class="rbhdc-settings-status description" style="margin-left:8px;" aria-live="polite"></span>
				</p>
				<p class="description"><?php esc_html_e( 'You’ll be sent to Reloom to approve this site, then returned here automatically. The token is delivered securely — it never appears in the browser URL.', 'reloom-human-design' ); ?></p>
			</div>

			<details class="rbhd-card" style="max-width:640px;padding:0 22px;margin:16px 0;" <?php echo $connected ? '' : 'open'; ?>>
				<summary style="padding:14px 0;cursor:pointer;font-weight:600;"><?php esc_html_e( 'Advanced — connect manually', 'reloom-human-design' ); ?></summary>
				<p class="description"><?php esc_html_e( 'Paste the API base URL (…/api/v1) and a token created under API access in your Reloom dashboard. You can also paste the whole “Shareable URL” into the base field — the key is split out automatically.', 'reloom-human-design' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="rbhdc-host"><?php esc_html_e( 'Reloom site', 'reloom-human-design' ); ?></label></th>
						<td><input type="text" id="rbhdc-host" class="regular-text code" value="<?php echo esc_attr( $s['reloom_host'] ); ?>" placeholder="https://reloom.life" style="width:480px;max-width:100%;" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rbhdc-base"><?php esc_html_e( 'API base URL', 'reloom-human-design' ); ?></label></th>
						<td><input type="text" id="rbhdc-base" class="regular-text code" value="<?php echo esc_attr( $s['api_base'] ); ?>" placeholder="https://reloom.life/api/v1" style="width:480px;max-width:100%;" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rbhdc-token"><?php esc_html_e( 'API token', 'reloom-human-design' ); ?></label></th>
						<td><input type="text" id="rbhdc-token" class="regular-text code" value="<?php echo esc_attr( $s['api_token'] ); ?>" placeholder="<?php esc_attr_e( 'Paste your Reloom API token…', 'reloom-human-design' ); ?>" style="width:480px;max-width:100%;" /></td>
					</tr>
				</table>
				<p>
					<button type="button" class="button button-primary rbhdc-save-settings"><?php esc_html_e( 'Save', 'reloom-human-design' ); ?></button>
					<button type="button" class="button rbhdc-test"><?php esc_html_e( 'Save & test connection', 'reloom-human-design' ); ?></button>
					<span class="rbhdc-settings-status description" style="margin-left:8px;" aria-live="polite"></span>
				</p>
			</details>

			<table class="form-table" style="max-width:640px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Data sharing', 'reloom-human-design' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="rbhdc-sync" <?php checked( ! empty( $s['sync'] ) ); ?> />
							<?php esc_html_e( 'Add profiles created here to my Reloom dashboard', 'reloom-human-design' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Enabled by default. When on, each profile you create here is synced to your Reloom account (subject to your plan’s profile limit).', 'reloom-human-design' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button rbhdc-save-settings"><?php esc_html_e( 'Save data-sharing preference', 'reloom-human-design' ); ?></button>
				<span class="rbhdc-settings-status description" style="margin-left:8px;" aria-live="polite"></span>
			</p>
			<div class="rbhdc-settings-result"></div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Profiles list                                                          */
	/* --------------------------------------------------------------------- */

	public static function render_profiles_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $id && self::find( $id ) ) {
			self::render_detail( self::find( $id ) );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$rows       = self::get_all();
		$configured = RBHDC_Client::is_configured();
		$nonce      = wp_create_nonce( self::NONCE );
		?>
		<div class="wrap rbhd-wrap rbhdc-profiles" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Human Design', 'reloom-human-design' ); ?> <span class="count">(<span class="rbhdc-count"><?php echo (int) count( $rows ); ?></span>)</span></h1>
			<button type="button" class="page-title-action rbhdc-add-toggle"><?php esc_html_e( 'Add new', 'reloom-human-design' ); ?></button>
			<button type="button" class="page-title-action rbhdc-export"><?php esc_html_e( 'Export JSON', 'reloom-human-design' ); ?></button>
			<button type="button" class="page-title-action rbhdc-import-toggle"><?php esc_html_e( 'Import JSON', 'reloom-human-design' ); ?></button>
			<hr class="wp-header-end" />

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: settings URL. */
						wp_kses_post( __( 'Not connected yet. Add the API URL + token on the <a href="%s">Settings</a> page to pull charts and readings.', 'reloom-human-design' ) ),
						esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="rbhdc-import-form rbhd-card" style="display:none;max-width:520px;padding:12px 18px;margin:12px 0;">
				<p><strong><?php esc_html_e( 'Import profiles (JSON)', 'reloom-human-design' ); ?></strong></p>
				<input type="file" class="rbhdc-import-file" accept="application/json,.json" />
				<button type="button" class="button rbhdc-import-go"><?php esc_html_e( 'Import', 'reloom-human-design' ); ?></button>
				<span class="rbhdc-import-status description"></span>
			</div>

			<div class="rbhdc-add-form rbhd-card" style="<?php echo empty( $rows ) ? '' : 'display:none;'; ?>max-width:760px;padding:14px 22px;margin:12px 0;">
				<?php self::render_birth_form(); ?>
			</div>

			<div class="rbhdc-filters" style="margin:10px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<input type="search" class="rbhdc-search" placeholder="<?php esc_attr_e( 'Search name or place…', 'reloom-human-design' ); ?>" style="min-width:240px;" />
				<select class="rbhdc-filter-gender">
					<option value=""><?php esc_html_e( 'All genders', 'reloom-human-design' ); ?></option>
					<option value="female"><?php esc_html_e( 'Female', 'reloom-human-design' ); ?></option>
					<option value="male"><?php esc_html_e( 'Male', 'reloom-human-design' ); ?></option>
				</select>
				<select class="rbhdc-sort">
					<option value="created-desc"><?php esc_html_e( 'Newest first', 'reloom-human-design' ); ?></option>
					<option value="created-asc"><?php esc_html_e( 'Oldest first', 'reloom-human-design' ); ?></option>
					<option value="name-asc"><?php esc_html_e( 'Name A→Z', 'reloom-human-design' ); ?></option>
				</select>
				<span class="rbhdc-filter-count description"></span>
			</div>

			<table class="wp-list-table widefat fixed striped rbhd-profiles-table rbhdc-table">
				<thead><tr>
					<th class="column-primary"><?php esc_html_e( 'Name', 'reloom-human-design' ); ?></th>
					<th><?php esc_html_e( 'Gender', 'reloom-human-design' ); ?></th>
					<th><?php esc_html_e( 'Birth', 'reloom-human-design' ); ?></th>
					<th><?php esc_html_e( 'Place', 'reloom-human-design' ); ?></th>
					<th><?php esc_html_e( 'Status', 'reloom-human-design' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'reloom-human-design' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr class="rbhdc-empty-row"><td colspan="6"><?php esc_html_e( 'No people yet. Click “Add new”.', 'reloom-human-design' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : self::render_row( $row ); endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * One profile row with a Status column (Chart / N readings) like the Suite.
	 *
	 * @param array $row Profile row.
	 */
	private static function render_row( array $row ) {
		$url        = add_query_arg( array( 'page' => self::MENU_SLUG, 'id' => $row['id'] ), admin_url( 'admin.php' ) );
		$has_chart  = (bool) self::get_chart( $row['id'] );
		$n_readings = count( self::get_readings( $row['id'] ) );
		$birth      = trim( ( $row['date'] ?? '' ) . ' ' . ( $row['time'] ?? '' ) );
		?>
		<tr data-id="<?php echo esc_attr( $row['id'] ); ?>"
			data-name="<?php echo esc_attr( strtolower( $row['name'] . ' ' . ( $row['place'] ?? '' ) ) ); ?>"
			data-gender="<?php echo esc_attr( $row['gender'] ?? '' ); ?>"
			data-created="<?php echo esc_attr( $row['created'] ?? 0 ); ?>">
			<td class="column-primary"><strong><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $row['name'] ); ?></a></strong></td>
			<td><?php echo esc_html( $row['gender'] ? $row['gender'] : '—' ); ?></td>
			<td><?php echo esc_html( $birth ?: '—' ); ?></td>
			<td><?php echo esc_html( $row['place'] ? $row['place'] : ( $row['timezone'] ?? '—' ) ); ?></td>
			<td class="rbhdc-status">
				<span class="rbhd-status-pill <?php echo $has_chart ? 'is-on' : ''; ?>"><?php esc_html_e( 'Chart', 'reloom-human-design' ); ?></span>
				<span class="rbhd-status-pill <?php echo $n_readings ? 'is-on' : ''; ?>"><?php echo (int) $n_readings; ?> <?php esc_html_e( 'readings', 'reloom-human-design' ); ?></span>
			</td>
			<td>
				<a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open', 'reloom-human-design' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', '1', $url ) ); ?>"><?php esc_html_e( 'Edit', 'reloom-human-design' ); ?></a>
				<button type="button" class="button button-small rbhdc-delete" data-id="<?php echo esc_attr( $row['id'] ); ?>" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'reloom-human-design' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Birth Data form (mirrors the HD Suite chart form).
	 *
	 * @param array|null $row Existing row.
	 */
	private static function render_birth_form( $row = null, $context = 'add' ) {
		$g = $row['gender'] ?? '';
		?>
		<h2 style="margin-top:0;"><?php echo 'edit' === $context ? esc_html__( 'Edit profile', 'reloom-human-design' ) : esc_html__( 'Birth Data', 'reloom-human-design' ); ?></h2>
		<form class="rbhdc-form" data-id="<?php echo esc_attr( $row['id'] ?? '' ); ?>" data-context="<?php echo esc_attr( $context ); ?>">
			<div class="rbhdc-row2">
				<p><label><?php esc_html_e( 'First name', 'reloom-human-design' ); ?><br>
					<input type="text" name="first_name" value="<?php echo esc_attr( $row['first_name'] ?? '' ); ?>" class="regular-text" /></label></p>
				<p><label><?php esc_html_e( 'Family name (surname)', 'reloom-human-design' ); ?><br>
					<input type="text" name="last_name" value="<?php echo esc_attr( $row['last_name'] ?? '' ); ?>" class="regular-text" /></label></p>
			</div>
			<p><label><?php esc_html_e( 'Gender', 'reloom-human-design' ); ?><br>
				<select name="gender">
					<option value="" <?php selected( $g, '' ); ?>><?php esc_html_e( '— unspecified —', 'reloom-human-design' ); ?></option>
					<option value="female" <?php selected( $g, 'female' ); ?>><?php esc_html_e( 'Female', 'reloom-human-design' ); ?></option>
					<option value="male" <?php selected( $g, 'male' ); ?>><?php esc_html_e( 'Male', 'reloom-human-design' ); ?></option>
				</select></label></p>
			<p><label><?php esc_html_e( 'Date of birth', 'reloom-human-design' ); ?><br>
				<input type="date" name="date" value="<?php echo esc_attr( $row['date'] ?? '' ); ?>" /></label></p>
			<p><label><?php esc_html_e( 'Time of birth', 'reloom-human-design' ); ?><br>
				<input type="time" name="time" value="<?php echo esc_attr( $row['time'] ?? '' ); ?>" /></label></p>
			<p><label><?php esc_html_e( 'Place of birth', 'reloom-human-design' ); ?><br>
				<span class="rbhdc-place-wrap rbhd-place" style="position:relative;display:block;width:100%;max-width:540px;">
					<input type="text" name="place" value="<?php echo esc_attr( $row['place'] ?? '' ); ?>" class="rbhdc-place" autocomplete="off" placeholder="<?php esc_attr_e( 'Start typing a city…', 'reloom-human-design' ); ?>" style="width:100%;" />
					<ul class="rbhdc-place-results" hidden></ul>
				</span>
				<span class="rbhdc-place-status" style="font-weight:600;display:inline-block;margin-top:4px;"></span></label></p>
			<p><label><?php esc_html_e( 'Timezone (optional, IANA)', 'reloom-human-design' ); ?><br>
				<input type="text" name="timezone" value="<?php echo esc_attr( $row['timezone'] ?? '' ); ?>" class="regular-text rbhdc-timezone" placeholder="Europe/Amsterdam" /></label>
				<span class="description"><?php esc_html_e( 'Filled in when you pick a city. Fallback if the city can’t be resolved.', 'reloom-human-design' ); ?></span></p>
			<p><label><?php esc_html_e( 'Email (optional)', 'reloom-human-design' ); ?><br>
				<input type="email" name="email" value="<?php echo esc_attr( $row['email'] ?? '' ); ?>" class="regular-text" /></label></p>
			<p><label><?php esc_html_e( 'Comment / notes (optional)', 'reloom-human-design' ); ?><br>
				<textarea name="notes" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Private notes about this person — only visible in the admin.', 'reloom-human-design' ); ?>"><?php echo esc_textarea( $row['notes'] ?? '' ); ?></textarea></label></p>
			<p>
				<?php if ( 'edit' === $context ) : ?>
					<button type="button" class="button button-primary rbhdc-save"><?php esc_html_e( 'Save changes', 'reloom-human-design' ); ?></button>
					<button type="button" class="button rbhdc-edit-cancel"><?php esc_html_e( 'Cancel', 'reloom-human-design' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-primary rbhdc-generate"><?php esc_html_e( 'Generate Chart', 'reloom-human-design' ); ?></button>
					<button type="button" class="button rbhdc-save"><?php esc_html_e( 'Save profile', 'reloom-human-design' ); ?></button>
				<?php endif; ?>
				<span class="rbhdc-form-status description" style="margin-left:8px;" aria-live="polite"></span>
			</p>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Profile detail (suite-style tabs + stored content + Refresh)           */
	/* --------------------------------------------------------------------- */

	private static function render_detail( array $row ) {
		$nonce  = wp_create_nonce( self::NONCE );
		$scopes = RBHDC_Client::active_scopes();
		$meta   = RBHDC_Client::meta();
		$err    = is_wp_error( $meta ) ? $meta->get_error_message() : '';
		$labels = self::scope_labels();
		$order  = array_keys( $labels ); // chart first, then readings.
		$tabs   = array();
		foreach ( $order as $sc ) {
			if ( in_array( $sc, $scopes, true ) ) {
				$tabs[ $sc ] = $labels[ $sc ];
			}
		}
		$readings = self::get_readings( $row['id'] );
		$chart    = self::get_chart( $row['id'] );
		$back     = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		?>
		<?php $edit_open = ! empty( $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="wrap rbhd-wrap rbhdc-detail" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-id="<?php echo esc_attr( $row['id'] ); ?>">
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'All profiles', 'reloom-human-design' ); ?></a></p>
			<h1 style="display:inline-block;margin-right:10px;"><?php echo esc_html( $row['name'] ); ?></h1>
			<button type="button" class="page-title-action rbhdc-edit-toggle"><?php esc_html_e( 'Edit profile', 'reloom-human-design' ); ?></button>
			<?php if ( $chart || ! empty( $readings ) ) : ?>
				<button type="button" class="page-title-action rbhdc-pdf">
					<span class="dashicons dashicons-pdf" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Download PDF', 'reloom-human-design' ); ?>
				</button>
			<?php endif; ?>
			<p class="description">
				<?php echo esc_html( trim( ( $row['date'] ?? '' ) . ' ' . ( $row['time'] ?? '' ) ) ); ?>
				<?php echo $row['place'] ? ' · ' . esc_html( $row['place'] ) : ''; ?>
				<?php echo $row['timezone'] ? ' · ' . esc_html( $row['timezone'] ) : ''; ?>
			</p>

			<div class="rbhdc-edit-form rbhd-card" style="<?php echo $edit_open ? '' : 'display:none;'; ?>max-width:760px;padding:14px 22px;margin:12px 0;">
				<?php self::render_birth_form( $row, 'edit' ); ?>
			</div>

			<?php if ( '' !== $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Check Settings', 'reloom-human-design' ); ?></a>
				</p></div>
			<?php elseif ( empty( $tabs ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Your Reloom plan includes no content for this connection. Upgrade your plan in the Reloom dashboard to unlock readings.', 'reloom-human-design' ); ?></p></div>
			<?php else : ?>
				<?php
				$chart_enabled  = in_array( 'chart', $scopes, true );
				$reading_scopes = array_values( array_intersect( self::reading_slots(), $scopes ) );
				?>
				<div class="rbhdc-split">
					<div class="rbhdc-split-col rbhdc-split-left">
						<?php if ( $chart_enabled ) : ?>
							<nav class="rbhd-tabs nav-tab-wrapper" role="tablist">
								<span class="rbhd-tab nav-tab nav-tab-active rbhdc-static-tab" role="tab" aria-selected="true">
									<span class="rbhd-tab-label"><?php echo esc_html( $labels['chart'] ); ?></span>
									<?php if ( $chart ) : ?><span class="rbhd-tab-dot" aria-hidden="true"></span><?php endif; ?>
								</span>
							</nav>
							<div class="rbhd-tab-panels">
								<?php
								$chart_html = $chart ? RBHDC_Chart_Renderer::render( $chart['data'] ) : '';
								self::render_content_panel( 'chart', $labels['chart'], $chart_html, $chart['at'] ?? 0, true );
								?>
							</div>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'The Bodygraph chart isn’t included in your plan.', 'reloom-human-design' ); ?></p>
						<?php endif; ?>
					</div>
					<div class="rbhdc-split-col rbhdc-split-right">
						<?php if ( $reading_scopes ) : ?>
							<nav class="rbhd-tabs nav-tab-wrapper" role="tablist">
								<?php $first = true; ?>
								<?php foreach ( $reading_scopes as $sc ) : ?>
									<a href="#" class="rbhd-tab nav-tab <?php echo $first ? 'nav-tab-active' : ''; ?>" data-scope="<?php echo esc_attr( $sc ); ?>" role="tab">
										<span class="rbhd-tab-label"><?php echo esc_html( $labels[ $sc ] ); ?></span>
										<?php if ( ! empty( $readings[ $sc ] ) ) : ?><span class="rbhd-tab-dot" aria-hidden="true"></span><?php endif; ?>
									</a>
									<?php $first = false; ?>
								<?php endforeach; ?>
							</nav>
							<div class="rbhd-tab-panels">
								<?php $first = true; ?>
								<?php foreach ( $reading_scopes as $sc ) : ?>
									<?php
									$entry      = $readings[ $sc ] ?? null;
									$reading_html = ( $entry && ! empty( $entry['text'] ) ) ? '<div class="rbhd-reading-body">' . RBHDC_Chart_Renderer::markdown_to_html( $entry['text'] ) . '</div>' : '';
									self::render_content_panel( $sc, $labels[ $sc ], $reading_html, $entry['at'] ?? 0, $first );
									$first = false;
									?>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'No readings are included in your plan yet.', 'reloom-human-design' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One content panel (chart or reading). Auto-loads when empty.
	 *
	 * @param string $scope       Scope key.
	 * @param string $label       Heading.
	 * @param string $stored_html Cached HTML ('' if not yet pulled).
	 * @param int    $stored_at   Cache timestamp.
	 * @param bool   $active      Visible by default?
	 */
	private static function render_content_panel( $scope, $label, $stored_html, $stored_at, $active ) {
		?>
		<section class="rbhd-tab-panel <?php echo $active ? 'is-active' : ''; ?>" data-scope="<?php echo esc_attr( $scope ); ?>" role="tabpanel" aria-label="<?php echo esc_attr( $label ); ?>" <?php echo $active ? '' : 'hidden'; ?>>
			<div class="rbhdc-panel-toolbar">
				<?php if ( 'chart' === $scope ) : ?>
					<span class="rbhdc-zoom" role="group" aria-label="<?php esc_attr_e( 'Zoom chart', 'reloom-human-design' ); ?>">
						<button type="button" class="button button-small rbhdc-zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'reloom-human-design' ); ?>">&minus;</button>
						<span class="rbhdc-zoom-val">55%</span>
						<button type="button" class="button button-small rbhdc-zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'reloom-human-design' ); ?>">+</button>
					</span>
				<?php endif; ?>
				<span class="rbhdc-stored description">
					<?php
					if ( $stored_at ) {
						printf( /* translators: %s: time diff. */ esc_html__( 'Stored %s ago', 'reloom-human-design' ), esc_html( human_time_diff( (int) $stored_at, time() ) ) );
					}
					?>
				</span>
				<button type="button" class="button button-small rbhdc-refresh">
					<span class="dashicons dashicons-update" style="vertical-align:text-bottom;"></span>
					<?php echo $stored_html ? esc_html__( 'Refresh', 'reloom-human-design' ) : esc_html__( 'Generate', 'reloom-human-design' ); ?>
				</button>
			</div>
			<div class="rbhdc-content" data-loaded="<?php echo $stored_html ? '1' : '0'; ?>">
				<?php
				if ( $stored_html ) {
					echo $stored_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by renderer/kses.
				} else {
					echo '<p class="rbhdc-loading"><span class="spinner is-active" style="float:none;"></span> ' . esc_html__( 'Loading…', 'reloom-human-design' ) . '</p>';
				}
				?>
			</div>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* AJAX                                                                    */
	/* --------------------------------------------------------------------- */

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reloom-human-design' ) ), 403 );
		}
	}

	public static function ajax_save_settings() {
		self::guard(); // Nonce + capability checked in guard(); phpcs cannot see through the helper.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$sync = isset( $_POST['sync'] ) ? in_array( sanitize_text_field( wp_unslash( $_POST['sync'] ) ), array( '1', 'true' ), true ) : null;
		$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : null;
		RBHDC_Client::save_settings(
			isset( $_POST['base'] ) ? sanitize_text_field( wp_unslash( $_POST['base'] ) ) : '',
			isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '',
			$sync,
			$host
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		delete_transient( 'rbhdc_meta_' . md5( wp_json_encode( RBHDC_Client::settings() ) ) );
		wp_send_json_success( array( 'message' => __( 'Saved.', 'reloom-human-design' ) ) );
	}

	public static function ajax_test() {
		self::guard(); // Nonce + capability checked in guard().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['base'], $_POST['token'] ) ) {
			$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : null;
			RBHDC_Client::save_settings( sanitize_text_field( wp_unslash( $_POST['base'] ) ), sanitize_text_field( wp_unslash( $_POST['token'] ) ), null, $host );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$meta = RBHDC_Client::meta( true );
		if ( is_wp_error( $meta ) ) {
			wp_send_json_error( array( 'message' => $meta->get_error_message() ) );
		}
		wp_send_json_success( $meta );
	}

	public static function ajax_save_profile() {
		self::guard(); // Nonce + capability checked in guard().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized in self::save().
		$in = isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : array();

		// Verify the place of birth against the Bodygraph location database (via
		// the proxy) before saving. A typed place that does not resolve is
		// rejected unless a valid IANA timezone was supplied as a fallback.
		$place = trim( (string) ( $in['place'] ?? '' ) );
		$tz    = trim( (string) ( $in['timezone'] ?? '' ) );
		if ( '' !== $place ) {
			$matches = RBHDC_Client::locations( $place );
			if ( is_array( $matches ) && ! empty( $matches ) ) {
				// Verified — snap to the canonical label + resolved timezone.
				$best          = $matches[0];
				$in['place']   = (string) $best['label'];
				$in['timezone'] = (string) $best['timezone'];
			} else {
				$tz_ok = '' !== $tz && in_array( $tz, timezone_identifiers_list(), true );
				if ( is_wp_error( $matches ) ) {
					if ( ! $tz_ok ) {
						wp_send_json_error( array( 'message' => __( 'Could not verify the place right now (connection issue). Try again, or set a valid IANA timezone as a fallback.', 'reloom-human-design' ) ) );
					}
				} elseif ( ! $tz_ok ) {
					wp_send_json_error( array( 'message' => __( 'Place of birth could not be verified against the location database. Pick a city from the suggestions.', 'reloom-human-design' ) ) );
				}
			}
		}

		$row = self::save( $in );

		// Auto-sync the profile back to the Suite (deduplicated there), when the
		// user has consented and the connection is configured.
		$sync = null;
		if ( RBHDC_Client::is_sync_on() && RBHDC_Client::is_configured() ) {
			$res = RBHDC_Client::sync_profile( $row );
			if ( is_wp_error( $res ) ) {
				$sync = array( 'ok' => false, 'message' => $res->get_error_message() );
			} else {
				$sync = array( 'ok' => true, 'status' => $res['status'] ?? 'created' );
				self::mark_synced( $row['id'], $res['status'] ?? 'created' );
			}
		}

		wp_send_json_success( array(
			'id'   => $row['id'],
			'url'  => add_query_arg( array( 'page' => self::MENU_SLUG, 'id' => $row['id'] ), admin_url( 'admin.php' ) ),
			'sync' => $sync,
		) );
	}

	/** Record the sync result on a local profile row. */
	private static function mark_synced( $id, $status ) {
		$rows = self::get_all();
		foreach ( $rows as $i => $r ) {
			if ( isset( $r['id'] ) && (string) $r['id'] === (string) $id ) {
				$rows[ $i ]['synced']    = (string) $status;
				$rows[ $i ]['synced_at'] = time();
				update_option( self::PROFILES_OPTION, $rows, false );
				return;
			}
		}
	}

	public static function ajax_delete_profile() {
		self::guard(); // Nonce + capability checked in guard().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::delete( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '' );
		wp_send_json_success();
	}

	/**
	 * Fetch one tab's content via the proxy AND store it locally. Returns HTML
	 * plus a "stored ... ago" label so the panel updates after a Refresh.
	 */
	public static function ajax_content() {
		self::guard(); // Nonce + capability checked in guard().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$row   = self::find( $id );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Profile not found.', 'reloom-human-design' ) ), 404 );
		}
		if ( 'chart' === $scope ) {
			$res = RBHDC_Client::chart( $row );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			$chart = isset( $res['chart'] ) && is_array( $res['chart'] ) ? $res['chart'] : $res;
			self::store_chart( $id, $chart );
			wp_send_json_success( array( 'html' => RBHDC_Chart_Renderer::render( $chart ), 'stored' => __( 'just now', 'reloom-human-design' ) ) );
		}
		$res = RBHDC_Client::reading( $scope, $row );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$md = isset( $res['markdown'] ) ? (string) $res['markdown'] : '';
		self::store_reading( $id, $scope, $md );
		wp_send_json_success( array(
			'html'   => '<div class="rbhd-reading-body">' . RBHDC_Chart_Renderer::markdown_to_html( $md ) . '</div>',
			'stored' => __( 'just now', 'reloom-human-design' ),
		) );
	}

	/**
	 * Generate a real PDF for a profile with the bundled Dompdf library and
	 * stream it to the browser as a download. No print dialog, no browser
	 * header/footer chrome, real page margins on every page. Works out of the
	 * box on any PHP 7.4+ / WordPress host — Dompdf ships inside the plugin
	 * (vendor/) and is loaded only here, so normal pages pay no cost.
	 */
	public static function ajax_pdf() {
		// Triggered via a download link, so accept GET and verify the nonce
		// from the request (not just POST).
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload and try again.', 'reloom-human-design' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'reloom-human-design' ), 403 );
		}
		$id  = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';
		$row = self::find( $id );
		if ( ! $row ) {
			wp_die( esc_html__( 'Profile not found.', 'reloom-human-design' ), 404 );
		}

		// The browser rasterises the on-screen bodygraph SVG to a PNG and posts
		// it here (PDF libraries render SVG unreliably; a PNG always embeds). We
		// validate it is a genuine PNG data URI before trusting it.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated + re-encoded by sanitize_png_data_uri() (PNG magic bytes, size cap).
		$chart_png = self::sanitize_png_data_uri( isset( $_POST['chart_png'] ) ? wp_unslash( $_POST['chart_png'] ) : '' );

		$autoload = RBHDC_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die( esc_html__( 'The PDF engine is missing from this plugin build.', 'reloom-human-design' ) );
		}

		// Render with the chart; if it ever defeats the renderer, retry without
		// it so the readings still export.
		$pdf = self::render_pdf( $row, true, $chart_png );
		if ( null === $pdf ) {
			$pdf = self::render_pdf( $row, false, '' );
		}
		if ( null === $pdf ) {
			wp_die( esc_html__( 'Sorry, the PDF could not be generated.', 'reloom-human-design' ) );
		}

		$base     = $row['name'] ? $row['name'] : 'human-design';
		$filename = sanitize_file_name( $base . ' - Human Design.pdf' );

		// Discard any output (stray notices/warnings, WP buffers) so the binary
		// PDF is the only thing on the wire — otherwise the download corrupts.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF payload.
		exit;
	}

	/**
	 * Validate a base64 PNG data URI (the browser-rasterised chart). Returns a
	 * clean re-encoded data URI, or '' if it is not a genuine PNG.
	 *
	 * @param mixed $raw Posted value.
	 * @return string
	 */
	private static function sanitize_png_data_uri( $raw ) {
		if ( ! is_string( $raw ) || 0 !== strpos( $raw, 'data:image/png;base64,' ) ) {
			return '';
		}
		$b64 = preg_replace( '/\s+/', '', substr( $raw, strlen( 'data:image/png;base64,' ) ) );
		if ( '' === $b64 || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $b64 ) ) {
			return '';
		}
		$bin = base64_decode( $b64, true );
		// Cap at ~6MB and require the PNG magic signature.
		if ( false === $bin || strlen( $bin ) > 6 * MB_IN_BYTES || 0 !== strncmp( $bin, "\x89PNG\r\n\x1a\n", 8 ) ) {
			return '';
		}
		return 'data:image/png;base64,' . base64_encode( $bin );
	}

	/**
	 * Render a profile to a PDF byte string via Dompdf, or null on failure.
	 *
	 * @param array  $row           Profile row.
	 * @param bool   $include_chart Embed the bodygraph?
	 * @param string $chart_png     Optional pre-rasterised chart PNG data URI.
	 * @return string|null
	 */
	private static function render_pdf( array $row, $include_chart, $chart_png = '' ) {
		$ob_level = ob_get_level();
		try {
			$html = self::build_pdf_document( $row, $include_chart, $chart_png );

			// Dompdf needs a writable cache dir. The plugin folder may be
			// read-only on shared hosts, so use the (always-writable) uploads
			// dir, falling back to the system temp dir.
			$upload = wp_upload_dir();
			$tmp    = ( ! empty( $upload['basedir'] ) ) ? trailingslashit( $upload['basedir'] ) . 'rbhdc-pdf' : '';
			if ( '' === $tmp || ! wp_mkdir_p( $tmp ) ) {
				$tmp = sys_get_temp_dir();
			}

			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'defaultFont', 'DejaVu Serif' );
			$options->set( 'tempDir', $tmp );
			$options->set( 'fontCache', $tmp );
			$options->set( 'chroot', array( RBHDC_PLUGIN_DIR, (string) ( $upload['basedir'] ?? $tmp ) ) );

			// Buffer the render so any deprecation/notice output emitted by the
			// PDF library (some versions on newer PHP) is captured and discarded
			// rather than leaking into the binary download.
			ob_start();
			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();
			$pdf = (string) $dompdf->output();
			ob_end_clean();
			return $pdf;
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			error_log( '[rbhdc] PDF render failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}
	}

	/**
	 * Fetch the branding logo and return it as a base64 data URI Dompdf can
	 * embed without a network call at render time. Only raster types Dompdf
	 * renders reliably are allowed; anything else returns '' (text fallback).
	 *
	 * @param string $url Logo URL.
	 * @return string data: URI or ''.
	 */
	private static function logo_data_uri( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$ck     = 'rbhdc_logo_' . md5( $url );
		$cached = get_transient( $ck );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
		$out = '';
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$body = wp_remote_retrieve_body( $res );
			$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
			if ( '' === $type ) {
				$ext  = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$map  = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif' );
				$type = isset( $map[ $ext ] ) ? $map[ $ext ] : '';
			}
			$type = trim( explode( ';', $type )[0] );
			if ( '' !== $body && in_array( $type, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
				$out = 'data:' . $type . ';base64,' . base64_encode( $body );
			}
		}
		set_transient( $ck, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * Assemble the PDF source document for a profile (rendered by Dompdf):
	 * cover (name, key facts, bodygraph) → readings → "Powered by" footer.
	 *
	 * @param array  $row           Profile row.
	 * @param bool   $include_chart Embed the bodygraph?
	 * @param string $chart_png     Optional pre-rasterised chart PNG data URI.
	 * @return string Full HTML document.
	 */
	private static function build_pdf_document( array $row, $include_chart = true, $chart_png = '' ) {
		$labels   = self::scope_labels();
		$chart    = self::get_chart( $row['id'] );
		$readings = self::get_readings( $row['id'] );

		// Branding from the proxy (HD Suite → Settings → Branding + site URL).
		// Fetch fresh so a logo/URL change on HD Suite shows in the very next
		// export rather than waiting for the short /meta cache to expire.
		$meta      = RBHDC_Client::meta( true );
		$logo_url  = ( is_array( $meta ) && ! empty( $meta['logo_url'] ) ) ? (string) $meta['logo_url'] : '';
		$brand_url = ( is_array( $meta ) && ! empty( $meta['brand_url'] ) ) ? (string) $meta['brand_url'] : '';
		$logo_data = '' !== $logo_url ? self::logo_data_uri( $logo_url ) : '';
		$brand_host = '';
		if ( '' !== $brand_url ) {
			$brand_host = (string) wp_parse_url( $brand_url, PHP_URL_HOST );
			$brand_host = preg_replace( '/^www\./', '', $brand_host );
		}

		$birth_bits = array_filter(
			array(
				trim( (string) ( $row['date'] ?? '' ) . ' ' . (string) ( $row['time'] ?? '' ) ),
				(string) ( $row['place'] ?? '' ),
				(string) ( $row['timezone'] ?? '' ),
			),
			'strlen'
		);

		$data = ( $chart && ! empty( $chart['data'] ) && is_array( $chart['data'] ) ) ? $chart['data'] : array();

		// Bodygraph: prefer the browser-rasterised PNG (renders reliably in the
		// PDF engine); otherwise fall back to the raw SVG from the chart payload.
		$svg = '';
		if ( $include_chart && '' === $chart_png ) {
			foreach ( array( 'SVG', 'svg', 'chart', 'Chart' ) as $k ) {
				if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) && '' !== trim( $data[ $k ] ) ) {
					$svg = (string) $data[ $k ];
					break;
				}
			}
		}
		$has_chart = $include_chart && ( '' !== $chart_png || '' !== $svg );

		// Compact "key facts" band for the cover.
		$fact_keys = array(
			'Type'           => __( 'Type', 'reloom-human-design' ),
			'Strategy'       => __( 'Strategy', 'reloom-human-design' ),
			'InnerAuthority' => __( 'Authority', 'reloom-human-design' ),
			'Profile'        => __( 'Profile', 'reloom-human-design' ),
			'Definition'     => __( 'Definition', 'reloom-human-design' ),
		);
		$facts = array();
		foreach ( $fact_keys as $k => $label ) {
			$v = $data ? RBHDC_Chart_Renderer::get_property( $data, $k ) : '';
			if ( '' !== $v ) {
				$facts[ $label ] = $v;
			}
		}

		// Cover page: name → birth line → key facts → bodygraph.
		$cover  = '<div class="cover">';
		$cover .= '<h1>' . esc_html( (string) $row['name'] ) . '</h1>';
		if ( $birth_bits ) {
			$cover .= '<p class="birth">' . esc_html( implode( '   ·   ', $birth_bits ) ) . '</p>';
		}
		if ( $facts ) {
			$cover .= '<div class="facts">';
			foreach ( $facts as $label => $v ) {
				$cover .= '<div class="fact"><span class="k">' . esc_html( $label ) . '</span><span class="v">' . esc_html( $v ) . '</span></div>';
			}
			$cover .= '</div>';
		}
		if ( '' !== $chart_png ) {
			$cover .= '<div class="chart"><img src="' . esc_attr( $chart_png ) . '" alt="" /></div>';
		} elseif ( '' !== $svg ) {
			$cover .= '<div class="chart">' . wp_kses( $svg, RBHDC_Chart_Renderer::allowed_svg_tags_public() ) . '</div>';
		}
		$cover .= '</div>';

		// Readings — each on a fresh page after the cover.
		$reading_html = '';
		foreach ( self::reading_slots() as $slot ) {
			$entry = isset( $readings[ $slot ] ) ? $readings[ $slot ] : null;
			if ( ! $entry || empty( $entry['text'] ) ) {
				continue;
			}
			$reading_html .= '<div class="reading">'
				. '<h2>' . esc_html( $labels[ $slot ] ) . '</h2>'
				. '<div class="reading-body">' . RBHDC_Chart_Renderer::markdown_to_html( (string) $entry['text'] ) . '</div>'
				. '</div>';
		}

		$body = $cover;
		if ( '' !== $reading_html ) {
			$body .= '<div class="readings' . ( $has_chart ? ' page-break' : '' ) . '">' . $reading_html . '</div>';
		}

		// Footer: "Powered by" + logo (or brand host as text), with the URL.
		$footer  = '<div class="doc-foot">';
		$footer .= '<div class="pb-line"><span class="pb">' . esc_html__( 'Powered by', 'reloom-human-design' ) . '</span>';
		if ( '' !== $logo_data ) {
			$footer .= ' <img class="pb-logo" src="' . esc_attr( $logo_data ) . '" alt="" />';
		} elseif ( '' !== $brand_host ) {
			$footer .= ' <span class="pb-name">' . esc_html( $brand_host ) . '</span>';
		}
		$footer .= '</div>';
		if ( '' !== $brand_url && '' !== $brand_host && '' !== $logo_data ) {
			$footer .= '<div class="pb-url"><a href="' . esc_url( $brand_url ) . '">' . esc_html( $brand_host ) . '</a></div>';
		}
		$footer .= '</div>';

		$title = sprintf(
			/* translators: %s: person name. */
			__( 'Human Design — %s', 'reloom-human-design' ),
			(string) $row['name']
		);

		$html  = '<!doctype html><html><head>';
		$html .= '<meta charset="utf-8" />';
		$html .= '<title>' . esc_html( $title ) . '</title>';
		$html .= '<style>' . self::pdf_css() . '</style>';
		$html .= '</head><body>';
		$html .= $body . $footer;
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Stylesheet for the Dompdf-rendered PDF. Kept to CSS that Dompdf 2.x
	 * supports — real @page margins (so every page, incl. continuation pages,
	 * has top/bottom/left/right room), no flexbox, no CSS custom properties,
	 * and the bundled DejaVu fonts (full Unicode for "·", em dashes, accents).
	 *
	 * @return string
	 */
	private static function pdf_css() {
		return '
			@page { margin: 20mm 18mm 22mm 18mm; }
			body { margin: 0; padding: 0; color: #23232a; font-family: "DejaVu Serif", serif; font-size: 11.5px; line-height: 1.7; }

			/* ---- Cover ---- */
			.cover { text-align: center; }
			.cover h1 { font-family: "DejaVu Sans", sans-serif; font-size: 26px; font-weight: bold; color: #16161a; margin: 0 0 6px; }
			.cover .birth { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #7a7a82; margin: 0 0 18px; }
			.facts { margin: 0 auto 9mm; text-align: center; }
			.fact { display: inline-block; vertical-align: top; border: 1px solid #e7e7ea; border-radius: 8px; padding: 7px 14px; margin: 4px; text-align: center; }
			.fact .k { display: block; font-family: "DejaVu Sans", sans-serif; font-size: 8px; letter-spacing: 0.08em; text-transform: uppercase; color: #7a7a82; }
			.fact .v { display: block; font-family: "DejaVu Sans", sans-serif; font-size: 12px; font-weight: bold; color: #16161a; margin-top: 3px; }
			.chart { text-align: center; margin: 0 auto; }
			.chart svg { width: 95mm; height: auto; }
			.chart img { width: 95mm; height: auto; }

			/* ---- Readings ---- */
			.readings.page-break { page-break-before: always; }
			.reading { page-break-before: always; }
			.reading:first-child { page-break-before: avoid; }
			.reading h2 { font-family: "DejaVu Sans", sans-serif; font-size: 19px; font-weight: bold; color: #16161a; margin: 0 0 11px; padding-bottom: 6px; border-bottom: 2px solid #2f6b4f; }
			.reading-body h1, .reading-body h2, .reading-body h3, .reading-body h4 { font-family: "DejaVu Sans", sans-serif; color: #2f6b4f; line-height: 1.3; margin: 16px 0 5px; }
			.reading-body h1 { font-size: 15px; }
			.reading-body h2 { font-size: 14px; }
			.reading-body h3, .reading-body h4 { font-size: 12.5px; }
			.reading-body p { margin: 0 0 10px; }
			.reading-body ul, .reading-body ol { margin: 0 0 10px 18px; padding: 0; }
			.reading-body li { margin: 0 0 5px; }
			.reading-body strong { color: #16161a; }
			.reading-body hr { border: 0; border-top: 1px solid #e7e7ea; margin: 13px 0; }
			.reading-body blockquote { margin: 0 0 10px; padding: 2px 0 2px 12px; border-left: 3px solid #e7e7ea; color: #4a4a52; }

			/* ---- Footer ---- */
			.doc-foot { margin-top: 14mm; padding-top: 7mm; border-top: 1px solid #e7e7ea; text-align: center; }
			.doc-foot .pb { font-family: "DejaVu Sans", sans-serif; font-size: 14px; color: #555; vertical-align: middle; }
			.doc-foot .pb-logo { height: 46px; vertical-align: middle; margin-left: 10px; }
			.doc-foot .pb-name { font-family: "DejaVu Sans", sans-serif; font-size: 15px; font-weight: bold; color: #444; vertical-align: middle; margin-left: 8px; }
			.doc-foot .pb-url { margin-top: 7px; }
			.doc-foot .pb-url a { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #2f6b4f; text-decoration: none; }
		';
	}

	/**
	 * City typeahead — proxies to the Suite's /locations endpoint.
	 */
	public static function ajax_locations() {
		self::guard(); // Nonce + capability checked in guard().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$res   = RBHDC_Client::locations( $query );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'results' => $res ) );
	}

	public static function ajax_export() {
		self::guard();
		wp_send_json_success( array(
			'filename' => 'rbhdc-profiles-' . gmdate( 'Ymd' ) . '.json',
			'json'     => wp_json_encode( array( 'profiles' => self::get_all() ), JSON_PRETTY_PRINT ),
		) );
	}

	public static function ajax_import() {
		self::guard(); // Nonce + capability checked in guard().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by json_decode below; each profile field is sanitized in self::save().
		$raw  = isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '';
		$data = json_decode( $raw, true );
		$list = is_array( $data ) && isset( $data['profiles'] ) ? $data['profiles'] : ( is_array( $data ) ? $data : null );
		if ( ! is_array( $list ) ) {
			wp_send_json_error( array( 'message' => __( 'Not a valid profiles JSON file.', 'reloom-human-design' ) ) );
		}
		$added = 0;
		foreach ( $list as $p ) {
			if ( is_array( $p ) ) {
				self::save( $p );
				$added++;
			}
		}
		wp_send_json_success( array( 'added' => $added ) );
	}
}
