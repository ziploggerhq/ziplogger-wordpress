<?php
/**
 * Module settings: safe defaults, coercion, and validation of every field type.
 */

use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Settings_Schema;

class Test_Settings_Schema extends ZL_TestCase {

	public function test_every_module_is_off_and_sampling_is_conservative_by_default() {
		$s = Settings::get();
		foreach ( array( 'browser', 'analytics', 'replay', 'tracing', 'woocommerce' ) as $module ) {
			$this->assertFalse( $s[ $module ]['enabled'], "$module must default to off" );
		}
		$this->assertLessThanOrEqual( 10, $s['replay']['sample_rate'] );
		$this->assertLessThanOrEqual( 10, $s['tracing']['sample_rate'] );
		$this->assertLessThanOrEqual( 10, $s['browser']['perf_sample_rate'] );
		$this->assertTrue( $s['replay']['mask_all_text'], 'Text is masked in recordings by default.' );
		$this->assertFalse( $s['analytics']['interactions'], 'Nothing but page views is tracked by default.' );
		$this->assertFalse( $s['analytics']['identify'] );
		$this->assertFalse( $s['tracing']['record_path'] );
		$this->assertFalse( $s['tracing']['db_timing'] );
		$this->assertSame( '', $s['tracing']['propagate_hosts'], 'Trace headers go to nobody by default.' );
		$this->assertSame( 'hold', $s['on_destination_change'] );
		$this->assertContains( 'administrator', $s['replay']['exclude_roles'] );
	}

	public function test_there_is_no_setting_that_unmasks_inputs() {
		$this->assertArrayNotHasKey( 'mask_inputs', Settings::defaults()['replay'], 'Input masking is not configurable: password and payment fields must never be exposed by a setting.' );
		$result = Settings_Schema::validate_module( 'replay', array( 'mask_inputs' => '' ), Settings::get()['replay'] );
		$this->assertArrayNotHasKey( 'mask_inputs', $result['values'] );
	}

	public function test_normalize_coerces_garbage_and_clamps_ranges() {
		$out = Settings_Schema::normalize(
			array(
				'browser' => array( 'enabled' => 'yes', 'perf_sample_rate' => '9999', 'slow_threshold_ms' => 5, 'max_errors_page' => 'lots' ),
				'replay'  => array( 'max_minutes' => 500, 'sample_rate' => -3, 'block_selector' => "a{}\n.ok", 'exclude_roles' => array( 'administrator', 'nonexistent_role', 7 ) ),
				'consent' => array( 'analytics' => 'maybe', 'browser' => 'none' ),
				'on_destination_change' => 'explode',
			)
		);
		$this->assertTrue( $out['browser']['enabled'] );
		$this->assertSame( 100, $out['browser']['perf_sample_rate'] );
		$this->assertSame( 200, $out['browser']['slow_threshold_ms'] );
		$this->assertSame( 20, $out['browser']['max_errors_page'], 'Non-numeric keeps the default.' );
		$this->assertSame( 60, $out['replay']['max_minutes'] );
		$this->assertSame( 0, $out['replay']['sample_rate'] );
		$this->assertSame( '.ok', $out['replay']['block_selector'], 'An unsafe selector line is dropped.' );
		$this->assertSame( array( 'administrator' ), $out['replay']['exclude_roles'] );
		$this->assertSame( 'required', $out['consent']['analytics'], 'Anything but "none" means consent is required.' );
		$this->assertSame( 'none', $out['consent']['browser'] );
		$this->assertSame( 'hold', $out['on_destination_change'] );
	}

	public function test_selector_validation() {
		$r = Settings_Schema::selectors( ".a\n#b > .c\n[data-x=\"y\"]\nbody{color:red}\n<script>\nurl(javascript:x)\n" . str_repeat( 'a', 300 ) . "\n.a" );
		$this->assertSame( ".a\n#b > .c\n[data-x=\"y\"]", $r['clean'], 'Valid selectors kept, duplicates removed.' );
		$this->assertCount( 4, $r['rejected'] );
		$many = Settings_Schema::selectors( implode( "\n", array_map( static function ( $i ) { return '.c' . $i; }, range( 1, 40 ) ) ) );
		$this->assertCount( 20, explode( "\n", $many['clean'] ), 'At most twenty selectors.' );
	}

