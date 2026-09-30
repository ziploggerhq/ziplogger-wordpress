<?php
/**
 * Plugin Name:       ZipLogger: Error Monitoring & Session Replay
 * Plugin URI:        https://ziplogger.ai/
 * Description:       Server and browser error monitoring, analytics, masked session replay, tracing and WooCommerce events for ZipLogger. Nothing is sent until you add a key and switch a module on.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ZipLogger
 * Author URI:        https://ziplogger.ai/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ziplogger
 * Domain Path:       /languages
 *
 * @package ZipLogger
 */

// Deliberately written in syntax that parses on very old PHP, so the version guard below can run.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ZIPLOGGER_VERSION' ) ) {
	// Two copies of the plugin in wp-content/plugins: the first one loaded wins.
	return;
}

define( 'ZIPLOGGER_VERSION', '1.0.0' );
define( 'ZIPLOGGER_DB_VERSION', 2 );
define( 'ZIPLOGGER_FILE', __FILE__ );
define( 'ZIPLOGGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZIPLOGGER_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: detected PHP version. */
					'ZipLogger needs PHP %1$s or newer. This site runs PHP %2$s, so the plugin is inactive.',
					'7.4',
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once ZIPLOGGER_DIR . 'includes/autoload.php';
require_once ZIPLOGGER_DIR . 'includes/api.php';

register_activation_hook( __FILE__, array( '\ZipLogger\WordPress\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\ZipLogger\WordPress\Lifecycle', 'deactivate' ) );

\ZipLogger\WordPress\Plugin::instance()->boot();
