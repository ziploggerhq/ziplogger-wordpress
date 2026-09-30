<?php
/**
 * The WordPress Consent API as evidence of consent (PHP side).
 *
 * This file defines wp_has_consent(), as the WP Consent API plugin does. A function cannot be removed again, and
 * once it exists the plugin treats the API as present for the rest of the process, so these tests are their own
 * suite (--testsuite wp-consent-api) and are not part of the default run. The stub is the documented function
 * signature, not the plugin: the real WP Consent API plugin was not installed.
 */

use ZipLogger\WordPress\Consent;
use ZipLogger\WordPress\Frontend;
use ZipLogger\WordPress\Settings;

require_once dirname( __DIR__ ) . '/phpunit/class-zl-testcase.php';

if ( ! function_exists( 'wp_has_consent' ) ) {
	/**
	 * Stand-in for the WP Consent API's function of the same name. The answers come from a global.
	 *
	 * @param string $category Consent category.
	 * @return bool
	 */
	function wp_has_consent( $category ) {
		return ! empty( $GLOBALS['zl_test_wp_consent'][ $category ] );
	}
}

class Test_WP_Consent_Api extends ZL_TestCase {

	public function set_up() {
		parent::set_up();
		$GLOBALS['zl_test_wp_consent'] = array();
		unset( $_COOKIE[ Consent::COOKIE ] );
		Consent::reset();
	}

	public function tear_down() {
		unset( $GLOBALS['zl_test_wp_consent'], $_COOKIE[ Consent::COOKIE ] );
		remove_all_filters( 'ziplogger_wp_consent_categories' );
		remove_all_filters( 'ziplogger_has_consent' );
		Consent::reset();
		parent::tear_down();
	}

	private function grant( array $categories ) {
		$GLOBALS['zl_test_wp_consent'] = array_fill_keys( $categories, true );
		Consent::reset();
	}

	public function test_the_plugin_registers_as_compatible_with_the_api() {
		$this->assertTrue( apply_filters( 'wp_consent_api_registered_' . plugin_basename( ZIPLOGGER_FILE ), false ) );
	}

	public function test_it_is_used_by_default_and_categories_map_to_the_apis_own_names() {
		$this->assertTrue( Settings::get()['consent']['wp_consent_api'], 'On by default: it only matters when the API plugin is installed.' );

		$this->grant( array( 'statistics' ) );
		$this->assertTrue( Consent::allows( 'analytics' ), 'analytics maps to statistics' );
		$this->assertTrue( Consent::allows( 'replay' ), 'replay maps to statistics' );
		$this->assertSame( 'denied', Consent::state( 'commerce' ), 'commerce maps to functional, which was not granted' );

		$this->grant( array( 'functional' ) );
		$this->assertSame( 'granted', Consent::state( 'commerce' ) );
		$this->assertSame( 'denied', Consent::state( 'analytics' ) );

		$this->grant( array( 'statistics-anonymous' ) );
		$this->assertSame( 'granted', Consent::state( 'browser' ) );
		$this->assertSame( 'denied', Consent::state( 'replay' ) );
	}

	public function test_nothing_granted_means_denied_not_allowed() {
		$this->grant( array() );
		foreach ( array( 'analytics', 'replay' ) as $category ) {
			$this->assertFalse( Consent::allows( $category ), $category );
			$this->assertSame( 'denied', Consent::state( $category ) );
		}
	}

	public function test_withdrawal_in_the_api_is_seen_on_the_next_read() {
		$this->grant( array( 'statistics' ) );
		$this->assertTrue( Consent::allows( 'analytics' ) );
		$this->grant( array() );
		$this->assertFalse( Consent::allows( 'analytics' ) );
	}

	public function test_the_apis_answer_wins_over_the_plugins_own_cookie() {
		$_COOKIE[ Consent::COOKIE ] = 'browser,analytics,replay,commerce';
		$this->grant( array() );
		$this->assertFalse( Consent::allows( 'analytics' ), 'The visitor withdrew it in the banner that speaks for the API; a stale cookie must not override that.' );
	}

	public function test_the_php_filter_wins_over_the_api() {
		$this->grant( array() );
		add_filter(
			'ziplogger_has_consent',
			static function ( $consent, $category ) {
				return 'analytics' === $category ? true : $consent;
			},
			10,
			2
		);
		Consent::reset();
		$this->assertTrue( Consent::allows( 'analytics' ) );
		$this->assertFalse( Consent::allows( 'replay' ) );
	}

	public function test_turning_the_setting_off_ignores_the_api_and_falls_back_to_the_cookie() {
		Settings::save( array( 'consent' => array( 'wp_consent_api' => false ) ) );
		Settings::reset_cache();
		$this->grant( array( 'statistics' ) );
		$this->assertSame( 'unknown', Consent::state( 'analytics' ), 'No cookie and the API is switched off: no evidence.' );
		$_COOKIE[ Consent::COOKIE ] = 'analytics';
		Consent::reset();
		$this->assertSame( 'granted', Consent::state( 'analytics' ) );
	}

	public function test_the_mapping_can_be_changed_and_an_unmapped_category_falls_back_to_the_cookie() {
		add_filter(
			'ziplogger_wp_consent_categories',
			static function ( $map ) {
				$map['analytics'] = 'marketing';
				unset( $map['replay'] );
				return $map;
			}
		);
		$this->grant( array( 'marketing' ) );
		$this->assertSame( 'granted', Consent::state( 'analytics' ) );
		$this->assertSame( 'unknown', Consent::state( 'replay' ), 'Not mapped, no cookie: no evidence.' );
		$_COOKIE[ Consent::COOKIE ] = 'replay';
		Consent::reset();
		$this->assertSame( 'granted', Consent::state( 'replay' ) );
	}

	public function test_the_browser_is_told_to_ask_the_api_with_the_same_mapping() {
		add_filter(
			'ziplogger_wp_consent_categories',
			static function ( $map ) {
				$map['analytics'] = 'marketing';
				return $map;
			}
		);
		$config = Consent::for_browser();
		$this->assertTrue( $config['wpConsentApi'] );
		$this->assertSame( 'marketing', $config['wpCategories']['analytics'] );
		$this->assertSame( 'statistics-anonymous', $config['wpCategories']['browser'] );
	}
}