	public function test_path_pattern_validation() {
		$r = Settings_Schema::path_patterns( "/members/*\n/private\nno-slash\n/ok path\n//x" );
		$this->assertSame( "/members/*\n/private\n//x", $r['clean'] );
		$this->assertSame( array( 'no-slash', '/ok path' ), $r['rejected'] );
	}

	public function test_host_validation() {
		$r = Settings_Schema::hosts( "API.Example.com\n*.svc.example.org\nlocalhost\n10.0.0.1\nhttp://x.com\nexample.com:8443\nnot a host" );
		$this->assertSame( "api.example.com\n*.svc.example.org\nexample.com:8443", $r['clean'] );
		$this->assertCount( 4, $r['rejected'] );
	}

	public function test_roles_are_limited_to_existing_ones() {
		$this->assertSame( array( 'editor' ), Settings_Schema::roles( array( 'editor', 'made-up', 'editor' ) ) );
	}

	public function test_validate_module_reports_and_keeps_previous_values_for_invalid_input() {
		$current = Settings::get()['tracing'];
		$r       = Settings_Schema::validate_module(
			'tracing',
			array(
				'enabled'         => '1',
				'sample_rate'     => 'ten',
				'propagate_hosts' => "good.example.com\nbad host",
				'service_name'    => 'bad name!',
			),
			$current
		);
		$this->assertTrue( $r['values']['enabled'] );
		$this->assertSame( $current['sample_rate'], $r['values']['sample_rate'] );
		$this->assertSame( 'good.example.com', $r['values']['propagate_hosts'] );
		$this->assertSame( $current['service_name'], $r['values']['service_name'] );
		$this->assertCount( 3, $r['errors'] );
		$this->assertFalse( $r['values']['record_path'], 'An unchecked box in a submitted module means off.' );
	}

	public function test_out_of_range_numbers_are_clamped_with_a_notice() {
		$r = Settings_Schema::validate_module( 'replay', array( 'sample_rate' => '250', 'max_minutes' => '90' ), Settings::get()['replay'] );
		$this->assertSame( 100, $r['values']['sample_rate'] );
		$this->assertSame( 60, $r['values']['max_minutes'] );
		$this->assertCount( 2, $r['errors'] );
	}

	public function test_woocommerce_product_identifier_is_restricted() {
		$r = Settings_Schema::validate_module( 'woocommerce', array( 'product_identifier' => 'email' ), Settings::get()['woocommerce'] );
		$this->assertSame( 'id', $r['values']['product_identifier'] );
		$r = Settings_Schema::validate_module( 'woocommerce', array( 'product_identifier' => 'sku' ), Settings::get()['woocommerce'] );
		$this->assertSame( 'sku', $r['values']['product_identifier'] );
	}

	public function test_saving_one_module_never_changes_another() {
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true, 'errors' => false ) ) );
		$current = Settings::get();
		$r       = Settings_Schema::validate_module( 'analytics', array( 'enabled' => '1' ), $current['analytics'] );
		$new     = $current;
		$new['analytics'] = $r['values'];
		Settings::save( $new );
		$this->assertTrue( Settings::get()['browser']['enabled'] );
		$this->assertFalse( Settings::get()['browser']['errors'] );
		$this->assertTrue( Settings::get()['enabled'] );
		$this->assertTrue( Settings::get()['analytics']['enabled'] );
	}

	public function test_saving_a_section_of_the_legacy_form_never_resets_another_section() {
		Settings::save( array( 'enabled' => true, 'source' => 'kept', 'delete_on_uninstall' => true, 'collectors' => array( 'failed_logins' => true ) ) );
		$current = Settings::get();

		$conn = Settings::validate_submission( array( 'source' => 'renamed' ), $current, array( 'connection' ) );
		$this->assertTrue( $conn['settings']['enabled'], 'A checkbox absent from a different section is not "off".' );
		$this->assertTrue( $conn['settings']['collectors']['failed_logins'] );
		$this->assertTrue( $conn['settings']['delete_on_uninstall'] );
		$this->assertSame( 'renamed', $conn['settings']['source'] );

		$logs = Settings::validate_submission( array( 'min_severity' => 'error' ), $current, array( 'logs' ) );
		$this->assertFalse( $logs['settings']['enabled'], 'Inside the submitted section, an absent checkbox means off.' );
		$this->assertSame( 'kept', $logs['settings']['source'] );
		$this->assertTrue( $logs['settings']['delete_on_uninstall'] );
	}
}
