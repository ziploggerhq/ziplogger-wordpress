<?php
/**
 * Collectors: PHP errors (handler compatibility, duplicates, fatals), failed logins, plugin/theme
 * changes, updates and outbound HTTP.
 */

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Collectors\Failed_Logins;
use ZipLogger\WordPress\Collectors\Http_Api;
use ZipLogger\WordPress\Collectors\Php_Errors;
use ZipLogger\WordPress\Collectors\Plugin_Theme;
use ZipLogger\WordPress\Collectors\Updates;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Plugin;
use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Transport;

class Test_Collectors extends ZL_TestCase {

	/**
	 * Handlers installed by a test, unwound in tear_down.
	 *
	 * @var int
	 */
	private $handlers_to_restore = 0;

	private $prev_calls = array();

	public function tear_down() {
		while ( $this->handlers_to_restore > 0 ) {
			restore_error_handler();
			--$this->handlers_to_restore;
		}
		error_reporting( E_ALL );
		parent::tear_down();
	}

	/**
	 * Install a controlled "previous" handler, then our collector on top of it.
	 */
	private function install( $prev_return = true, array $settings = array() ) {
		$this->enable( $settings );
		$this->prev_calls = array();
		set_error_handler(
			function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( $prev_return ) {
				$this->prev_calls[] = array( $errno, $errstr );
				return $prev_return;
			}
		);
		++$this->handlers_to_restore;

		$recorder  = new Recorder();
		$collector = new Php_Errors( $recorder );
		$collector->register();
		++$this->handlers_to_restore; // The handler the collector installed.
		return array( $collector, $recorder );
	}

	// ---------------------------------------------------------------------------------------------
	// PHP errors.
	// ---------------------------------------------------------------------------------------------

	public function test_the_previous_handler_still_runs_and_its_result_is_returned() {
		list( $collector ) = $this->install( true );
		$this->assertTrue( $collector->handle_error( E_WARNING, 'x', 'f.php', 3 ) );
		$this->assertSame( array( array( E_WARNING, 'x' ) ), $this->prev_calls );
		restore_error_handler(); // Ours.
		--$this->handlers_to_restore;

		list( $collector2 ) = $this->install( false );
		$this->assertFalse( $collector2->handle_error( E_WARNING, 'x', 'f.php', 3 ), 'false lets PHP\'s own handling continue.' );
	}

	public function test_without_a_previous_handler_php_standard_handling_is_left_alone() {
		$this->enable();
		$recorder  = new Recorder();
		$collector = new Php_Errors( $recorder );
		// Not registered: exercise handle_error directly with no previous handler.
		$this->assertFalse( $collector->handle_error( E_WARNING, 'x', 'f.php', 3 ) );
	}

	public function test_a_real_triggered_error_reaches_both_the_collector_and_the_previous_handler() {
		list( , $recorder ) = $this->install( true );
		trigger_error( 'Custom warning', E_USER_WARNING );
		$this->assertCount( 1, $this->prev_calls );
		$this->assertSame( 'Custom warning', $this->prev_calls[0][1] );
		$this->assertSame( 1, $recorder->buffered() );
	}

	public function test_warnings_are_recorded_with_context_and_no_absolute_paths() {
		list( , $recorder ) = $this->install();
		trigger_error( 'Custom warning', E_USER_WARNING );
		$recorder->flush();

		$event = $this->queued_events()[0];
		$this->assertSame( 'warn', $event['severity'] );
		$this->assertStringStartsWith( 'PHP Warning: Custom warning in ', $event['message'] );
		$this->assertSame( 'php_error', $event['fields']['eventType'] );
		$this->assertSame( 'E_USER_WARNING', $event['fields']['phpErrorType'] );
		$this->assertSame( E_USER_WARNING, $event['fields']['phpErrorCode'] );
		$this->assertIsInt( $event['fields']['line'] );
		$this->assertStringNotContainsString( '/opt/ci', json_encode( $event ), 'Server paths must not leave the site.' );
		$this->assertStringContainsString( 'test-collectors.php', $event['fields']['file'] );
		$this->assertArrayHasKey( 'stackTrace', $event );
		$this->assertMatchesRegularExpression( '/test-collectors\.php:\d+/', $event['stackTrace'] );
		$this->assertStringNotContainsString( 'Array(', $event['stackTrace'] );
	}

