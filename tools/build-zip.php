<?php
/**
 * Construit g2rd-wpjm-geo.zip à la racine du dépôt, sans les tests.
 * Usage : php tools/build-zip.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root     = dirname( __DIR__ );
$src      = $root . '/g2rd-wpjm-geo';
$zip_path = $root . '/g2rd-wpjm-geo.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Impossible de créer l'archive.\n" );
	exit( 1 );
}

$count    = 0;
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$relative = 'g2rd-wpjm-geo/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $src ) + 1 ) );
	if ( 0 === strpos( $relative, 'g2rd-wpjm-geo/tests/' ) ) {
		continue;
	}
	$zip->addFile( $file->getPathname(), $relative );
	++$count;
}
$zip->close();
printf( "%s : %d fichiers, %d octets\n", basename( $zip_path ), $count, filesize( $zip_path ) );
