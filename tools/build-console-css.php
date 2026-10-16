<?php
/**
 * Régénère g2rd-wpjm-geo/assets/css/console.css depuis la feuille compilée de
 * la console du thème G2RD (même auteur).
 *
 * Usage : php tools/build-console-css.php ../G2RD-theme
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$theme = rtrim( (string) ( isset( $argv[1] ) ? $argv[1] : '' ), '/\\' );
$src   = $theme . '/blocks/g2rd-options-page/build/style-index.css';
$style = $theme . '/style.css';
if ( '' === $theme || ! is_readable( $src ) || ! is_readable( $style ) ) {
	fwrite( STDERR, "Feuille de la console introuvable : $src\n" );
	exit( 1 );
}

preg_match( '/^\s*Version:\s*(\S+)/mi', (string) file_get_contents( $style ), $match );
$version = isset( $match[1] ) ? $match[1] : 'inconnue';
$null    = '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null';
$rel     = 'blocks/g2rd-options-page/build/style-index.css';
$git     = 'git -C ' . escapeshellarg( $theme ) . ' ';
// Dernier commit de la feuille elle-même (pas HEAD) : l'en-tête ne change que si le CSS change.
$commit = trim( (string) shell_exec( $git . 'log -1 --format=%h -- ' . escapeshellarg( $rel ) . ' 2>' . $null ) );
if ( '' !== $commit && '' !== trim( (string) shell_exec( $git . 'status --porcelain -- ' . escapeshellarg( $rel ) . ' 2>' . $null ) ) ) {
	$commit .= ' + modifications locales';
}
// La carte des sources (.map) n'est pas copiée : sa référence provoquerait un 404.
$css = preg_replace( '~\s*/\*#\s*sourceMappingURL=[^*]*\*/\s*$~', "\n", (string) file_get_contents( $src ) );

$header = "/*\n"
	. ' * Copie de la feuille de la console G2RD — thème G2RD ' . $version . ( '' !== $commit ? ', commit ' . $commit : '' ) . ".\n"
	. " * Source : blocks/g2rd-options-page/build/style-index.css — © Sébastien Gérard, reprise par son auteur.\n"
	. " * Chargée UNIQUEMENT quand le thème G2RD ne fournit pas sa console (menu autonome).\n"
	. " * Ne pas modifier à la main : php tools/build-console-css.php <dossier du thème G2RD>.\n"
	. " */\n\n";

$target = dirname( __DIR__ ) . '/g2rd-wpjm-geo/assets/css/console.css';
if ( false === file_put_contents( $target, $header . $css ) ) {
	fwrite( STDERR, "Écriture impossible : $target\n" );
	exit( 1 );
}
echo 'console.css régénérée (thème ' . $version . ( '' !== $commit ? ' ' . $commit : '' ) . ', ' . filesize( $target ) . " octets)\n";