	public function test_notices_and_deprecations_follow_the_severity_threshold() {
		list( , $recorder ) = $this->install();
		trigger_error( 'a notice', E_USER_NOTICE );
		trigger_error( 'a deprecation', E_USER_DEPRECATED );
		$this->assertSame( 0, $recorder->buffered(), 'The default threshold is "warn".' );

		\ZipLogger\WordPress\Settings::save( array( 'enabled' => true, 'min_severity' => 'debug' ) );
		trigger_error( 'a notice', E_USER_NOTICE );
		trigger_error( 'a deprecation', E_USER_DEPRECATED );
		$this->assertSame( 2, $recorder->buffered() );
		$recorder->flush();
		$this->assertSame( array( 'info', 'debug' ), array_column( $this->queued_events(), 'severity' ) );
	}

	public function test_errors_hidden_by_error_reporting_are_not_recorded_but_still_reach_the_previous_handler() {
		list( , $recorder ) = $this->install();
		error_reporting( E_ALL & ~E_USER_WARNING );
		trigger_error( 'hidden by error_reporting', E_USER_WARNING );
		$this->assertSame( 0, $recorder->buffered() );
		$this->assertCount( 1, $this->prev_calls );
	}

	public function test_the_at_operator_suppresses_recording() {
		list( , $recorder ) = $this->install();
		@trigger_error( 'suppressed with @', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->assertSame( 0, $recorder->buffered() );
		trigger_error( 'not suppressed', E_USER_WARNING );
		$this->assertSame( 1, $recorder->buffered() );
	}

	public function test_a_repeated_warning_is_reported_once_with_a_count() {
		list( , $recorder ) = $this->install();
		for ( $i = 0; $i < 5; $i++ ) {
			trigger_error( 'Same warning in a loop', E_USER_WARNING ); // One file:line, five calls.
		}
		$this->assertSame( 1, $recorder->buffered() );
		$recorder->flush();
		$this->assertSame( 5, $this->queued_events()[0]['fields']['occurrencesInRequest'] );
	}

	public function test_errors_raised_while_recording_do_not_recurse() {
		list( , $recorder ) = $this->install();
		add_filter(
			'ziplogger_event',
			static function ( $spec ) {
				@trigger_error( 'inner error from a filter', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $spec;
			}
		);
		trigger_error( 'outer', E_USER_WARNING );
		$this->assertSame( 1, $recorder->buffered(), 'The inner error is not recorded, and nothing loops.' );
	}

	public function test_a_failure_while_recording_never_reaches_php() {
		list( $collector ) = $this->install();
		add_filter(
			'ziplogger_event',
			static function () {
				throw new RuntimeException( 'logging bug' );
			}
		);
		$this->assertTrue( $collector->handle_error( E_USER_WARNING, 'x', 'f.php', 1 ), 'The previous handler\'s result is still returned.' );
	}

	public function test_fatal_errors_are_captured_once_from_error_get_last() {
		list( $collector, $recorder ) = $this->install();
		$fatal = array(
			'type'    => E_ERROR,
			'message' => "Uncaught Exception: boom in /var/www/html/wp-content/plugins/x/x.php:12\nStack trace:\n#0 /var/www/html/index.php(3): f('secret-argument')\n#1 {main}\n  thrown",
			'file'    => '/var/www/html/wp-content/plugins/x/x.php',
			'line'    => 12,
		);
		Php_Errors::$last_error_source = static function () use ( $fatal ) {
			return $fatal;
		};

		$this->assertTrue( $collector->capture_last_error() );
		$this->assertFalse( $collector->capture_last_error(), 'The same fatal error must not be reported twice.' );
		$recorder->flush();

		$events = $this->queued_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'fatal', $events[0]['severity'] );
		$this->assertSame( 'php_exception', $events[0]['fields']['eventType'], 'An uncaught exception reported by PHP as a fatal error keeps its identity.' );
		$this->assertSame( 'Exception', $events[0]['fields']['exceptionClass'] );
		$this->assertStringStartsWith( 'PHP Fatal error: Uncaught Exception: boom', $events[0]['message'] );
		$this->assertStringNotContainsString( 'secret-argument', json_encode( $events[0] ) );
		$this->assertStringNotContainsString( '/var/www/html', json_encode( $events[0] ) );
		$this->assertStringContainsString( 'f()', $events[0]['stackTrace'] );
	}

	public function test_non_fatal_last_errors_are_ignored_at_shutdown() {
		list( $collector ) = $this->install();
		Php_Errors::$last_error_source = static function () {
			return array( 'type' => E_WARNING, 'message' => 'just a warning', 'file' => 'f.php', 'line' => 1 );
		};
		$this->assertFalse( $collector->capture_last_error() );
		Php_Errors::$last_error_source = static function () {
			return null;
		};
		$this->assertFalse( $collector->capture_last_error() );
	}

	public function test_a_fatal_type_error_already_reported_by_the_handler_is_not_reported_again_at_shutdown() {
		list( $collector, $recorder ) = $this->install();
		$collector->handle_error( E_USER_ERROR, 'custom fatal', 'f.php', 9 );
		Php_Errors::$last_error_source = static function () {
			return array( 'type' => E_USER_ERROR, 'message' => 'custom fatal', 'file' => 'f.php', 'line' => 9 );
		};
		$this->assertFalse( $collector->capture_last_error() );
		$this->assertSame( 1, $recorder->buffered() );
	}

	public function test_an_uncaught_exception_is_reported_once_even_though_php_then_raises_a_fatal() {
		list( $collector, $recorder ) = $this->install();
		$handled   = array();
		$previous  = static function ( $e ) use ( &$handled ) {
			$handled[] = $e->getMessage();
		};
		$collector_with_previous = new Php_Errors( $recorder );
		$rp = new ReflectionProperty( $collector_with_previous, 'previous_exception' );
		$rp->setAccessible( true );
		$rp->setValue( $collector_with_previous, $previous );

		$e = new RuntimeException( 'uncaught boom' );
		$collector_with_previous->handle_exception( $e );
		$this->assertSame( array( 'uncaught boom' ), $handled, 'A previously installed exception handler still gets the exception.' );

		Php_Errors::$last_error_source = static function () use ( $e ) {
			return array(
				'type'    => E_ERROR,
				'message' => 'Uncaught RuntimeException: uncaught boom in ' . $e->getFile() . ':' . $e->getLine() . "\nStack trace:\n#0 {main}\n  thrown",
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			);
		};
		$this->assertFalse( $collector_with_previous->capture_last_error(), 'The follow-up fatal error is a duplicate.' );
		$recorder->flush();
		$events = $this->queued_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'php_exception', $events[0]['fields']['eventType'] );
		$this->assertSame( 'RuntimeException', $events[0]['fields']['exceptionClass'] );
		unset( $collector );
	}

