<?php
/**
 * Composition root.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together. With collection switched off (the default after activation) this does
 * almost nothing: no handlers are installed and no event is recorded.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Recorder (created on first use).
	 *
	 * @var Recorder|null
	 */
	private $recorder = null;

	/**
	 * Active collectors by name.
	 *
	 * @var object[]
	 */
	private $collectors = array();

	/**
	 * The plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Called once when the plugin file loads.
	 *
	 * @return void
	 */
	public function boot() {
		if ( Schema::maybe_upgrade() && is_multisite() ) {
			// A site that the network activation did not reach (it was created before, or is beyond the first batch).
			Scheduler::ensure_watchdog();
		}
		Transport::register_hooks();
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( Lifecycle::class, 'new_site' ), 200 );
		}

		add_action( 'switch_blog', array( '\ZipLogger\WordPress\Settings', 'reset_cache' ) );
		add_action( Scheduler::HOOK_DELIVER, array( $this, 'run_delivery' ) );
		add_action( Scheduler::HOOK_WATCHDOG, array( $this, 'run_watchdog' ) );

		// The browser script and its per-visitor endpoint. Registering is cheap; each hook checks whether
		// any browser module is actually on before doing anything.
		Frontend::register();
		Context_Endpoint::register();
		// Server request spans and outbound HTTP spans (a no-op unless the tracing module is effective).
		Tracing\Tracer::maybe_start();
		// WooCommerce order, payment and refund events, and the compatibility declarations.
		Commerce\Integration::register();
		// Suggested text for the site's privacy policy (Tools, Privacy).
		Privacy_Policy::register();
		// Tells the WordPress Consent API plugin that this plugin honours its consent signals.
		add_filter( 'wp_consent_api_registered_' . plugin_basename( ZIPLOGGER_FILE ), '__return_true' );

		if ( is_admin() ) {
			$admin = new Admin\Settings_Page();
			$admin->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ziplogger', '\ZipLogger\WordPress\Cli\Command' );
		}

		if ( Settings::is_enabled() ) {
			$this->start_collectors();
		}
	}

	/**
	 * The shared recorder.
	 *
	 * @return Recorder
	 */
	public function recorder() {
		if ( null === $this->recorder ) {
			$this->recorder = new Recorder();
		}
		return $this->recorder;
	}

	/**
	 * Developer API entry point (see ziplogger_log()).
	 *
	 * @param mixed $severity Severity.
	 * @param mixed $message  Message.
	 * @param array $context  Fields.
	 * @return bool
	 */
	public function log( $severity, $message, array $context = array() ) {
		if ( ! Settings::is_enabled() ) {
			return false;
		}
		if ( is_string( $message ) ) {
			$text = $message;
		} elseif ( is_scalar( $message ) || ( is_object( $message ) && method_exists( $message, '__toString' ) ) ) {
			$text = (string) $message;
		} else {
			$text = '[unsupported message type: ' . gettype( $message ) . ']';
		}

		$stack  = null;
		$fields = array();
		if ( isset( $context['exception'] ) && $context['exception'] instanceof \Throwable ) {
			$e      = $context['exception'];
			$stack  = Trace::from_throwable( $e );
			$fields = array(
				'exceptionClass'   => get_class( $e ),
				'exceptionMessage' => $e->getMessage(),
			);
			unset( $context['exception'] );
		}

		return $this->recorder()->record(
			'developer',
			is_string( $severity ) ? $severity : 'info',
			$text,
			array(
				'context' => $context,
				'fields'  => $fields,
				'stack'   => $stack,
			)
		);
	}

	/**
	 * Developer API entry point (see ziplogger_track()): a custom event from the server.
	 *
	 * @param mixed $name       Event name.
	 * @param array $properties Properties.
	 * @param array $options    insert_id, actor.
	 * @return bool
	 */
	public function track( $name, array $properties = array(), array $options = array() ) {
		if ( ! Settings::is_enabled() || ! Modules::effective( 'analytics' ) || ! is_string( $name ) ) {
			return false;
		}
		$identity = array();
		if ( ! isset( $options['actor'] ) || 'system' !== $options['actor'] ) {
			$identity = Commerce\Visitor::for_request();
		}
		if ( ! isset( $identity['anonymousId'] ) && ! isset( $identity['userId'] ) ) {
			$identity = array( 'anonymousId' => Secrets::pseudonym( 'sys', get_current_blog_id() ) );
		}
		$insert_id            = isset( $options['insert_id'] ) && is_scalar( $options['insert_id'] ) ? 'dev:' . substr( preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $options['insert_id'] ), 0, 150 ) : 'dev:' . bin2hex( random_bytes( 12 ) );
		$properties['origin'] = 'server';
		return Server_Events::emit( $name, $properties, $identity, $insert_id, false );
	}

	/**
	 * Install the collectors that are switched on.
	 *
	 * @return void
	 */
	private function start_collectors() {
		$settings = Settings::get();
		$on       = $settings['collectors'];

		if ( $on['php_errors'] ) {
			$this->collectors['php_errors'] = new Collectors\Php_Errors( $this->recorder() );
			$this->collectors['php_errors']->register();
		}
		if ( $on['failed_logins'] ) {
			$this->collectors['failed_logins'] = new Collectors\Failed_Logins( $this->recorder() );
			$this->collectors['failed_logins']->register();
		}
		if ( $on['plugin_theme'] ) {
			$this->collectors['plugin_theme'] = new Collectors\Plugin_Theme( $this->recorder() );
			$this->collectors['plugin_theme']->register();
		}
		if ( $on['updates'] ) {
			$this->collectors['updates'] = new Collectors\Updates( $this->recorder() );
			$this->collectors['updates']->register();
		}
		if ( $on['http_failures'] || $on['http_slow'] ) {
			$this->collectors['http'] = new Collectors\Http_Api( $this->recorder() );
			$this->collectors['http']->register();
		}
	}

	/**
	 * WP-Cron callback: one delivery pass.
	 *
	 * @return void
	 */
	public function run_delivery() {
		Tracing\Tracer::exclude(); // A span for the request that delivers spans would need delivering itself.
		try {
			$worker = new Worker();
			$worker->run();
		} catch ( \Throwable $e ) {
			$this->note_internal_failure( $e );
		}
	}

	/**
	 * A bug or a database hiccup must not lose the queue or spin: remember what happened and try
	 * again later. Nothing in here may depend on code that could have caused the failure (filters,
	 * option reads), so each step is guarded on its own.
	 *
	 * @param \Throwable $e What went wrong.
	 * @return void
	 */
	private function note_internal_failure( \Throwable $e ) {
		$message = 'Internal error (details unavailable).';
		try {
			$redactor = new Redactor(
				array(
					'max_depth'  => 4,
					'max_items'  => 40,
					'max_string' => 1024,
				)
			);
			$message  = $redactor->diagnostic( get_class( $e ) . ': ' . $e->getMessage() );
		} catch ( \Throwable $inner ) {
			unset( $inner );
		}
		try {
			( new Meta_Store() )->set_json(
				Meta_Store::LAST_ERROR,
				array(
					'at'      => Clock::time(),
					'outcome' => 'internal',
					'kind'    => 'exception',
					'status'  => 0,
					'message' => $message,
				)
			);
		} catch ( \Throwable $inner ) {
			unset( $inner );
		}
		try {
			Scheduler::ensure_scheduled( Clock::time() + 300 );
		} catch ( \Throwable $inner ) {
			unset( $inner );
		}
	}

	/**
	 * WP-Cron callback: hourly watchdog.
	 *
	 * @return void
	 */
	public function run_watchdog() {
		Tracing\Tracer::exclude();
		try {
			$worker = new Worker();
			$worker->maintain();
			if ( Settings::is_enabled() && Scheduler::next_delivery() === 0 ) {
				$queue = new Queue_Store();
				if ( $queue->stats()['count'] > 0 ) {
					Scheduler::ensure_scheduled( Clock::time() );
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
