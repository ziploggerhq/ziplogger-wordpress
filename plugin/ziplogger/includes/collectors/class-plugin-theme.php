<?php
/**
 * Plugin and theme activation / deactivation collector.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Collectors;

use ZipLogger\WordPress\Recorder;

defined( 'ABSPATH' ) || exit;

/**
 * Low-volume operational events. They record which extension changed state - never who did it.
 */
final class Plugin_Theme {

	/**
	 * Recorder.
	 *
	 * @var Recorder
	 */
	private $recorder;

	/**
	 * Constructor.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( Recorder $recorder ) {
		$this->recorder = $recorder;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'activated_plugin', array( $this, 'on_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivated' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'on_switch_theme' ), 10, 3 );
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin       Plugin file relative to the plugins directory.
	 * @param bool   $network_wide Whether network-wide.
	 * @return void
	 */
	public function on_activated( $plugin = '', $network_wide = false ) {
		$this->plugin_event( 'plugin_activated', 'Plugin activated', $plugin, $network_wide );
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin       Plugin file relative to the plugins directory.
	 * @param bool   $network_wide Whether network-wide.
	 * @return void
	 */
	public function on_deactivated( $plugin = '', $network_wide = false ) {
		$this->plugin_event( 'plugin_deactivated', 'Plugin deactivated', $plugin, $network_wide );
	}

	/**
	 * The active theme changed. WordPress has no separate "theme deactivated" event: switching is both.
	 *
	 * @param string          $new_name  New theme name.
	 * @param \WP_Theme|mixed $new_theme New theme.
	 * @param \WP_Theme|mixed $old_theme Previous theme (WordPress 4.5+).
	 * @return void
	 */
	public function on_switch_theme( $new_name = '', $new_theme = null, $old_theme = null ) {
		$slug = $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : sanitize_title( (string) $new_name );
		$old  = $old_theme instanceof \WP_Theme ? $old_theme->get_stylesheet() : '';

		$fields = array( 'theme' => $slug );
		if ( '' !== $old ) {
			$fields['previousTheme'] = $old;
		}
		$this->recorder->record(
			'theme_switched',
			'info',
			'Theme switched to ' . $slug,
			array(
				'template' => 'Theme switched to {theme}',
				'fields'   => $fields,
				'exempt'   => true,
			)
		);
	}

	/**
	 * Record a plugin event.
	 *
	 * @param string $type         eventType.
	 * @param string $label        Message prefix.
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network-wide flag.
	 * @return void
	 */
	private function plugin_event( $type, $label, $plugin, $network_wide ) {
		$slug = self::slug( (string) $plugin );
		if ( '' === $slug || basename( dirname( ZIPLOGGER_FILE ) ) === $slug ) {
			return; // This plugin's own state changes are not interesting to itself.
		}
		$this->recorder->record(
			$type,
			'info',
			$label . ': ' . $slug,
			array(
				'template' => $label . ': {plugin}',
				'fields'   => array(
					'plugin'      => $slug,
					'networkWide' => (bool) $network_wide,
				),
				'exempt'   => true,
			)
		);
	}

	/**
	 * A plugin's slug: its directory, or its file name without ".php" for single-file plugins.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @return string
	 */
	public static function slug( $plugin_file ) {
		$plugin_file = str_replace( '\\', '/', $plugin_file );
		$dir         = dirname( $plugin_file );
		$slug        = '.' === $dir ? basename( $plugin_file, '.php' ) : strtok( $plugin_file, '/' );
		return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $slug );
	}
}