	public function test_without_an_existing_exception_handler_none_is_installed_so_php_behaves_normally() {
		$this->enable();
		$before = set_exception_handler( null );
		restore_exception_handler();
		$collector = new Php_Errors( new Recorder() );
		$collector->register();
		++$this->handlers_to_restore; // The error handler.

		$after = set_exception_handler( null );
		restore_exception_handler();
		$this->assertSame( $before, $after, 'No exception handler may be left behind: PHP must raise its normal fatal error.' );
	}

	public function test_with_an_existing_exception_handler_ours_is_chained_in_front_of_it() {
		$this->enable();
		$seen    = array();
		$mine    = static function ( $e ) use ( &$seen ) {
			$seen[] = $e->getMessage();
		};
		set_exception_handler( $mine );
		$collector = new Php_Errors( new Recorder() );
		$collector->register();
		++$this->handlers_to_restore;

		$current = set_exception_handler( null );
		restore_exception_handler();
		$this->assertIsArray( $current );
		$this->assertInstanceOf( Php_Errors::class, $current[0], 'Ours is the active handler...' );
		$current[0]->handle_exception( new RuntimeException( 'chained' ) );
		$this->assertSame( array( 'chained' ), $seen, '...and it hands over to the previous one.' );

		restore_exception_handler(); // Ours.
		restore_exception_handler(); // Theirs.
	}

