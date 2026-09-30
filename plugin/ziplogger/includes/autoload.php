<?php
/**
 * Minimal class autoloader (no Composer on the customer's server).
 *
 * ZipLogger\WordPress\Foo_Bar            -> includes/class-foo-bar.php
 * ZipLogger\WordPress\Collectors\Php_Errors -> includes/collectors/class-php-errors.php
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'ZipLogger\\WordPress\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$dir   = ZIPLOGGER_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' );
		$file  = $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
