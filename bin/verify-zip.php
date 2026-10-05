<?php
/**
 * Sanity-check a built plugin ZIP the way WordPress will see it.
 *
 * Usage: php bin/verify-zip.php dist/ziplogger.zip
 *
 * Fails (exit 1) when: an entry is outside the one plugin folder (which must be the WordPress.org slug
 * and equal the Text Domain header), a path uses a backslash, the main file is missing, the plugin header and readme.txt disagree on version/requirements, a development file is
 * present, or any PHP file fails a basic sanity check.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$path = isset( $argv[1] ) ? $argv[1] : dirname( __DIR__ ) . '/dist/ziplogger.zip';
$zip  = new ZipArchive();
if ( true !== $zip->open( $path ) ) {
	fwrite( STDERR, "Cannot open {$path}\n" );
	exit( 1 );
}

$slug     = 'ziplogger-error-monitoring-session-replay'; // WordPress.org slug = folder in the ZIP = text domain.
$problems = array();
$names    = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name    = $zip->getNameIndex( $i );
	$names[] = $name;
	if ( false !== strpos( $name, '\\' ) ) {
		$problems[] = "Backslash in entry name: {$name}";
	}
	if ( 0 !== strpos( $name, $slug . '/' ) ) {
		$problems[] = "Entry outside {$slug}/: {$name}";
	}
	if ( preg_match( '#(^|/)(tests?|node_modules|\.git|\.github|composer\.(json|lock)|package(-lock)?\.json|phpunit|phpcs)#i', $name ) ) {
		$problems[] = "Development file shipped: {$name}";
	}
	if ( preg_match( '#\.(zip|tar|gz|env)$#i', $name ) ) {
		$problems[] = "Unexpected archive/secret-like file: {$name}";
	}
}
foreach ( array( $slug . '/ziplogger.php', $slug . '/readme.txt', $slug . '/uninstall.php', $slug . '/index.php' ) as $required ) {
	if ( ! in_array( $required, $names, true ) ) {
		$problems[] = "Missing required file: {$required}";
	}
}

$main   = (string) $zip->getFromName( $slug . '/ziplogger.php' );
$readme = (string) $zip->getFromName( $slug . '/readme.txt' );
$header = static function ( $text, $field ) {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $text, $m ) ? trim( $m[1] ) : '';
};
$version_header = $header( $main, 'Version' );
$stable_tag     = $header( $readme, 'Stable tag' );
$req_wp_main    = $header( $main, 'Requires at least' );
$req_wp_readme  = $header( $readme, 'Requires at least' );
$req_php_main   = $header( $main, 'Requires PHP' );
$req_php_readme = $header( $readme, 'Requires PHP' );
$const_version  = preg_match( "/define\(\s*'ZIPLOGGER_VERSION'\s*,\s*'([^']+)'/", $main, $m ) ? $m[1] : '';

foreach ( array(
	'Version header vs Stable tag' => array( $version_header, $stable_tag ),
	'Version header vs ZIPLOGGER_VERSION' => array( $version_header, $const_version ),
	'Requires at least (header vs readme)' => array( $req_wp_main, $req_wp_readme ),
	'Requires PHP (header vs readme)' => array( $req_php_main, $req_php_readme ),
) as $label => $pair ) {
	if ( '' === $pair[0] || $pair[0] !== $pair[1] ) {
		$problems[] = "{$label}: '{$pair[0]}' != '{$pair[1]}'";
	}
}
if ( $slug !== $header( $main, 'Text Domain' ) ) {
	$problems[] = "Text Domain header is not \"{$slug}\"";
}

$php_files = 0;
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );
	if ( '.php' === substr( $name, -4 ) ) {
		++$php_files;
		$contents = (string) $zip->getFromIndex( $i );
		if ( false === strpos( $contents, '<?php' ) ) {
			$problems[] = "PHP file without an opening tag: {$name}";
		}
		if ( $slug . '/index.php' !== $name && false === strpos( $contents, 'ABSPATH' ) && false === strpos( $contents, 'WP_UNINSTALL_PLUGIN' ) ) {
			$problems[] = "PHP file without a direct-access guard: {$name}";
		}
	}
}

printf( "Entries: %d (PHP files: %d), version %s, requires WP %s / PHP %s\n", $zip->numFiles, $php_files, $version_header, $req_wp_main, $req_php_main );
if ( $problems ) {
	fwrite( STDERR, "PROBLEMS:\n - " . implode( "\n - ", $problems ) . "\n" );
	exit( 1 );
}
echo "ZIP layout OK\n";