	public function test_handle_exception_without_a_previous_handler_logs_and_returns() {
		$this->enable();
		$recorder  = new Recorder();
		$collector = new Php_Errors( $recorder );
		$collector->handle_exception( new RuntimeException( 'direct call' ) );
		$this->assertSame( 1, $this->queue_count(), 'Recorded and persisted immediately.' );
		$this->assertSame( 'RuntimeException', $this->queued_events()[0]['fields']['exceptionClass'] );
	}

	public function test_php_fatal_for_an_uncaught_exception_keeps_the_exception_class() {
		list( $collector, $recorder ) = $this->install();
		Php_Errors::$last_error_source = static function () {
			return array(
				'type'    => E_ERROR,
				'message' => "Uncaught My\Custom\ThingException: it broke in /var/www/html/wp-content/plugins/x/x.php:9\nStack trace:\n#0 {main}\n  thrown",
				'file'    => '/var/www/html/wp-content/plugins/x/x.php',
				'line'    => 9,
			);
		};
		$collector->capture_last_error();
		$recorder->flush();
		$event = $this->queued_events()[0];
		$this->assertSame( 'php_exception', $event['fields']['eventType'] );
		$this->assertSame( 'My\Custom\ThingException', $event['fields']['exceptionClass'] );
		$this->assertSame( 'fatal', $event['severity'] );
	}
	public function test_the_wp_die_wrapper_records_a_fatal_before_wordpress_ends_the_request() {
		list( $collector ) = $this->install();
		$called = array();
		$orig   = static function ( $message ) use ( &$called ) {
			$called[] = $message;
		};
		Php_Errors::$last_error_source = static function () {
			return array( 'type' => E_ERROR, 'message' => 'Allowed memory size of 1 bytes exhausted', 'file' => '/var/www/html/wp-content/plugins/x/x.php', 'line' => 5 );
		};

		$wrapped = $collector->wrap_die_handler( $orig );
		$wrapped( 'The site is experiencing technical difficulties.', '', array() );

		$this->assertSame( array( 'The site is experiencing technical difficulties.' ), $called, 'The original wp_die handler still runs.' );
		$this->assertSame( 1, $this->queue_count(), 'The event is persisted immediately: wp_die() ends the request without running later shutdown functions.' );
		$this->assertSame( 'fatal', $this->queued_events()[0]['severity'] );
		$this->assertSame( $orig, $collector->wrap_die_handler( 'not_callable_xyz' ) === 'not_callable_xyz' ? $orig : $orig );
	}

