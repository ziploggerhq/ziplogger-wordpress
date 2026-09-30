<?php
/**
 * Settings -> ZipLogger: the tabbed screen shell, request handlers and shared pieces.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin;

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Destination;
use ZipLogger\WordPress\Health;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Queue_Store;
use ZipLogger\WordPress\Scheduler;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Settings_Schema;
use ZipLogger\WordPress\Worker;

defined( 'ABSPATH' ) || exit;

/**
 * Every state-changing request is an admin-post.php action that checks BOTH the capability
 * (manage_options) and a nonce - a nonce proves intent, it is not authorization. Results are shown
 * as one-shot notices kept in a short-lived per-user transient, so nothing user-supplied travels in
 * a redirect URL. No credential is ever rendered: only a masked hint is shown.
 *
 * The screen is ordinary server-rendered WordPress admin markup (tabs, form tables, cards): no
 * front-end framework is loaded, and the only script is a confirmation for destructive actions.
 */
final class Settings_Page {

	const SLUG       = 'ziplogger';
	const CAPABILITY = 'manage_options';

	/**
	 * Tab slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'overview'    => __( 'Overview', 'ziplogger' ),
			'connection'  => __( 'Connection', 'ziplogger' ),
			'logs'        => __( 'Server logs', 'ziplogger' ),
			'browser'     => __( 'Browser monitoring', 'ziplogger' ),
			'analytics'   => __( 'Analytics', 'ziplogger' ),
			'replay'      => __( 'Session replay', 'ziplogger' ),
			'tracing'     => __( 'Tracing', 'ziplogger' ),
			'woocommerce' => __( 'WooCommerce', 'ziplogger' ),
			'privacy'     => __( 'Privacy and consent', 'ziplogger' ),
			'diagnostics' => __( 'Diagnostics', 'ziplogger' ),
		);
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_ziplogger_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ziplogger_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_ziplogger_flush', array( $this, 'handle_flush' ) );
		add_action( 'admin_post_ziplogger_clear', array( $this, 'handle_clear' ) );
		add_action( 'admin_post_ziplogger_held', array( $this, 'handle_held' ) );
		add_action( 'admin_post_ziplogger_diagnostics', array( $this, 'handle_diagnostics' ) );
		add_action( 'admin_post_ziplogger_test_read', array( $this, 'handle_test_read' ) );
		\ZipLogger\WordPress\Dashboard\Ajax::register();
		add_filter( 'plugin_action_links_' . plugin_basename( ZIPLOGGER_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Settings -> ZipLogger.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'ZipLogger', 'ziplogger' ),
			__( 'ZipLogger', 'ziplogger' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Assets load on this screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'ziplogger-admin', ZIPLOGGER_URL . 'assets/admin.css', array(), ZIPLOGGER_VERSION );
		wp_enqueue_script( 'ziplogger-admin', ZIPLOGGER_URL . 'assets/admin.js', array(), ZIPLOGGER_VERSION, true );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ziplogger' ) . '</a>' );
		return $links;
	}

	/**
	 * Screen URL, optionally for one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function url( $tab = '' ) {
		$url = admin_url( 'options-general.php?page=' . self::SLUG );
		return '' !== $tab && isset( self::tabs()[ $tab ] ) && 'overview' !== $tab ? $url . '&tab=' . $tab : $url;
	}

	// ---------------------------------------------------------------------------------------------
	// Request handlers.
	// ---------------------------------------------------------------------------------------------

	/**
	 * Refuse the request unless the user may manage options AND the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage ZipLogger.', 'ziplogger' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * The tab a request came from (validated against the known tabs).
	 *
	 * @param array $post Unslashed request values.
	 * @return string
	 */
	private function tab_of( array $post ) {
		$tab = isset( $post['tab'] ) && is_string( $post['tab'] ) ? sanitize_key( $post['tab'] ) : 'overview';
		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	/**
	 * Save one section of settings.
	 *
	 * @return void
	 */
	public function handle_save() {
		$this->authorize( 'ziplogger_save' );

		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in authorize(); every value is validated below.
		$post    = is_array( $post ) ? $post : array();
		$section = isset( $post['section'] ) && is_string( $post['section'] ) ? sanitize_key( $post['section'] ) : '';
		$tab     = $this->tab_of( $post );
		$current = Settings::get();
		$notices = array();
		$errors  = array();

		switch ( $section ) {
			case 'connection':
				$notices = $this->save_connection( $post, $current );
				$errors  = null; // The connection handler builds its own notices.
				break;

			case 'logs':
				$result = Settings::validate_submission( $post, $current, array( 'logs' ) );
				$new    = $result['settings'];
				$errors = $result['errors'];
				Settings::save( $new );
				if ( $new['enabled'] && ! Settings::is_configured() ) {
					$notices[] = array( 'warning', __( 'Collection is on, but there is no server API key yet, so nothing can be delivered. Events wait in the local queue.', 'ziplogger' ) );
				}
				if ( $new['enabled'] && Settings::is_configured() && ( new Queue_Store() )->stats()['count'] > 0 ) {
					Scheduler::ensure_scheduled( Clock::time() );
				}
				break;

			case 'privacy':
				$basics         = Settings::validate_submission( $post, $current, array( 'basics' ) );
				$new            = $basics['settings'];
				$module         = isset( $post['ziplogger']['consent'] ) && is_array( $post['ziplogger']['consent'] ) ? $post['ziplogger']['consent'] : array();
				$checked        = Settings_Schema::validate_module( 'consent', $module, $current['consent'] );
				$new['consent'] = $checked['values'];
				$errors         = array_merge( $basics['errors'], $checked['errors'] );
				Settings::save( $new );
				break;

			case 'browser':
			case 'analytics':
			case 'replay':
			case 'tracing':
			case 'woocommerce':
				$module          = isset( $post['ziplogger'][ $section ] ) && is_array( $post['ziplogger'][ $section ] ) ? $post['ziplogger'][ $section ] : array();
				$checked         = Settings_Schema::validate_module( $section, $module, $current[ $section ] );
				$new             = $current;
				$new[ $section ] = $checked['values'];
				$errors          = $checked['errors'];

				// A module cannot be switched on while something it needs is missing: say so instead of
				// pretending it runs.
				if ( ! empty( $new[ $section ]['enabled'] ) ) {
					$blockers = $this->blockers_if_enabled( $section, $new );
					if ( $blockers ) {
						$new[ $section ]['enabled'] = false;
						foreach ( $blockers as $b ) {
							/* translators: 1: module name, 2: what is missing. */
							$errors[] = sprintf( __( '%1$s was not switched on: %2$s', 'ziplogger' ), self::tabs()[ $section ], $b );
						}
					}
				}
				Settings::save( $new );
				break;

			default:
				$errors = array( __( 'Unknown settings section.', 'ziplogger' ) );
		}

		if ( null !== $errors ) {
			foreach ( $errors as $error ) {
				$notices[] = array( 'error', $error );
			}
			if ( ! $errors ) {
				array_unshift( $notices, array( 'success', __( 'Settings saved.', 'ziplogger' ) ) );
			}
		}
		$this->finish( $notices, $tab );
	}

	/**
	 * What would block a module if it were switched on with these settings.
	 *
	 * @param string $module   Module.
	 * @param array  $settings Settings that would be saved.
	 * @return string[]
	 */
	private function blockers_if_enabled( $module, array $settings ) {
		$reasons = array();
		$browser = '' !== Settings::browser_key();
		if ( in_array( $module, array( 'browser', 'analytics', 'replay' ), true ) && ! $browser ) {
			$problem   = Settings::key_problem( 'browser' );
			$reasons[] = '' !== $problem ? $problem : __( 'add a browser API key on the Connection tab first.', 'ziplogger' );
		}
		if ( 'tracing' === $module && '' === Settings::api_key() ) {
			$reasons[] = __( 'add a server API key on the Connection tab first.', 'ziplogger' );
		}
		if ( 'tracing' === $module && ! empty( $settings['tracing']['browser'] ) && ! $browser ) {
			$reasons[] = __( 'browser-to-server tracing needs a browser API key.', 'ziplogger' );
		}
		if ( 'woocommerce' === $module ) {
			if ( ! Modules::woocommerce_active() ) {
				$reasons[] = __( 'WooCommerce is not active on this site.', 'ziplogger' );
			}
			if ( '' === Settings::api_key() ) {
				$reasons[] = __( 'add a server API key on the Connection tab first.', 'ziplogger' );
			}
		}
		return $reasons;
	}

	/**
	 * Save the connection section: label, environment, endpoint, credentials and the destination policy.
	 *
	 * @param array $post    Unslashed request.
	 * @param array $current Current settings.
	 * @return array[] Notices.
	 */
	private function save_connection( array $post, array $current ) {
		$notices = array();
		$before  = Destination::current();

		$result = Settings::validate_submission( $post, $current, array( 'connection' ) );
		Settings::save( $result['settings'] );
		foreach ( $result['errors'] as $error ) {
			$notices[] = array( 'error', $error );
		}

		$keys_changed = false;
		$new_keys     = isset( $post['key'] ) && is_array( $post['key'] ) ? $post['key'] : array();
		$remove       = isset( $post['remove_key'] ) && is_array( $post['remove_key'] ) ? $post['remove_key'] : array();
		$labels       = array(
			'server'  => __( 'server', 'ziplogger' ),
			'browser' => __( 'browser', 'ziplogger' ),
			'read'    => __( 'read', 'ziplogger' ),
		);
		foreach ( array_keys( Settings::KEY_KINDS ) as $kind ) {
			if ( ! empty( $remove[ $kind ] ) ) {
				Settings::remove_key( $kind );
				$keys_changed = true;
				/* translators: %s: server, browser or read. */
				$notices[] = array( 'success', sprintf( __( 'The saved %s key was removed.', 'ziplogger' ), $labels[ $kind ] ) );
			} elseif ( isset( $new_keys[ $kind ] ) && is_string( $new_keys[ $kind ] ) && '' !== trim( $new_keys[ $kind ] ) ) {
				$error = Settings::save_key( $kind, $new_keys[ $kind ] );
				if ( '' !== $error ) {
					$notices[] = array( 'error', $error );
				} else {
					$keys_changed = true;
					/* translators: %s: server, browser or read. */
					$notices[] = array( 'success', sprintf( __( 'The %s key was saved.', 'ziplogger' ), $labels[ $kind ] ) );
					if ( 0 !== strpos( trim( $new_keys[ $kind ] ), 'zk_' ) ) {
						$notices[] = array( 'warning', __( 'ZipLogger keys normally start with "zk_". If sending fails, check that you copied the right key.', 'ziplogger' ) );
					}
				}
			}
		}

		// Whatever was blocking delivery (a wrong key, a wrong URL) may just have been fixed, so saving
		// always lifts the pauses and lets the worker try again.
		( new Meta_Store() )->clear_gate();
		Scheduler::ensure_watchdog();

		// A different key or endpoint may mean a different workspace: queued data is never sent there
		// automatically. The administrator's policy decides what happens to it.
		$after = Destination::current();
		$queue = new Queue_Store();
		if ( '' !== $after && $before !== $after ) {
			$policy = Settings::get()['on_destination_change'];
			$held   = $queue->held_count( $after );
			if ( $held > 0 ) {
				if ( 'retarget' === $policy ) {
					$queue->retarget_held( $after );
					/* translators: %d: number of events. */
					$notices[] = array( 'warning', sprintf( _n( '%d queued item collected for the previous destination will now be sent to the new one, as you chose.', '%d queued items collected for the previous destination will now be sent to the new one, as you chose.', $held, 'ziplogger' ), $held ) );
				} elseif ( 'discard' === $policy ) {
					$deleted = $queue->discard_held( $after );
					( new Meta_Store() )->dropped( 'cleared', $deleted );
					/* translators: %d: number of events. */
					$notices[] = array( 'warning', sprintf( _n( '%d queued item collected for the previous destination was discarded, as you chose.', '%d queued items collected for the previous destination were discarded, as you chose.', $deleted, 'ziplogger' ), $deleted ) );
				} else {
					/* translators: %d: number of events. */
					$notices[] = array( 'warning', sprintf( _n( '%d queued item was collected for the previous key or endpoint. It is HELD, not sent, because the new key may belong to a different workspace. Choose what to do with it on the Connection tab.', '%d queued items were collected for the previous key or endpoint. They are HELD, not sent, because the new key may belong to a different workspace. Choose what to do with them on the Connection tab.', $held, 'ziplogger' ), $held ) );
				}
			}
		}
		if ( $keys_changed && Settings::is_configured() && $queue->stats()['count'] > 0 ) {
			Scheduler::ensure_scheduled( Clock::time() );
		}
		if ( ! $notices ) {
			$notices[] = array( 'success', __( 'Settings saved.', 'ziplogger' ) );
		} elseif ( ! in_array( 'error', array_column( $notices, 0 ), true ) ) {
			array_unshift( $notices, array( 'success', __( 'Settings saved.', 'ziplogger' ) ) );
		}
		return $notices;
	}

	/**
	 * Decide what happens to queued items held for another destination.
	 *
	 * @return void
	 */
	public function handle_held() {
		$this->authorize( 'ziplogger_held' );
		$choice      = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$destination = Destination::current();
		$queue       = new Queue_Store();
		$notices     = array();

		if ( '' === $destination ) {
			$notices[] = array( 'error', __( 'Add a server API key first.', 'ziplogger' ) );
		} elseif ( 'retarget' === $choice ) {
			$n = $queue->retarget_held( $destination );
			/* translators: %d: number of events. */
			$notices[] = array( 'success', sprintf( _n( '%d held item will now be sent to the current destination.', '%d held items will now be sent to the current destination.', $n, 'ziplogger' ), $n ) );
			Scheduler::ensure_scheduled( Clock::time() );
		} elseif ( 'discard' === $choice ) {
			$n = $queue->discard_held( $destination );
			( new Meta_Store() )->dropped( 'cleared', $n );
			/* translators: %d: number of events. */
			$notices[] = array( 'success', sprintf( _n( '%d held item was discarded.', '%d held items were discarded.', $n, 'ziplogger' ), $n ) );
		}
		$this->finish( $notices, 'connection' );
	}

	/**
	 * Send a test event and report exactly what ZipLogger answered.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->authorize( 'ziplogger_test' );
		$result = ( new Worker() )->send_test_event();

		$notices = array();
		if ( $result['ok'] ) {
			$notices[] = array(
				'success',
				sprintf(
					/* translators: 1: HTTP status code, 2: number of events accepted. */
					_n(
						'ZipLogger accepted the test event (HTTP %1$d, %2$d event). "Accepted" means it was received and queued for indexing; it can take a short while to appear in Search.',
						'ZipLogger accepted the test event (HTTP %1$d, %2$d events). "Accepted" means it was received and queued for indexing; it can take a short while to appear in Search.',
						(int) $result['accepted'],
						'ziplogger'
					),
					$result['status'],
					(int) $result['accepted']
				),
			);
		} else {
			$notices[] = array( 'error', $result['message'] );
		}
		$this->finish( $notices, $this->tab_of( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); the tab is validated.
	}

	/**
	 * Check that the read key works (asks ZipLogger who it belongs to). Server side; the key is never shown.
	 *
	 * @return void
	 */
	public function handle_test_read() {
		$this->authorize( 'ziplogger_test_read' );
		$result = \ZipLogger\WordPress\Dashboard\Remote::health();
		$meta   = new Meta_Store();
		$notice = array();
		if ( $result['ok'] ) {
			$workspace = isset( $result['data']['workspace'] ) && is_string( $result['data']['workspace'] ) ? sanitize_text_field( $result['data']['workspace'] ) : '';
			$meta->set_json(
				'read_test',
				array(
					'at'        => Clock::time(),
					'ok'        => true,
					'workspace' => $workspace,
				)
			);
			$notice[] = array(
				'success',
				'' !== $workspace
					/* translators: %s: workspace name. */
					? sprintf( __( 'The read key works. It belongs to the workspace "%s".', 'ziplogger' ), $workspace )
					: __( 'The read key works.', 'ziplogger' ),
			);
		} else {
			$meta->set_json(
				'read_test',
				array(
					'at'    => Clock::time(),
					'ok'    => false,
					'error' => $result['error'],
				)
			);
			$notice[] = array( 'error', $result['error'] );
		}
		$this->finish( $notice, $this->tab_of( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); the tab is validated.
	}

	/**
	 * Deliver queued items now.
	 *
	 * @return void
	 */
	public function handle_flush() {
		$this->authorize( 'ziplogger_flush' );
		$report = ( new Worker() )->run(
			array(
				'force'       => true,
				'max_seconds' => 25,
				'max_batches' => 20,
			)
		);

		$notices = array();
		if ( 'disabled' === $report['status'] ) {
			$notices[] = array( 'warning', __( 'Server collection is switched off, so queued items are not being delivered. Turn it on to send them.', 'ziplogger' ) );
		} elseif ( 'not_configured' === $report['status'] ) {
			$notices[] = array( 'error', $report['error'] );
		} else {
			$notices[] = array(
				'' === $report['error'] ? 'success' : 'warning',
				sprintf(
					/* translators: 1: items delivered, 2: items still waiting. */
					__( 'Delivered %1$d items. %2$d still waiting.', 'ziplogger' ),
					$report['events_sent'],
					$report['pending']
				) . ( '' !== $report['error'] ? ' ' . sprintf( /* translators: %s: error detail */ __( 'Last error: %s', 'ziplogger' ), $report['error'] ) : '' ),
			);
		}
		$this->finish( $notices, $this->tab_of( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); the tab is validated.
	}

	/**
	 * Delete every queued item.
	 *
	 * @return void
	 */
	public function handle_clear() {
		$this->authorize( 'ziplogger_clear' );
		$deleted = ( new Queue_Store() )->clear();
		if ( $deleted > 0 ) {
			( new Meta_Store() )->dropped( 'cleared', $deleted );
		}
		$this->finish(
			array(
				array(
					'success',
					sprintf(
						/* translators: %d: number of items. */
						_n( 'Cleared %d queued item.', 'Cleared %d queued items.', $deleted, 'ziplogger' ),
						$deleted
					),
				),
			),
			$this->tab_of( wp_unslash( $_POST ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); the tab is validated.
		);
	}

	/**
	 * Download a redacted diagnostics report.
	 *
	 * @return void
	 */
	public function handle_diagnostics() {
		$this->authorize( 'ziplogger_diagnostics' );
		$report = Tabs\Diagnostics::report();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ziplogger-diagnostics.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download; contains no credentials.
		exit;
	}

	/**
	 * Store notices for the next page view and redirect back to a tab.
	 *
	 * @param array[] $notices List of [ type, message ].
	 * @param string  $tab     Tab to return to.
	 * @return void
	 */
	private function finish( array $notices, $tab = 'overview' ) {
		set_transient( self::notice_key(), $notices, 120 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/**
	 * Per-user transient key for one-shot notices.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'ziplogger_notices_' . get_current_user_id();
	}

	// ---------------------------------------------------------------------------------------------
	// Rendering.
	// ---------------------------------------------------------------------------------------------

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ziplogger' ), '', array( 'response' => 403 ) );
		}
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tabs     = self::tabs();
		$tab      = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		$settings = Settings::get();
		$health   = Health::snapshot();

		echo '<div class="wrap ziplogger-wrap">';
		echo '<h1>' . esc_html__( 'ZipLogger', 'ziplogger' ) . '</h1>';
		$this->render_notices();

		echo '<nav class="nav-tab-wrapper ziplogger-tabs" aria-label="' . esc_attr__( 'ZipLogger sections', 'ziplogger' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				$slug === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div class="ziplogger-panel">';
		switch ( $tab ) {
			case 'connection':
				Tabs\Connection::render( $settings, $health );
				break;
			case 'logs':
				Tabs\Logs::render( $settings, $health );
				break;
			case 'privacy':
				Tabs\Privacy::render( $settings, $health );
				break;
			case 'diagnostics':
				Tabs\Diagnostics::render( $settings, $health );
				break;
			case 'browser':
			case 'analytics':
			case 'replay':
			case 'tracing':
			case 'woocommerce':
				Tabs\Module::render( $tab, $settings, $health );
				break;
			default:
				Tabs\Overview::render( $settings, $health );
		}
		echo '</div></div>';
	}

	/**
	 * One-shot notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notices = get_transient( self::notice_key() );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( self::notice_key() );
		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || ! isset( $notice[0], $notice[1] ) ) {
				continue;
			}
			$type = in_array( $notice[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice[0] : 'info';
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) $notice[1] ) );
		}
	}

	/**
	 * A small POST form for one action button (test, flush, clear ...).
	 *
	 * @param string      $action  admin-post action (also the nonce action).
	 * @param string      $label   Button label.
	 * @param string      $type    Button type: secondary, delete, primary.
	 * @param string      $tab     Tab to return to.
	 * @param string|null $confirm Confirmation text (enforced by admin.js).
	 * @param array       $extra   Extra hidden fields.
	 * @return void
	 */
	public static function action_form( $action, $label, $type, $tab, $confirm = null, array $extra = array() ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( null !== $confirm ? ' data-ziplogger-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		foreach ( $extra as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		wp_nonce_field( $action );
		submit_button( $label, $type, 'submit', false );
		echo '</form>';
	}

	/**
	 * Format a timestamp as "date (n ago)".
	 *
	 * @param int $ts  Unix time.
	 * @param int $now Current time.
	 * @return string
	 */
	public static function ago( $ts, $now ) {
		if ( ! $ts ) {
			return '';
		}
		/* translators: 1: date and time, 2: how long ago. */
		return sprintf( __( '%1$s (%2$s ago)', 'ziplogger' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ), human_time_diff( $ts, $now ) );
	}

	/**
	 * A two-column table row (label, value), escaped.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	public static function row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
