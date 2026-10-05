<?php
/**
 * Build the installable plugin ZIP, reproducibly.
 *
 * Usage: php bin/build-zip.php [<plugin dir>] [<output zip>]
 *   plugin dir  default: plugin/ziplogger  (relative to the repository directory of this script)
 *   output zip  default: dist/ziplogger.zip
 *
 * The folder inside the ZIP is the plugin's WordPress.org slug, which is also its text domain: the
 * directory's upload check reads the slug from that folder name and compares it with the text domain.
 *
 * Reproducible: entries are sorted, timestamps are fixed (SOURCE_DATE_EPOCH, or the newest mtime
 * in the plugin directory rounded down to the day is NOT used - set SOURCE_DATE_EPOCH for a stable
 * hash), permissions are normalised and forward slashes are always used. Building the same tree twice
 * gives byte-identical archives. Needs the PHP zip extension (ZipArchive).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension is required.\n" );
	exit( 1 );
}

$slug   = 'ziplogger-error-monitoring-session-replay'; // WordPress.org slug = folder in the ZIP = text domain.
$root   = dirname( __DIR__ );
$source = isset( $argv[1] ) ? $argv[1] : $root . '/plugin/ziplogger';
$target = isset( $argv[2] ) ? $argv[2] : $root . '/dist/ziplogger.zip';
$source = rtrim( str_replace( '\\', '/', $source ), '/' );

if ( ! is_file( $source . '/ziplogger.php' ) ) {
	fwrite( STDERR, "Not a plugin directory: {$source}\n" );
	exit( 1 );
}

$epoch = getenv( 'SOURCE_DATE_EPOCH' );
$epoch = false !== $epoch && ctype_digit( (string) $epoch ) ? (int) $epoch : 1788134400; // 2026-08-31, a fixed default.

// Never ship these, even if they appear in the source tree.
$excluded = array( '#(^|/)\.#', '#\.(log|orig|rej|swp|bak)$#', '#(^|/)(node_modules|tests|vendor-src)/#', '#Thumbs\.db$#' );

$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$relative = ltrim( substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $source ) ), '/' );
	foreach ( $excluded as $pattern ) {
		if ( preg_match( $pattern, $relative ) ) {
			continue 2;
		}
	}
	$files[] = $relative;
}
sort( $files, SORT_STRING );

if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0777, true ) ) {
	fwrite( STDERR, "Cannot create output directory.\n" );
	exit( 1 );
}
if ( is_file( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
	fwrite( STDERR, "Cannot create {$target}\n" );
	exit( 1 );
}
foreach ( $files as $relative ) {
	$name = $slug . '/' . $relative;
	$zip->addFile( $source . '/' . $relative, $name );
	$zip->setCompressionName( $name, ZipArchive::CM_DEFLATE, 9 );
	$zip->setMtimeName( $name, $epoch );
	$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, ( 0100644 << 16 ) );
}
$zip->close();

$hash = hash_file( 'sha256', $target );
printf( "Built %s\n  files:  %d\n  size:   %d bytes\n  sha256: %s\n", $target, count( $files ), filesize( $target ), $hash );