	public function test_wp_die_for_ordinary_reasons_records_nothing() {
		list( $collector ) = $this->install();
		Php_Errors::$last_error_source = static function () {
			return null;
		};
		$wrapped = $collector->wrap_die_handler( static function () {} );
		$wrapped( 'You are not allowed to do that.' );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_the_collector_is_not_installed_while_collection_is_off() {
		$current = set_error_handler( static function () {
			return false;
		} );
		restore_error_handler();
		$this->assertFalse( is_array( $current ) && $current[0] instanceof Php_Errors );
		$this->assertFalse( Plugin::instance() === null );
	}

	public function test_component_detection() {
		$c = new Php_Errors( new Recorder() );
		$this->assertSame( array( 'component' => 'plugin', 'componentSlug' => 'akismet' ), $c->component( WP_PLUGIN_DIR . '/akismet/akismet.php' ) );
		$this->assertSame( array( 'component' => 'plugin', 'componentSlug' => 'hello' ), $c->component( WP_PLUGIN_DIR . '/hello.php' ) );
		$this->assertSame( array( 'component' => 'mu-plugin', 'componentSlug' => 'loader' ), $c->component( WPMU_PLUGIN_DIR . '/loader.php' ) );
		$this->assertSame( array( 'component' => 'core' ), $c->component( ABSPATH . 'wp-includes/load.php' ) );
		$this->assertSame( array( 'component' => 'core' ), $c->component( ABSPATH . 'wp-admin/admin.php' ) );
		$this->assertSame( array( 'component' => 'other' ), $c->component( '/somewhere/else.php' ) );
		$this->assertSame( array( 'component' => 'plugin', 'componentSlug' => 'win' ), $c->component( str_replace( '/', '\\', WP_PLUGIN_DIR ) . '\\win\\w.php' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Failed logins.
	// ---------------------------------------------------------------------------------------------

	public function test_failed_logins_record_the_reason_but_never_who_tried() {
		$this->enable( array( 'collectors' => array( 'failed_logins' => true ) ) );
		$recorder  = new Recorder();
		$collector = new Failed_Logins( $recorder );
		$collector->register();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

		try {
			$result = wp_signon( array( 'user_login' => 'ghost.user@example.com', 'user_password' => 'sup3r-secret-pw', 'remember' => false ), false );
			$this->assertWPError( $result );
		} finally {
			remove_action( 'wp_login_failed', array( $collector, 'on_failed' ), 10 );
			unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		}

		$recorder->flush();
		$stored = implode( "\n", $this->queued_payloads() );
		$this->assertNotSame( '', $stored );
		foreach ( array( 'ghost.user', 'example.com', 'sup3r-secret-pw', '203.0.113.99', '198.51.100.7' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $stored, "$forbidden must never be collected" );
		}
		$event = $this->queued_events()[0];
		$this->assertSame( 'login_failed', $event['fields']['eventType'] );
		$this->assertSame( 'warn', $event['severity'] );
		$this->assertContains( $event['fields']['errorCode'], array( 'invalid_username', 'invalid_email', 'incorrect_password' ) );
		$this->assertSame( 'login', $event['fields']['channel'] );
	}

	public function test_failed_login_bursts_are_collapsed() {
		$this->enable( array( 'collectors' => array( 'failed_logins' => true ) ) );
		$recorder  = new Recorder();
		$collector = new Failed_Logins( $recorder );
		for ( $i = 0; $i < 20; $i++ ) {
			$collector->on_failed( 'user' . $i, new WP_Error( 'incorrect_password', 'x' ) );
		}
		$collector->on_failed( 'someone', new WP_Error( 'invalid_username', 'y' ) );
		$this->assertSame( 2, $recorder->buffered(), 'One event per error code, however many attempts.' );
		$recorder->flush();
		$this->assertSame( 20, $this->queued_events()[0]['fields']['occurrencesInRequest'] );
	}

	public function test_failed_logins_ignore_the_severity_threshold_because_they_have_their_own_switch() {
		$this->enable( array( 'min_severity' => 'fatal' ) );
		$recorder = new Recorder();
		( new Failed_Logins( $recorder ) )->on_failed( 'u', new WP_Error( 'incorrect_password', 'x' ) );
		$this->assertSame( 1, $recorder->buffered() );
	}

	public function test_failed_login_without_an_error_object_is_still_safe() {
		$this->enable();
		$recorder = new Recorder();
		( new Failed_Logins( $recorder ) )->on_failed( 'u' );
		$recorder->flush();
		$this->assertSame( 'unknown', $this->queued_events()[0]['fields']['errorCode'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Plugin / theme lifecycle.
	// ---------------------------------------------------------------------------------------------

	public function test_plugin_activation_and_deactivation_are_recorded_by_slug_only() {
		$this->enable( array( 'min_severity' => 'error' ) );
		$recorder  = new Recorder();
		$collector = new Plugin_Theme( $recorder );
		$collector->on_activated( 'akismet/akismet.php', false );
		$collector->on_deactivated( 'hello.php', true );
		$collector->on_activated( 'ziplogger/ziplogger.php', false );
		$this->assertSame( 2, $recorder->buffered(), 'This plugin\'s own changes are skipped; info events ignore the threshold.' );
		$recorder->flush();

		$events = $this->queued_events();
		$this->assertSame( 'Plugin activated: akismet', $events[0]['message'] );
		$this->assertSame( 'plugin_activated', $events[0]['fields']['eventType'] );
		$this->assertSame( 'akismet', $events[0]['fields']['plugin'] );
		$this->assertFalse( $events[0]['fields']['networkWide'] );
		$this->assertSame( 'Plugin activated: {plugin}', $events[0]['fields']['messageTemplate'] );
		$this->assertSame( 'Plugin deactivated: hello', $events[1]['message'] );
		$this->assertTrue( $events[1]['fields']['networkWide'] );
		$this->assertSame( 'info', $events[0]['severity'] );
		$this->assertStringNotContainsString( 'user', strtolower( wp_json_encode( array_keys( $events[0]['fields'] ) ) ) );
	}

	public function test_theme_switches_are_recorded() {
		$this->enable();
		$recorder  = new Recorder();
		$collector = new Plugin_Theme( $recorder );
		$theme     = wp_get_theme( WP_DEFAULT_THEME );
		$collector->on_switch_theme( $theme->get( 'Name' ), $theme, $theme );
		$collector->on_switch_theme( 'My Custom Theme' );
		$recorder->flush();
		$events = $this->queued_events();
		$this->assertSame( 'theme_switched', $events[0]['fields']['eventType'] );
		$this->assertSame( $theme->get_stylesheet(), $events[0]['fields']['theme'] );
		$this->assertSame( 'my-custom-theme', $events[1]['fields']['theme'] );
	}

	public function test_real_wordpress_hooks_drive_the_lifecycle_collector() {
		$this->enable();
		$recorder  = new Recorder();
		$collector = new Plugin_Theme( $recorder );
		$collector->register();
		try {
			do_action( 'activated_plugin', 'wp-hooked/wp-hooked.php', false );
			do_action( 'deactivated_plugin', 'wp-hooked/wp-hooked.php', false );
		} finally {
			remove_action( 'activated_plugin', array( $collector, 'on_activated' ), 10 );
			remove_action( 'deactivated_plugin', array( $collector, 'on_deactivated' ), 10 );
			remove_action( 'switch_theme', array( $collector, 'on_switch_theme' ), 10 );
		}
		$this->assertSame( 2, $recorder->buffered() );
	}

	// ---------------------------------------------------------------------------------------------
	// Updates.
	// ---------------------------------------------------------------------------------------------

	public function test_update_success_is_recorded_for_each_kind() {
		$this->enable();
		$recorder  = new Recorder();
		$collector = new Updates( $recorder );
		$collector->on_complete( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'a/a.php', 'b.php' ) ) );
		$collector->on_complete( null, array( 'type' => 'theme', 'action' => 'update', 'themes' => array( 'twentytwentyfive' ) ) );
		$collector->on_complete( null, array( 'type' => 'core', 'action' => 'update' ) );
		$collector->on_complete( null, array( 'type' => 'translation', 'action' => 'update', 'translations' => array( array( 'type' => 'plugin', 'slug' => 'a', 'language' => 'de_DE' ) ) ) );
		$collector->on_complete( null, array( 'type' => 'bogus' ) );
		$collector->on_complete( null, array() );
		$recorder->flush();

		$events = $this->queued_events();
		$this->assertCount( 4, $events );
		$this->assertSame( array( 'a', 'b' ), $events[0]['fields']['items'] );
		$this->assertSame( 'plugin', $events[0]['fields']['updateType'] );
		$this->assertSame( 'success', $events[0]['fields']['result'] );
		$this->assertSame( array( 'twentytwentyfive' ), $events[1]['fields']['items'] );
		$this->assertSame( 'WordPress core update completed', $events[2]['message'] );
		$this->assertSame( array( 'plugin:a' ), $events[3]['fields']['items'] );
		$this->assertSame( 'update_completed', $events[0]['fields']['eventType'] );
	}

	public function test_install_stage_failures_are_recorded_and_the_filter_result_is_untouched() {
		$this->enable();
		$recorder  = new Recorder();
		$collector = new Updates( $recorder );
		$error     = new WP_Error( 'folder_exists', 'Destination folder already exists. /var/www/html/wp-content/plugins/x' );

		$this->assertSame( $error, $collector->on_install_result( $error, array( 'type' => 'plugin', 'plugin' => 'x/x.php' ) ), 'This is a filter: the value must pass through unchanged.' );
		$this->assertSame( array( 'ok' ), $collector->on_install_result( array( 'ok' ), array( 'type' => 'plugin' ) ) );
		$recorder->flush();

		$events = $this->queued_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['severity'] );
		$this->assertSame( 'update_failed', $events[0]['fields']['eventType'] );
		$this->assertSame( 'folder_exists', $events[0]['fields']['errorCode'] );
		$this->assertSame( array( 'x' ), $events[0]['fields']['items'] );
		$this->assertStringNotContainsString( '/var/www/html', wp_json_encode( $events[0] ) );
	}

	public function test_automatic_update_failures_are_recorded_and_collapse_with_the_install_stage_report() {
		$this->enable();
		$recorder  = new Recorder();
		$collector = new Updates( $recorder );
		$collector->on_install_result( new WP_Error( 'download_failed', 'Download failed.' ), array( 'type' => 'plugin', 'plugin' => 'x/x.php' ) );
		$collector->on_automatic_complete(
			array(
				'plugin' => array(
					(object) array( 'item' => (object) array( 'plugin' => 'x/x.php' ), 'result' => new WP_Error( 'download_failed', 'Download failed.' ) ),
					(object) array( 'item' => (object) array( 'plugin' => 'ok/ok.php' ), 'result' => true ),
				),
			)
		);
		$collector->on_automatic_complete( 'garbage' );
		$this->assertSame( 1, $recorder->buffered(), 'The same failure reported by two hooks becomes one event.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Outbound HTTP.
	// ---------------------------------------------------------------------------------------------

	private function http( array $collectors = array( 'http_failures' => true, 'http_slow' => true ) ) {
		$this->enable( array( 'collectors' => $collectors, 'slow_http_seconds' => 5 ) );
		$recorder = new Recorder();
		return array( new Http_Api( $recorder ), $recorder );
	}

	public function test_a_failed_request_records_host_and_reason_but_not_the_path_or_query() {
		list( $c, $recorder ) = $this->http();
		$c->observe( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), 'response', 'Requests', array( 'blocking' => true, 'method' => 'POST' ), 'https://hooks.example.com/services/T000/B000/SECRET-TOKEN?token=abc&x=1' );
		$recorder->flush();
		$stored = implode( '', $this->queued_payloads() );
		$this->assertStringNotContainsString( 'SECRET-TOKEN', $stored );
		$this->assertStringNotContainsString( 'token=abc', $stored );
		$this->assertStringNotContainsString( '/services/', $stored );
		$event = $this->queued_events()[0];
		$this->assertSame( 'http_failure', $event['fields']['eventType'] );
		$this->assertSame( 'hooks.example.com', $event['fields']['host'] );
		$this->assertSame( 'POST', $event['fields']['method'] );
		$this->assertSame( 'http_request_failed', $event['fields']['errorCode'] );
	}

	public function test_server_errors_are_failures_but_client_errors_are_not() {
		list( $c, $recorder ) = $this->http();
		$c->observe( self::response( 502, '' ), 'response', 'Requests', array( 'blocking' => true ), 'https://api.example.com/x' );
		$c->observe( self::response( 404, '' ), 'response', 'Requests', array( 'blocking' => true ), 'https://api.example.org/x' );
		$c->observe( self::response( 200, '' ), 'response', 'Requests', array( 'blocking' => true ), 'https://api.example.net/x' );
		$this->assertSame( 1, $recorder->buffered() );
		$recorder->flush();
		$this->assertSame( 502, $this->queued_events()[0]['fields']['statusCode'] );
	}

	public function test_slow_requests_are_recorded_above_the_threshold() {
		list( $c, $recorder ) = $this->http();
		$slow = array( 'blocking' => true, 'method' => 'GET', Http_Api::STAMP => microtime( true ) - 6 );
		$fast = array( 'blocking' => true, 'method' => 'GET', Http_Api::STAMP => microtime( true ) - 1 );
		$c->observe( self::response( 200, '' ), 'response', 'Requests', $slow, 'https://slow.example.com/a' );
		$c->observe( self::response( 200, '' ), 'response', 'Requests', $fast, 'https://fast.example.com/a' );
		$this->assertSame( 1, $recorder->buffered() );
		$recorder->flush();
		$event = $this->queued_events()[0];
		$this->assertSame( 'http_slow', $event['fields']['eventType'] );
		$this->assertGreaterThanOrEqual( 5900, $event['fields']['durationMs'] );
	}

	public function test_each_http_collector_respects_its_own_switch() {
		list( $c, $recorder ) = $this->http( array( 'http_failures' => false, 'http_slow' => true ) );
		$c->observe( new WP_Error( 'x', 'y' ), 'response', 'Requests', array( 'blocking' => true ), 'https://a.example.com/' );
		$this->assertSame( 0, $recorder->buffered() );

		list( $c, $recorder ) = $this->http( array( 'http_failures' => true, 'http_slow' => false ) );
		$c->observe( self::response( 200, '' ), 'response', 'Requests', array( 'blocking' => true, Http_Api::STAMP => microtime( true ) - 30 ), 'https://a.example.com/' );
		$this->assertSame( 0, $recorder->buffered() );
	}

	public function test_zipLoggers_own_requests_are_never_observed() {
		list( $c, $recorder ) = $this->http();
		$error = new WP_Error( 'http_request_failed', 'cURL error 7' );

		// Flagged by the transport.
		$c->observe( $error, 'response', 'Requests', array( 'blocking' => true, 'ziplogger_transport' => true ), 'https://app.ziplogger.ai/ingest/v1/logs' );
		// Same host, even without the flag (defence in depth).
		$c->observe( $error, 'response', 'Requests', array( 'blocking' => true ), 'https://app.ziplogger.ai/ingest/v1/events' );
		// A configured custom endpoint host is excluded as well.
		\ZipLogger\WordPress\Settings::save( array( 'enabled' => true, 'endpoint' => 'https://logs.example.org', 'collectors' => array( 'http_failures' => true ) ) );
		$c->observe( $error, 'response', 'Requests', array( 'blocking' => true ), 'https://logs.example.org/anything' );
		$this->assertSame( 0, $recorder->buffered() );
	}

	public function test_a_failing_delivery_never_creates_an_http_failure_event() {
		list( $c, $recorder ) = $this->http();
		add_action(
			'http_api_debug',
			array( $c, 'observe' ),
			10,
			5
		);
		// While the transport's request is in flight, feed the collector the failure it would see.
		$this->responses = array(
			function ( $args, $url ) use ( $c ) {
				$c->observe( new WP_Error( 'http_request_failed', 'cURL error 7' ), 'response', 'Requests', array( 'blocking' => true ), $url );
				return new WP_Error( 'http_request_failed', 'cURL error 7' );
			},
		);
		( new Transport() )->send( '[{}]', 'zlwp-x', 1, self::KEY, self::ENDPOINT );
		remove_action( 'http_api_debug', array( $c, 'observe' ), 10 );
		$this->assertSame( 0, $recorder->buffered() );
	}

	public function test_non_blocking_and_non_response_contexts_are_ignored() {
		list( $c, $recorder ) = $this->http();
		$error = new WP_Error( 'x', 'y' );
		$c->observe( $error, 'response', 'Requests', array( 'blocking' => false ), 'https://a.example.com/' );
		$c->observe( $error, 'request', 'Requests', array( 'blocking' => true ), 'https://a.example.com/' );
		$c->observe( $error, 'response', 'Requests', array( 'blocking' => true ), 'not a url' );
		$this->assertSame( 0, $recorder->buffered() );
	}

	public function test_request_start_is_stamped_except_on_zipLoggers_own_requests() {
		list( $c ) = $this->http();
		$this->assertArrayHasKey( Http_Api::STAMP, $c->stamp( array( 'method' => 'GET' ), 'https://a.example.com' ) );
		$this->assertArrayNotHasKey( Http_Api::STAMP, $c->stamp( array( 'ziplogger_transport' => true ), 'https://a.example.com' ) );
	}
}
