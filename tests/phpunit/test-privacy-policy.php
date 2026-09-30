<?php
/**
 * The suggested privacy-policy text: it describes what is switched on, no more, and is safe to print.
 */

use ZipLogger\WordPress\Privacy_Policy;
use ZipLogger\WordPress\Settings;

class Test_Privacy_Policy extends ZL_TestCase {

	private function configure( array $settings ) {
		Settings::save( $settings );
		Settings::reset_cache();
	}

	public function test_it_is_offered_to_wordpress_on_admin_init() {
		Privacy_Policy::register();
		$this->assertNotFalse( has_action( 'admin_init', array( Privacy_Policy::class, 'add' ) ) );
	}

	public function test_the_plugin_registers_it_at_boot() {
		$this->assertNotFalse( has_action( 'admin_init', array( Privacy_Policy::class, 'add' ) ), 'Plugin::boot registers the policy hook.' );
	}

	public function test_a_site_with_nothing_switched_on_describes_nothing_it_does_not_do() {
		$this->configure( array( 'enabled' => false ) );
		$text = Privacy_Policy::content();
		$this->assertStringContainsString( 'ZipLogger', $text );
		$this->assertStringContainsString( 'https://ziplogger.ai/privacy', $text );
		foreach ( array( 'zl_anon', 'ziplogger_consent', 'recording of the page', 'ziplogger_v', 'trace identifier', 'Server errors' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $text, $absent );
		}
	}

	public function test_each_module_adds_its_own_paragraph() {
		$this->configure( array( 'enabled' => true ) );
		$this->assertStringContainsString( 'Server errors and warnings', Privacy_Policy::content() );

		$this->configure( array( 'enabled' => true, 'browser' => array( 'enabled' => true ) ) );
		$this->assertStringContainsString( 'ziplogger_li', Privacy_Policy::content() );

		$this->configure( array( 'enabled' => true, 'analytics' => array( 'enabled' => true ) ) );
		$text = Privacy_Policy::content();
		$this->assertStringContainsString( 'zl_anon', $text );
		$this->assertStringContainsString( 'ziplogger_consent', $text, 'The consent cookie is described whenever a consent-gated module is on.' );
		$this->assertStringNotContainsString( 'pseudonym of your account', $text, 'Identification is off by default.' );

		$this->configure( array( 'enabled' => true, 'analytics' => array( 'enabled' => true, 'identify' => true ) ) );
		$this->assertStringContainsString( 'pseudonym of your account', Privacy_Policy::content() );

		$this->configure( array( 'enabled' => true, 'replay' => array( 'enabled' => true ) ) );
		$this->assertStringContainsString( 'all text and all form fields are hidden', Privacy_Policy::content() );

		$this->configure( array( 'enabled' => true, 'tracing' => array( 'enabled' => true ) ) );
		$this->assertStringContainsString( 'trace identifier', Privacy_Policy::content() );

		$this->configure( array( 'enabled' => true, 'woocommerce' => array( 'enabled' => true ) ) );
		$text = Privacy_Policy::content();
		$this->assertStringContainsString( 'ziplogger_v', $text );
		$this->assertStringContainsString( 'never sent', $text );
	}

	public function test_the_endpoint_host_is_named_and_the_text_is_well_formed_html() {
		$this->configure( array( 'enabled' => true ) );
		$text = Privacy_Policy::content();
		$this->assertStringContainsString( 'app.ziplogger.ai', $text );
		$this->assertSame( substr_count( $text, '<p>' ), substr_count( $text, '</p>' ) );
		$this->assertSame( $text, wp_kses_post( $text ), 'Nothing in it needs stripping.' );
	}

	public function test_hostile_settings_cannot_inject_markup() {
		$this->configure( array( 'enabled' => true, 'source' => '<script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script>', Privacy_Policy::content() );
	}
}
