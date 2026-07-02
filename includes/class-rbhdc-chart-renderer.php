<?php
/**
 * Chart renderer — turns the JSON response from /hd-data into safe HTML.
 *
 * Bodygraph response shape (relevant subset):
 *   Properties: {
 *     Type:             { name, id, option, description, link }
 *     Strategy:         { ... }
 *     InnerAuthority:   { ... }
 *     Profile:          { ... }
 *     Definition:       { ... }
 *     IncarnationCross: { ... }
 *     Signature:        { ... }
 *     NotSelfTheme:     { ... }
 *   }
 *   Channels:          ["11-56", "12-22", ...]
 *   DefinedCenters:    ["Throat", ...]
 *   OpenCenters:       ["Head", ...]
 *   ConsciousCenters:  [...]
 *   UnconsciousCenters:[...]
 *   Gates:             { "0": 2, "1": 1, ... }
 *   SVG:               "<svg ...>"   (only when `design` param is set)
 *   ChartUrl:          "https://..."
 *
 * @package RayBogman\HumanDesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers that escape and format Bodygraph response data.
 */
class RBHDC_Chart_Renderer {

	/**
	 * Render the full chart result as HTML.
	 *
	 * @param array $data Decoded API response.
	 * @return string
	 */
	public static function render( array $data ) {
		ob_start();
		?>
		<div class="rbhd-result">
			<?php self::render_svg( $data ); ?>
			<div class="rbhd-cards">
				<?php
				self::render_birth_meta_card( $data );
				self::render_summary_card( $data );
				self::render_variables_card( $data );
				self::render_channels_card( $data );
				self::render_centers_card( $data );
				self::render_planets_card( $data );
				self::render_gates_card( $data );
				// Share + Raw API cards intentionally omitted in the client.
				?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the SVG bodygraph if the API returned one.
	 *
	 * @param array $data Response payload.
	 */
	private static function render_svg( array $data ) {
		$svg = '';
		foreach ( array( 'SVG', 'svg', 'chart', 'Chart' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
				$svg = $data[ $key ];
				break;
			}
		}
		if ( '' === $svg ) {
			return;
		}
		echo '<div class="rbhd-svg-wrap">' . wp_kses( $svg, self::allowed_svg_tags() ) . '</div>';
	}

	/**
	 * Render the summary card (Type / Strategy / Authority / Profile / Cross / Signature / Theme).
	 *
	 * @param array $data Response payload.
	 */
	private static function render_summary_card( array $data ) {
		$rows = array(
			'Type'             => __( 'Type', 'reloom-human-design' ),
			'Strategy'         => __( 'Strategy', 'reloom-human-design' ),
			'InnerAuthority'   => __( 'Inner Authority', 'reloom-human-design' ),
			'Profile'          => __( 'Profile', 'reloom-human-design' ),
			'Definition'       => __( 'Definition', 'reloom-human-design' ),
			'IncarnationCross' => __( 'Incarnation Cross', 'reloom-human-design' ),
			'Signature'        => __( 'Signature', 'reloom-human-design' ),
			'NotSelfTheme'     => __( 'Not-Self Theme', 'reloom-human-design' ),
		);
		$properties = isset( $data['Properties'] ) && is_array( $data['Properties'] ) ? $data['Properties'] : array();

		echo '<div class="rbhd-card"><h2>' . esc_html__( 'Summary', 'reloom-human-design' ) . '</h2><table class="rbhd-table rbhd-summary-table">';
		$any = false;
		foreach ( $rows as $key => $label ) {
			$value = self::property_value( $properties, $key );
			if ( '' === $value ) {
				continue;
			}
			$any  = true;
			$tip  = self::summary_layman_tip( $key, $value );
			echo '<tr><th scope="row">' . esc_html( $label );
			if ( '' !== $tip ) {
				echo ' <span class="rbhd-tip" tabindex="0" role="button" aria-label="' . esc_attr__( 'Explain', 'reloom-human-design' ) . '">?<span class="rbhd-tip-body">' . esc_html( $tip ) . '</span></span>';
			}
			echo '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		if ( ! $any ) {
			echo '<tr><td colspan="2"><em>' . esc_html__( 'No summary fields returned by the API.', 'reloom-human-design' ) . '</em></td></tr>';
		}
		echo '</table></div>';
		// Inline styles — small, scoped, and idempotent (browsers de-dupe identical CSS).
		echo '<style>'
			. '.rbhd-summary-table .rbhd-tip{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;margin-left:4px;border-radius:50%;background:#dcdcde;color:#1d2327;font-size:11px;font-weight:700;cursor:help;position:relative;vertical-align:middle;line-height:1;}'
			. '.rbhd-summary-table .rbhd-tip:hover,.rbhd-summary-table .rbhd-tip:focus{background:#2271b1;color:#fff;outline:none;}'
			. '.rbhd-summary-table .rbhd-tip-body{position:absolute;left:50%;top:calc(100% + 8px);transform:translateX(-50%);min-width:240px;max-width:340px;background:#1d2327;color:#fff;font-size:12px;font-weight:400;line-height:1.45;padding:8px 10px;border-radius:6px;text-align:left;opacity:0;pointer-events:none;transition:opacity .12s;z-index:10;box-shadow:0 4px 16px rgba(0,0,0,.2);}'
			. '.rbhd-summary-table .rbhd-tip-body::before{content:"";position:absolute;left:50%;bottom:100%;transform:translateX(-50%);border:5px solid transparent;border-bottom-color:#1d2327;}'
			. '.rbhd-summary-table .rbhd-tip:hover .rbhd-tip-body,.rbhd-summary-table .rbhd-tip:focus .rbhd-tip-body{opacity:1;pointer-events:auto;}'
			. '</style>';
	}

	/**
	 * Plain-language explainer for one summary row. Combines:
	 *  - what the field IS in everyday language
	 *  - what THIS specific value means for the reader
	 *
	 * Falls back to just the field-level text when the value isn't recognised.
	 *
	 * @param string $key   Property key (Type, Strategy, ...).
	 * @param string $value Resolved property value.
	 * @return string Empty string if nothing useful to say.
	 */
	private static function summary_layman_tip( $key, $value ) {
		$field = '';
		switch ( $key ) {
			case 'Type':
				$field = __( 'Your basic energetic blueprint — how your body is built to engage with the world.', 'reloom-human-design' );
				break;
			case 'Strategy':
				$field = __( 'The simple rule for making decisions that feel right and do not drain you.', 'reloom-human-design' );
				break;
			case 'InnerAuthority':
				$field = __( 'Where in your body your real "yes" or "no" lives — the signal to actually trust.', 'reloom-human-design' );
				break;
			case 'Profile':
				$field = __( 'The role you naturally take when learning and relating to others (two numbers, 1–6).', 'reloom-human-design' );
				break;
			case 'Definition':
				$field = __( 'How the energetic parts of you are connected — one stream, two streams, or more.', 'reloom-human-design' );
				break;
			case 'IncarnationCross':
				$field = __( 'A short label for the lifelong theme you are here to explore.', 'reloom-human-design' );
				break;
			case 'Signature':
				$field = __( 'The feeling you get when you are living your design right — your green light.', 'reloom-human-design' );
				break;
			case 'NotSelfTheme':
				$field = __( 'The feeling you get when you are pushing against your design — your warning light.', 'reloom-human-design' );
				break;
		}
		if ( '' === $field ) {
			return '';
		}
		$value_norm = strtolower( trim( (string) $value ) );
		$specific   = self::summary_value_layman( $key, $value_norm );
		return '' !== $specific ? $field . ' ' . $specific : $field;
	}

	private static function summary_value_layman( $key, $value_norm ) {
		switch ( $key ) {
			case 'Type':
				if ( false !== strpos( $value_norm, 'manifesting generator' ) ) {
					return __( 'A multi-passionate doer — your body has steady fuel AND can initiate; thrives on responding to many things and pivoting fast.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'generator' ) ) {
					return __( 'A steady doer — your body has sustainable life-force when it engages with what genuinely excites you.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'manifestor' ) ) {
					return __( 'A starter — you initiate things into motion. Bursts of intense action, then real rest.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'projector' ) ) {
					return __( 'A guide — you see other people clearly; your power lands when you are recognised and invited, not when you push.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'reflector' ) ) {
					return __( 'A mirror — you sample the people and places around you; live somewhere that feels good, and take time before big decisions.', 'reloom-human-design' );
				}
				break;
			case 'Strategy':
				if ( false !== strpos( $value_norm, 'inform' ) ) {
					return __( 'Tell the people affected BEFORE you start — it removes resistance and lets you act freely.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'respond' ) ) {
					return __( 'Wait for life to show up with something, then notice your gut response — yes/no/maybe.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'invitation' ) ) {
					return __( 'Wait to be recognised and invited — to roles, friendships, projects. Acting uninvited drains you and gets ignored.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'lunar' ) ) {
					return __( 'Give big decisions a full lunar cycle (≈28 days) before committing — clarity comes through time and varied input.', 'reloom-human-design' );
				}
				break;
			case 'InnerAuthority':
				if ( false !== strpos( $value_norm, 'emotional' ) || false !== strpos( $value_norm, 'solar' ) ) {
					return __( 'Your "yes" needs to ride the emotional wave — never decide from a high or a low; sleep on it.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'sacral' ) ) {
					return __( 'Your gut gives the real answer — that immediate "uh-huh" or "uh-uh" the second something is asked.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'splen' ) ) {
					return __( 'A quiet in-the-moment knowing — the soft inner "yes" or "no" that comes once and does not repeat.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'ego' ) || false !== strpos( $value_norm, 'heart' ) ) {
					return __( 'Notice what your willpower actually backs up with action — your real "yes" is what your gut says "I want" to.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'self' ) && false !== strpos( $value_norm, 'project' ) ) {
					return __( 'Talk it out — hear yourself say it aloud to a trusted listener. The truth is in what you hear yourself say, not what they reply.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'mental' ) ) {
					return __( 'Think out loud across several days in safe spaces — your clarity needs different environments to surface.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'lunar' ) ) {
					return __( 'Wait a full lunar cycle (≈28 days) before big decisions — clarity needs time.', 'reloom-human-design' );
				}
				break;
			case 'Profile':
				$profile_map = array(
					'1/3' => __( 'The investigator-experimenter: study foundations, then learn by trial-and-error in real life.', 'reloom-human-design' ),
					'1/4' => __( 'The investigator-networker: deep study + share what you learn with your close circle.', 'reloom-human-design' ),
					'2/4' => __( 'The natural-with-friends: alone time recharges you; your closest network calls you out into the world.', 'reloom-human-design' ),
					'2/5' => __( 'The natural-with-strangers: alone time recharges you; the wider world projects roles onto you and expects solutions.', 'reloom-human-design' ),
					'3/5' => __( 'The experimenter-leader: learn by trial-and-error; bring practical solutions when others project expectations.', 'reloom-human-design' ),
					'3/6' => __( 'The experimenter-sage: messy trial-and-error in your first 30 years, then you become the wise observer.', 'reloom-human-design' ),
					'4/6' => __( 'The networker-sage: built through your close circle, then over time you become a role model.', 'reloom-human-design' ),
					'4/1' => __( 'The networker-investigator: built through your close circle, with deep foundations and study underneath.', 'reloom-human-design' ),
					'5/1' => __( 'The universal-investigator: people project expectations; your authority comes from deep study + solid foundations.', 'reloom-human-design' ),
					'5/2' => __( 'The universal-natural: people project; you bring solutions naturally — guard your alone time fiercely.', 'reloom-human-design' ),
					'6/2' => __( 'The role-model-natural: ~30 years of trial, then observing, then leading by example; alone time is fuel.', 'reloom-human-design' ),
					'6/3' => __( 'The role-model-experimenter: live the lessons through experimentation, become the wise example.', 'reloom-human-design' ),
				);
				$compact = preg_replace( '/\s+/', '', $value_norm );
				if ( isset( $profile_map[ $compact ] ) ) {
					return $profile_map[ $compact ];
				}
				break;
			case 'Definition':
				if ( false !== strpos( $value_norm, 'single' ) ) {
					return __( 'One continuous internal stream — you tend to feel whole on your own, and process internally before sharing.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'triple' ) ) {
					return __( 'Three separate internal streams — you process via lots of different people; busy social input helps you integrate.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'quadruple' ) ) {
					return __( 'Four separate internal streams — you genuinely process through diverse environments and people.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'split' ) ) {
					return __( 'Two separate internal streams — you naturally seek someone or something to bridge the gap between your inner parts.', 'reloom-human-design' );
				}
				if ( false !== strpos( $value_norm, 'no def' ) || false !== strpos( $value_norm, 'none' ) ) {
					return __( 'No fixed internal stream — you take colour from your environment; choose where you live and who you spend time with carefully.', 'reloom-human-design' );
				}
				break;
			case 'Signature':
				if ( 'peace' === $value_norm )         { return __( 'When you feel calm and unforced after acting, you did it the right way for you.', 'reloom-human-design' ); }
				if ( 'satisfaction' === $value_norm )  { return __( 'When you feel quietly satisfied at the end of the day, you spent your energy correctly.', 'reloom-human-design' ); }
				if ( 'success' === $value_norm )       { return __( 'When you feel that quiet "I nailed it" recognition, you were invited in the right way.', 'reloom-human-design' ); }
				if ( 'surprise' === $value_norm )      { return __( 'When you feel pleasantly surprised by how things unfolded, you sampled life correctly.', 'reloom-human-design' ); }
				break;
			case 'NotSelfTheme':
				if ( 'anger' === $value_norm )           { return __( 'Chronic anger or frustration with others means you are not informing them before you act.', 'reloom-human-design' ); }
				if ( 'frustration' === $value_norm )     { return __( 'Chronic frustration means you said yes to something your gut did not actually want.', 'reloom-human-design' ); }
				if ( 'bitterness' === $value_norm )      { return __( 'Chronic bitterness means you tried to push or act without being recognised or invited.', 'reloom-human-design' ); }
				if ( 'disappointment' === $value_norm )  { return __( 'Chronic disappointment means you decided too fast without giving life its full cycle.', 'reloom-human-design' ); }
				break;
		}
		return '';
	}

	/**
	 * Render the channels card.
	 *
	 * @param array $data Response payload.
	 */
	private static function render_channels_card( array $data ) {
		$channels = isset( $data['Channels'] ) && is_array( $data['Channels'] ) ? $data['Channels'] : array();
		if ( empty( $channels ) ) {
			return;
		}
		$tooltips = self::extract_tooltips( $data, 'Channels' );
		echo '<div class="rbhd-card"><h2>' . esc_html__( 'Channels', 'reloom-human-design' ) . '</h2><ul class="rbhd-list rbhd-list-tooltip">';
		foreach ( $channels as $channel ) {
			$label = self::format_channel( $channel );
			if ( '' === $label ) {
				continue;
			}
			$key = is_scalar( $channel ) ? (string) $channel : $label;
			$tt  = self::lookup_tooltip( $tooltips, array( $key, $label, str_replace( ' ', '', $key ) ) );
			echo '<li>';
			if ( '' !== $tt ) {
				echo '<details class="rbhd-tooltip"><summary>' . esc_html( $label ) . '</summary>';
				echo '<div class="rbhd-tooltip-body">' . wp_kses_post( $tt ) . '</div>';
				echo '</details>';
			} else {
				echo esc_html( $label );
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Render the centers card (defined / open / conscious / unconscious).
	 *
	 * @param array $data Response payload.
	 */
	private static function render_centers_card( array $data ) {
		$groups = array(
			'DefinedCenters'     => __( 'Defined', 'reloom-human-design' ),
			'OpenCenters'        => __( 'Open', 'reloom-human-design' ),
			'ConsciousCenters'   => __( 'Conscious', 'reloom-human-design' ),
			'UnconsciousCenters' => __( 'Unconscious', 'reloom-human-design' ),
		);
		$any = false;
		foreach ( $groups as $key => $label ) {
			if ( ! empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$any = true;
				break;
			}
		}
		if ( ! $any ) {
			return;
		}
		$tooltips = self::extract_tooltips( $data, 'Centers' );
		echo '<div class="rbhd-card"><h2>' . esc_html__( 'Centers', 'reloom-human-design' ) . '</h2>';
		foreach ( $groups as $key => $label ) {
			if ( empty( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				continue;
			}
			echo '<h3>' . esc_html( $label ) . '</h3>';
			if ( empty( $tooltips ) ) {
				echo '<p>' . esc_html( self::join_strings( $data[ $key ] ) ) . '</p>';
				continue;
			}
			echo '<ul class="rbhd-list rbhd-list-tooltip rbhd-centers-list">';
			foreach ( $data[ $key ] as $center ) {
				$name = is_scalar( $center ) ? (string) $center : '';
				if ( '' === $name ) {
					continue;
				}
				$tt = self::lookup_tooltip( $tooltips, array( $name ) );
				echo '<li>';
				if ( '' !== $tt ) {
					echo '<details class="rbhd-tooltip"><summary>' . esc_html( $name ) . '</summary>';
					echo '<div class="rbhd-tooltip-body">' . wp_kses_post( $tt ) . '</div>';
					echo '</details>';
				} else {
					echo esc_html( $name );
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Pull the tooltips dict for Centers or Channels out of the response. The API has
	 * been seen under a couple of different shapes — handle them defensively.
	 *
	 * @param array  $data Decoded response.
	 * @param string $kind 'Centers' or 'Channels'.
	 * @return array Map of key => HTML/text tooltip string.
	 */
	private static function extract_tooltips( array $data, $kind ) {
		$candidates = array(
			array( 'Tooltips', $kind ),
			array( 'tooltips', strtolower( $kind ) ),
			array( $kind . 'Tooltips' ),
			array( strtolower( $kind ) . '_tooltips' ),
		);
		foreach ( $candidates as $path ) {
			$cur = $data;
			$ok  = true;
			foreach ( $path as $step ) {
				if ( is_array( $cur ) && isset( $cur[ $step ] ) ) {
					$cur = $cur[ $step ];
				} else {
					$ok = false;
					break;
				}
			}
			if ( $ok && is_array( $cur ) ) {
				return $cur;
			}
		}
		return array();
	}

	/**
	 * Look up a tooltip by trying several candidate keys.
	 *
	 * @param array         $tooltips Tooltip map.
	 * @param array<string> $keys     Candidate keys.
	 * @return string Tooltip text (may contain limited HTML), or empty.
	 */
	private static function lookup_tooltip( array $tooltips, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $tooltips[ $k ] ) ) {
				$v = $tooltips[ $k ];
				if ( is_string( $v ) && '' !== trim( $v ) ) {
					return $v;
				}
				if ( is_array( $v ) ) {
					foreach ( array( 'description', 'text', 'body', 'tooltip' ) as $sk ) {
						if ( isset( $v[ $sk ] ) && is_string( $v[ $sk ] ) && '' !== trim( $v[ $sk ] ) ) {
							return $v[ $sk ];
						}
					}
				}
			}
		}
		return '';
	}

	/**
	 * Render birth-meta line: design date, age, UTC time, 12h time.
	 *
	 * @param array $data Response payload.
	 */
	private static function render_birth_meta_card( array $data ) {
		$rows  = array();
		$pairs = array(
			array( 'BirthDateLocal', 'birth_date_local',     __( 'Birth (local)', 'reloom-human-design' ) ),
			array( 'BirthDate12h',   'birth_date_12h',       __( 'Birth (12-hour)', 'reloom-human-design' ) ),
			array( 'BirthDateUtc',   'birth_date_utc',       __( 'Birth (UTC)', 'reloom-human-design' ) ),
			array( 'DesignDate',     'design_date',          __( 'Design Date (-88°)', 'reloom-human-design' ) ),
			array( 'DesignDateUtc',  'design_date_utc',      __( 'Design Date (UTC)', 'reloom-human-design' ) ),
			array( 'Age',            'age',                  __( 'Age', 'reloom-human-design' ) ),
		);
		foreach ( $pairs as $pair ) {
			$value = self::pluck_scalar( $data, array( $pair[0], $pair[1] ) );
			if ( '' !== $value ) {
				$rows[ $pair[2] ] = $value;
			}
		}
		if ( empty( $rows ) ) {
			return;
		}
		echo '<div class="rbhd-card"><h2>' . esc_html__( 'Birth & Design', 'reloom-human-design' ) . '</h2><table class="rbhd-table">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table></div>';
	}

	/**
	 * Render the Variables / PHS card (Digestion, Sense, Design Sense, Motivation, Perspective, Environment).
	 *
	 * @param array $data Response payload.
	 */
	private static function render_variables_card( array $data ) {
		$rows = array(
			'Digestion'   => __( 'Digestion', 'reloom-human-design' ),
			'Sense'       => __( 'Sense', 'reloom-human-design' ),
			'DesignSense' => __( 'Design Sense', 'reloom-human-design' ),
			'Motivation'  => __( 'Motivation', 'reloom-human-design' ),
			'Perspective' => __( 'Perspective', 'reloom-human-design' ),
			'Environment' => __( 'Environment', 'reloom-human-design' ),
			'Awareness'   => __( 'Awareness (arrow)', 'reloom-human-design' ),
		);
		$properties = isset( $data['Properties'] ) && is_array( $data['Properties'] ) ? $data['Properties'] : array();

		$found = array();
		foreach ( $rows as $key => $label ) {
			$value = self::property_full( $properties, $key );
			if ( null !== $value ) {
				$found[ $key ] = array( 'label' => $label, 'value' => $value );
			}
		}
		if ( empty( $found ) ) {
			return;
		}
		echo '<div class="rbhd-card"><h2>' . esc_html__( 'Variables (PHS)', 'reloom-human-design' ) . '</h2><table class="rbhd-table">';
		foreach ( $found as $entry ) {
			echo '<tr><th scope="row">' . esc_html( $entry['label'] ) . '</th><td>';
			echo esc_html( $entry['value']['option'] );
			if ( '' !== $entry['value']['description'] ) {
				echo '<div class="rbhd-meta">' . esc_html( $entry['value']['description'] ) . '</div>';
			}
			echo '</td></tr>';
		}
		echo '</table></div>';
	}

	/**
	 * Render the Personality vs Design planets card (two columns).
	 *
	 * @param array $data Response payload.
	 */
	private static function render_planets_card( array $data ) {
		$cons = self::pluck_planets( $data, array( 'Personality', 'PersonalityPlanets', 'personality_planets', 'personality' ) );
		$desg = self::pluck_planets( $data, array( 'Design', 'DesignPlanets', 'design_planets', 'design' ) );
		if ( empty( $cons ) && empty( $desg ) ) {
			return;
		}
		echo '<div class="rbhd-card rbhd-card-planets"><h2>' . esc_html__( 'Planets', 'reloom-human-design' ) . '</h2>';
		echo '<div class="rbhd-planets-grid">';
		echo '<div class="rbhd-planets-col"><h3>' . esc_html__( 'Personality (conscious)', 'reloom-human-design' ) . '</h3>';
		self::render_planet_list( $cons );
		echo '</div>';
		echo '<div class="rbhd-planets-col"><h3>' . esc_html__( 'Design (unconscious)', 'reloom-human-design' ) . '</h3>';
		self::render_planet_list( $desg );
		echo '</div>';
		echo '</div></div>';
	}

	/**
	 * Render the active gates list.
	 *
	 * @param array $data Response payload.
	 */
	private static function render_gates_card( array $data ) {
		if ( ! isset( $data['Gates'] ) ) {
			return;
		}
		$gates = $data['Gates'];
		$flat  = array();
		if ( is_array( $gates ) ) {
			foreach ( $gates as $entry ) {
				if ( is_scalar( $entry ) ) {
					$flat[] = (string) $entry;
				} elseif ( is_array( $entry ) ) {
					if ( isset( $entry['number'] ) ) {
						$flat[] = (string) $entry['number'];
					} elseif ( isset( $entry['Number'] ) ) {
						$flat[] = (string) $entry['Number'];
					}
				}
			}
		}
		$flat = array_values( array_unique( array_filter( $flat, 'strlen' ) ) );
		if ( empty( $flat ) ) {
			return;
		}
		usort(
			$flat,
			static function ( $a, $b ) {
				return (int) $a - (int) $b;
			}
		);
		echo '<div class="rbhd-card"><h2>' . esc_html__( 'Active Gates', 'reloom-human-design' ) . '</h2><p>' . esc_html( implode( ', ', $flat ) ) . '</p></div>';
	}

	/**
	 * Render the share-link card if Bodygraph returned a public ChartUrl.
	 *
	 * @param array $data Response payload.
	 */
	private static function render_share_card( array $data ) {
		$url = '';
		foreach ( array( 'ChartUrl', 'chart_url', 'shareUrl', 'share_url' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
				$url = trim( $data[ $key ] );
				break;
			}
		}
		if ( '' === $url ) {
			return;
		}
		echo '<div class="rbhd-card"><h2>' . esc_html__( 'Share', 'reloom-human-design' ) . '</h2>';
		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer" class="button">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'View on Bodygraph', 'reloom-human-design' )
		);
		echo '</div>';
	}

	/**
	 * Render a single planet list (used in the planets card).
	 *
	 * @param array $planets List of planet entries.
	 */
	private static function render_planet_list( array $planets ) {
		if ( empty( $planets ) ) {
			echo '<p class="rbhd-empty">' . esc_html__( 'Not provided.', 'reloom-human-design' ) . '</p>';
			return;
		}
		echo '<table class="rbhd-table rbhd-planet-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Planet', 'reloom-human-design' ) . '</th>';
		echo '<th>' . esc_html__( 'Gate', 'reloom-human-design' ) . '</th>';
		echo '<th title="' . esc_attr__( 'Line', 'reloom-human-design' ) . '">' . esc_html__( 'L', 'reloom-human-design' ) . '</th>';
		echo '<th title="' . esc_attr__( 'Color', 'reloom-human-design' ) . '">' . esc_html__( 'C', 'reloom-human-design' ) . '</th>';
		echo '<th title="' . esc_attr__( 'Tone', 'reloom-human-design' ) . '">' . esc_html__( 'T', 'reloom-human-design' ) . '</th>';
		echo '<th title="' . esc_attr__( 'Base', 'reloom-human-design' ) . '">' . esc_html__( 'B', 'reloom-human-design' ) . '</th>';
		echo '<th title="' . esc_attr__( 'Fixing State (Exalted / Detriment / Fixed / Juxtaposed)', 'reloom-human-design' ) . '">' . esc_html__( 'Fix', 'reloom-human-design' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $planets as $planet ) {
			$name  = self::pluck_scalar( $planet, array( 'name', 'planet', 'Planet', 'Name' ) );
			$gate  = self::pluck_scalar( $planet, array( 'gate', 'Gate', 'gate_number', 'GateNumber' ) );
			$line  = self::pluck_scalar( $planet, array( 'line', 'Line' ) );
			$color = self::pluck_scalar( $planet, array( 'color', 'Color' ) );
			$tone  = self::pluck_scalar( $planet, array( 'tone', 'Tone' ) );
			$base  = self::pluck_scalar( $planet, array( 'base', 'Base' ) );
			$fix   = self::pluck_scalar( $planet, array( 'fixingState', 'FixingState', 'fixing_state', 'Fixing' ) );
			if ( '' === $name && '' === $gate ) {
				continue;
			}
			echo '<tr>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( $gate ) . '</td>';
			echo '<td>' . esc_html( $line ) . '</td>';
			echo '<td>' . esc_html( $color ) . '</td>';
			echo '<td>' . esc_html( $tone ) . '</td>';
			echo '<td>' . esc_html( $base ) . '</td>';
			$fix_label = self::format_fixing_state( $fix );
			echo '<td class="rbhd-planet-fix" data-fix="' . esc_attr( strtolower( $fix ) ) . '">' . esc_html( $fix_label ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Map a raw FixingState value to a short human-readable label.
	 *
	 * @param string $value Raw API value (e.g. "Exalted", "Detriment", "Fixed", "Juxtaposed").
	 * @return string
	 */
	private static function format_fixing_state( $value ) {
		$v = strtolower( trim( (string) $value ) );
		if ( '' === $v ) {
			return '';
		}
		$map = array(
			'exalted'    => '↑',
			'detriment'  => '↓',
			'fixed'      => '●',
			'juxtaposed' => '◇',
			'fall'       => '↓',
			'dignified'  => '↑',
		);
		if ( isset( $map[ $v ] ) ) {
			return $map[ $v ] . ' ' . ucfirst( $v );
		}
		return ucfirst( $v );
	}

	/**
	 * Pluck the first matching scalar from an array, given a list of candidate keys.
	 *
	 * @param mixed         $source Array or other value.
	 * @param array<string> $keys   Candidate keys (in priority order).
	 * @return string
	 */
	private static function pluck_scalar( $source, array $keys ) {
		if ( ! is_array( $source ) ) {
			return '';
		}
		foreach ( $keys as $k ) {
			if ( isset( $source[ $k ] ) && is_scalar( $source[ $k ] ) && '' !== (string) $source[ $k ] ) {
				return (string) $source[ $k ];
			}
		}
		return '';
	}

	/**
	 * Pluck a list of planet entries from any of several plausible top-level keys.
	 *
	 * Handles three shapes:
	 *   ['Sun' => {...}, 'Earth' => {...}]   (object keyed by planet name)
	 *   [{name:'Sun',...}, {name:'Earth',...}]  (array of objects)
	 *   {planets: [...]}                      (wrapped under 'planets')
	 *
	 * @param array         $data Response payload.
	 * @param array<string> $keys Candidate top-level keys.
	 * @return array
	 */
	private static function pluck_planets( array $data, array $keys ) {
		$source = null;
		foreach ( $keys as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				$source = $data[ $k ];
				break;
			}
		}
		if ( null === $source ) {
			return array();
		}
		if ( isset( $source['planets'] ) && is_array( $source['planets'] ) ) {
			$source = $source['planets'];
		}
		$out = array();
		// Detect shape: indexed list vs name-keyed map.
		$is_indexed = array_keys( $source ) === range( 0, count( $source ) - 1 );
		if ( $is_indexed ) {
			foreach ( $source as $item ) {
				if ( is_array( $item ) ) {
					$out[] = $item;
				}
			}
		} else {
			foreach ( $source as $name => $item ) {
				if ( is_array( $item ) ) {
					if ( ! isset( $item['name'] ) ) {
						$item['name'] = (string) $name;
					}
					$out[] = $item;
				}
			}
		}
		return $out;
	}

	/**
	 * Pluck the full {option, description} from a Properties entry.
	 *
	 * @param array  $properties Properties subobject.
	 * @param string $key        Property key.
	 * @return array{option:string, description:string}|null
	 */
	private static function property_full( array $properties, $key ) {
		if ( ! isset( $properties[ $key ] ) ) {
			return null;
		}
		$entry = $properties[ $key ];
		if ( is_scalar( $entry ) ) {
			return array( 'option' => (string) $entry, 'description' => '' );
		}
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$option = '';
		foreach ( array( 'option', 'id', 'name' ) as $sub ) {
			if ( isset( $entry[ $sub ] ) && is_scalar( $entry[ $sub ] ) && '' !== (string) $entry[ $sub ] ) {
				$option = (string) $entry[ $sub ];
				break;
			}
		}
		if ( '' === $option ) {
			return null;
		}
		$desc = isset( $entry['description'] ) && is_scalar( $entry['description'] ) ? (string) $entry['description'] : '';
		return array( 'option' => $option, 'description' => $desc );
	}

	/**
	 * Collapsible raw JSON viewer — useful when iterating on the renderer.
	 *
	 * @param array $data Response payload.
	 */
	private static function render_raw_card( array $data ) {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return;
		}
		echo '<div class="rbhd-card rbhd-card-raw"><details><summary>' . esc_html__( 'Raw API response', 'reloom-human-design' ) . '</summary><pre class="rbhd-raw">' . esc_html( $json ) . '</pre></details></div>';
	}

	/**
	 * Pull the displayable string from a Properties.<Key> entry.
	 * Prefers `option`, then `id`, then `name`.
	 *
	 * @param array  $properties Properties subobject.
	 * @param string $key        Property key (e.g. "Type").
	 * @return string
	 */
	private static function property_value( array $properties, $key ) {
		if ( ! isset( $properties[ $key ] ) ) {
			return '';
		}
		$entry = $properties[ $key ];
		if ( is_scalar( $entry ) ) {
			return (string) $entry;
		}
		if ( ! is_array( $entry ) ) {
			return '';
		}
		foreach ( array( 'option', 'id', 'name' ) as $sub ) {
			if ( isset( $entry[ $sub ] ) && is_scalar( $entry[ $sub ] ) && '' !== (string) $entry[ $sub ] ) {
				return (string) $entry[ $sub ];
			}
		}
		return '';
	}

	/**
	 * Public accessor: displayable value of a chart summary property
	 * (e.g. "Type", "InnerAuthority", "Profile", "Definition"). Used by the
	 * Reports page to aggregate Human Design attributes.
	 *
	 * @param array  $data Full /hd-data response.
	 * @param string $key  Property key.
	 * @return string
	 */
	public static function get_property( array $data, $key ) {
		$properties = isset( $data['Properties'] ) && is_array( $data['Properties'] ) ? $data['Properties'] : array();
		return self::property_value( $properties, (string) $key );
	}

	/**
	 * Format a channel item.
	 *
	 * @param mixed $channel Channel entry.
	 * @return string
	 */
	private static function format_channel( $channel ) {
		if ( is_scalar( $channel ) ) {
			return (string) $channel;
		}
		if ( ! is_array( $channel ) ) {
			return '';
		}
		$gates = '';
		if ( isset( $channel['gates'] ) && is_array( $channel['gates'] ) ) {
			$gates = implode( '-', array_map( 'strval', $channel['gates'] ) );
		} elseif ( isset( $channel['number'] ) ) {
			$gates = (string) $channel['number'];
		}
		$name = isset( $channel['name'] ) && is_scalar( $channel['name'] ) ? (string) $channel['name'] : '';
		if ( '' !== $gates && '' !== $name ) {
			return $gates . ' — ' . $name;
		}
		return '' !== $gates ? $gates : $name;
	}

	/**
	 * Coerce a list of mixed scalars/arrays into a comma-joined string.
	 *
	 * @param array $list Items.
	 * @return string
	 */
	private static function join_strings( array $list ) {
		$out = array();
		foreach ( $list as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			} elseif ( is_array( $item ) ) {
				foreach ( array( 'name', 'id', 'option' ) as $sub ) {
					if ( isset( $item[ $sub ] ) && is_scalar( $item[ $sub ] ) ) {
						$out[] = (string) $item[ $sub ];
						break;
					}
				}
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Convert a small subset of Markdown to safe HTML.
	 *
	 * Supports: ## headings, **bold**, *italic*, `code`, unordered/ordered lists,
	 * blank-line paragraphs. The output is run through wp_kses_post by callers.
	 *
	 * @param string $md Markdown source.
	 * @return string HTML.
	 */
	public static function markdown_to_html( $md ) {
		$md = (string) $md;
		if ( '' === $md ) {
			return '';
		}
		$md = str_replace( "\r\n", "\n", $md );
		$md = trim( $md );

		$lines  = explode( "\n", $md );
		$out    = '';
		$buffer = array();
		$mode   = 'paragraph';

		$flush = function () use ( &$buffer, &$mode, &$out ) {
			if ( empty( $buffer ) ) {
				$mode = 'paragraph';
				return;
			}
			if ( 'ul' === $mode ) {
				$out .= '<ul>';
				foreach ( $buffer as $item ) {
					$out .= '<li>' . self::md_inline( $item ) . '</li>';
				}
				$out .= '</ul>';
			} elseif ( 'ol' === $mode ) {
				$out .= '<ol>';
				foreach ( $buffer as $item ) {
					$out .= '<li>' . self::md_inline( $item ) . '</li>';
				}
				$out .= '</ol>';
			} else {
				$text = self::md_inline( implode( ' ', $buffer ) );
				if ( '' !== trim( $text ) ) {
					$out .= '<p>' . $text . '</p>';
				}
			}
			$buffer = array();
			$mode   = 'paragraph';
		};

		foreach ( $lines as $line ) {
			$trim = trim( $line );
			if ( '' === $trim ) {
				$flush();
				continue;
			}
			if ( preg_match( '/^###\s+(.+)$/', $trim, $m ) ) {
				$flush();
				$out .= '<h4>' . self::md_inline( $m[1] ) . '</h4>';
				continue;
			}
			if ( preg_match( '/^##\s+(.+)$/', $trim, $m ) ) {
				$flush();
				$out .= '<h3>' . self::md_inline( $m[1] ) . '</h3>';
				continue;
			}
			if ( preg_match( '/^#\s+(.+)$/', $trim, $m ) ) {
				$flush();
				$out .= '<h3>' . self::md_inline( $m[1] ) . '</h3>';
				continue;
			}
			if ( preg_match( '/^[-*]\s+(.+)$/', $trim, $m ) ) {
				if ( 'ul' !== $mode ) {
					$flush();
					$mode = 'ul';
				}
				$buffer[] = $m[1];
				continue;
			}
			if ( preg_match( '/^\d+\.\s+(.+)$/', $trim, $m ) ) {
				if ( 'ol' !== $mode ) {
					$flush();
					$mode = 'ol';
				}
				$buffer[] = $m[1];
				continue;
			}
			if ( 'paragraph' !== $mode ) {
				$flush();
			}
			$mode    = 'paragraph';
			$buffer[] = $trim;
		}
		$flush();
		return $out;
	}

	/**
	 * Convert inline Markdown spans (bold/italic/code) to escaped HTML.
	 *
	 * @param string $text Inline markdown.
	 * @return string
	 */
	private static function md_inline( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text );
		return $text;
	}

	/**
	 * Public accessor for the SVG kses map (used by RBHD_Astro_Renderer).
	 *
	 * @return array
	 */
	public static function allowed_svg_tags_public() {
		return self::allowed_svg_tags();
	}

	/**
	 * Allowed SVG tags/attrs for wp_kses when echoing chart markup.
	 *
	 * @return array
	 */
	private static function allowed_svg_tags() {
		$attrs = array(
			'id'                => true,
			'class'             => true,
			'fill'              => true,
			'stroke'            => true,
			'stroke-width'      => true,
			'stroke-linecap'    => true,
			'stroke-linejoin'   => true,
			'stroke-miterlimit' => true,
			'stroke-dasharray'  => true,
			'opacity'           => true,
			'fill-opacity'      => true,
			'transform'         => true,
			'width'             => true,
			'height'            => true,
			'x'                 => true,
			'y'                 => true,
			'x1'                => true,
			'y1'                => true,
			'x2'                => true,
			'y2'                => true,
			'cx'                => true,
			'cy'                => true,
			'r'                 => true,
			'rx'                => true,
			'ry'                => true,
			'd'                 => true,
			'points'            => true,
			'viewbox'           => true,
			'xmlns'             => true,
			'xmlns:xlink'       => true,
			'preserveaspectratio' => true,
			'text-anchor'       => true,
			'font-family'       => true,
			'font-size'         => true,
			'font-weight'       => true,
			'dx'                => true,
			'dy'                => true,
			'href'              => true,
			'xlink:href'        => true,
		);
		$tags  = array(
			'svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon',
			'ellipse', 'text', 'tspan', 'defs', 'use', 'title', 'desc',
			'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask',
		);
		$out   = array();
		foreach ( $tags as $tag ) {
			$out[ $tag ] = $attrs;
		}
		return $out;
	}
}
