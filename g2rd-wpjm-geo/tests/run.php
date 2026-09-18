<?php
/**
 * Tests de la logique Go Fetch Jobs / candidature. Usage : php tests/run.php
 *
 * Les imports Go Fetch Jobs sont simulés en reproduisant ce que fait son
 * importeur : écriture des métadonnées puis do_action( 'goft_wpjm_after_insert_job' ).
 *
 * @package G2RD\WPJMGeo
 */

// phpcs:disable -- fichier de test, hors conventions de production.

// Exécutable uniquement en ligne de commande : jamais depuis le web.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

use G2RD\WPJMGeo\ApplicationNormalizer;
use G2RD\WPJMGeo\GoFetchIntegration;
use G2RD\WPJMGeo\Logger;
use G2RD\WPJMGeo\Settings;
use G2RD\WPJMGeo\UrlChecker;
use G2RD\WPJMGeo\UrlCheckQueue;

$GLOBALS['g2rd_results'] = array( 'pass' => 0, 'fail' => 0 );

function check( $label, $condition ) {
	$GLOBALS['g2rd_results'][ $condition ? 'pass' : 'fail' ]++;
	echo ( $condition ? '  [OK]   ' : '  [FAIL] ' ) . $label . PHP_EOL;
}

/** Démarre un plugin « neuf » et retourne ses composants. */
function boot( array $settings = array() ) {
	G2RD_Test_State::reset();
	if ( $settings ) {
		update_option( Settings::OPTION, $settings );
	}
	$s          = new Settings();
	$logger     = new Logger( $s );
	$normalizer = new ApplicationNormalizer( $s, $logger, new UrlChecker() );
	$queue      = new UrlCheckQueue( $normalizer );
	$queue->register();
	( new GoFetchIntegration( $s, $normalizer, $queue ) )->register();
	return array( $s, $normalizer, $queue );
}

/** Simule l'import (ou la mise à jour) d'une offre par Go Fetch Jobs. */
function gofetch_import( $id, $application ) {
	G2RD_Test_State::$post_types[ $id ]                  = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_goft_wpjm_is_external'] = 1;
	G2RD_Test_State::$meta[ $id ]['_goft_source_data']      = array( 'name' => 'Adzuna', 'feed_url' => 'https://api.adzuna.com/v1/api/jobs?app_key=SECRET' );
	G2RD_Test_State::$meta[ $id ]['_application']           = $application;
	do_action( 'goft_wpjm_after_insert_job', $id, array(), array(), array() );
}

/** Simule une offre créée hors Go Fetch Jobs (employeur ou administrateur). */
function manual_job( $id, $application ) {
	G2RD_Test_State::$post_types[ $id ]        = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_application'] = $application;
}

function application( $id ) {
	return G2RD_Test_State::$meta[ $id ]['_application'];
}

const DEF = 'reconversion@pompiersparis.fr';

echo PHP_EOL . '1. Offre Go Fetch Jobs avec URL HTTPS => remplacement' . PHP_EOL;
boot();
gofetch_import( 1, 'https://site-externe.com/job/54896' );
check( '_application vaut l’adresse configurée', DEF === application( 1 ) );
check( 'une entrée de journal est créée', 1 === count( G2RD_Test_State::$log_rows ) );
check( 'le journal contient ID, ancienne/nouvelle valeur, raison, source', 1 === G2RD_Test_State::$log_rows[0]['job_id'] && 'https://site-externe.com/job/54896' === G2RD_Test_State::$log_rows[0]['old_value'] && DEF === G2RD_Test_State::$log_rows[0]['new_value'] && 'mode_all' === G2RD_Test_State::$log_rows[0]['reason'] && 'Go Fetch Jobs — Adzuna' === G2RD_Test_State::$log_rows[0]['source'] );
check( 'aucune requête HTTP pendant l’import', 0 === count( G2RD_Test_State::$http_calls ) );

echo PHP_EOL . '2. Offre Go Fetch Jobs avec URL HTTP => remplacement' . PHP_EOL;
boot();
gofetch_import( 2, 'http://site.com/job/456' );
check( '_application remplacée', DEF === application( 2 ) );

echo PHP_EOL . '3. Offre Go Fetch Jobs avec email valide => aucune modification' . PHP_EOL;
boot();
gofetch_import( 3, 'recrutement@entreprise.fr' );
check( 'adresse conservée', 'recrutement@entreprise.fr' === application( 3 ) );
check( 'aucune entrée de journal', 0 === count( G2RD_Test_State::$log_rows ) );

echo PHP_EOL . '4. Offre manuelle (employeur) avec URL => aucune modification' . PHP_EOL;
list( , $normalizer ) = boot();
manual_job( 4, 'https://employeur.fr/postuler' );
$normalizer->handle_import( 4 );
check( 'URL conservée même si le normaliseur est appelé directement', 'https://employeur.fr/postuler' === application( 4 ) );
check( 'raison = not_gofetch', 'not_gofetch' === $normalizer->evaluate( 4 )['reason'] );

echo PHP_EOL . '5. Offre administrateur avec URL => aucune modification' . PHP_EOL;
boot();
manual_job( 5, 'https://carrieres.exemple.org/offre/9' );
do_action( 'goft_wpjm_after_insert_jobs_commit', array( 5 ) ); // Même passée par erreur dans un lot, elle est ignorée.
check( 'URL conservée', 'https://carrieres.exemple.org/offre/9' === application( 5 ) );
G2RD_Test_State::$post_types[50]                  = 'post';
G2RD_Test_State::$meta[50]['_goft_wpjm_is_external'] = 1;
G2RD_Test_State::$meta[50]['_application']           = 'https://x.test/a';
do_action( 'goft_wpjm_after_insert_job', 50 );
check( 'un contenu qui n’est pas une offre (job_listing) est ignoré', 'https://x.test/a' === application( 50 ) );

echo PHP_EOL . '6. Option de remplacement désactivée => aucune modification' . PHP_EOL;
boot( array( 'gofetch_replace' => false ) );
gofetch_import( 6, 'https://site.com/offre/123' );
check( 'URL conservée', 'https://site.com/offre/123' === application( 6 ) );

echo PHP_EOL . '7. Adresse configurée modifiée => la nouvelle adresse est utilisée' . PHP_EOL;
boot();
gofetch_import( 70, 'https://site.com/a' );
update_option( Settings::FALLBACK_EMAIL_OPTION, 'candidatures@pompiersparis.fr' );
gofetch_import( 71, 'https://site.com/b' );
check( 'import avant modification : ancienne adresse', DEF === application( 70 ) );
check( 'import après modification : nouvelle adresse', 'candidatures@pompiersparis.fr' === application( 71 ) );

echo PHP_EOL . '8. Aucune option enregistrée => adresse par défaut' . PHP_EOL;
list( $s, $normalizer ) = boot();
check( 'l’option n’existe pas en base', ! array_key_exists( Settings::FALLBACK_EMAIL_OPTION, G2RD_Test_State::$options ) );
check( 'get_fallback_email() retourne la valeur d’initialisation', DEF === $normalizer->get_fallback_email() && DEF === $s->get_fallback_email() );

echo PHP_EOL . '9. Adresse invalide soumise dans les réglages => refus' . PHP_EOL;
list( $s ) = boot();
update_option( Settings::FALLBACK_EMAIL_OPTION, 'candidatures@pompiersparis.fr' );
foreach ( array( 'pas-une-adresse', 'a@b', 'test@@exemple.fr', '', '<script>@x.fr' ) as $bad ) {
	G2RD_Test_State::$errors = array();
	$kept                    = $s->sanitize_fallback_email( $bad );
	check( sprintf( '« %s » refusée, dernière adresse valide conservée, message affiché', $bad ), 'candidatures@pompiersparis.fr' === $kept && 'invalid_fallback_email' === G2RD_Test_State::$errors[0]['code'] );
}
G2RD_Test_State::$errors = array();
check( 'une adresse valide est acceptée sans message', 'rh@pompiersparis.fr' === $s->sanitize_fallback_email( ' rh@pompiersparis.fr ' ) && array() === G2RD_Test_State::$errors );

echo PHP_EOL . '10. Mise à jour Go Fetch Jobs qui réinjecte une URL => remplacement automatique' . PHP_EOL;
boot();
gofetch_import( 10, 'https://site-source.com/job/123' );
check( 'premier import : remplacée', DEF === application( 10 ) );
gofetch_import( 10, 'https://site-source.com/job/123' ); // Réimport quelques heures plus tard.
check( 'après mise à jour : remplacée de nouveau', DEF === application( 10 ) );
check( 'deux entrées de journal (une par remplacement)', 2 === count( G2RD_Test_State::$log_rows ) );
do_action( 'goft_wpjm_after_insert_jobs_commit', array( 10 ) );
check( 'le filet de fin de lot est idempotent (pas de 3e écriture)', 2 === count( G2RD_Test_State::$log_rows ) );

echo PHP_EOL . '11. Cas limites' . PHP_EOL;
list( , $normalizer ) = boot();
gofetch_import( 110, '' );
check( 'valeur vide => reste vide', '' === application( 110 ) );
gofetch_import( 111, 'www.site.com/job/1' );
check( 'URL sans schéma => remplacée', DEF === application( 111 ) );
gofetch_import( 112, 'Envoyer CV par courrier' );
check( 'texte libre (ni URL ni email) => conservé', 'Envoyer CV par courrier' === application( 112 ) );
check( 'classify() : javascript: est traité comme une URL', ApplicationNormalizer::TYPE_URL === $normalizer->classify( 'javascript:alert(1)' ) );
do_action( 'goft_wpjm_after_insert_job', 'abc' );
do_action( 'goft_wpjm_after_insert_job', array( 1 ) );
check( 'identifiant non numérique transmis par Go Fetch Jobs => ignoré sans erreur', true );
boot();
update_option( Settings::FALLBACK_EMAIL_OPTION, 'invalide' );
gofetch_import( 113, 'https://site.com/x' );
check( 'adresse stockée invalide (base altérée) => aucun remplacement', 'https://site.com/x' === application( 113 ) );
check( 'redact_url() masque les jetons', false === strpos( Logger::redact_url( 'https://u:p@api.test/j?id=4&app_key=SECRET' ), 'SECRET' ) && false === strpos( Logger::redact_url( 'https://u:p@api.test/j?id=4' ), 'u:p' ) );
boot( array( 'log_enabled' => false ) );
gofetch_import( 114, 'https://site.com/y' );
check( 'journal désactivé => remplacement effectué sans entrée', DEF === application( 114 ) && 0 === count( G2RD_Test_State::$log_rows ) );
$s = new Settings();
$c = $s->sanitize( array( 'map_height' => '99999', 'log_retention_days' => '0', 'gofetch_scope' => 'nimporte', 'browser_api_key' => 'AIza<script>"Key_-1', 'gofetch_replace' => '1' ) );
check( 'sanitize() borne les valeurs et nettoie la clé', 1200 === $c['map_height'] && 1 === $c['log_retention_days'] && 'all' === $c['gofetch_scope'] && 'AIzascriptKey_-1' === $c['browser_api_key'] && true === $c['gofetch_replace'] && false === $c['map_enabled'] );

echo PHP_EOL . '12. Mode « uniquement les URL invalides ou inaccessibles » (différé)' . PHP_EOL;
list( , , $queue ) = boot( array( 'gofetch_scope' => 'invalid' ) );
G2RD_Test_State::$http['https://mort.test/job/1']['HEAD'] = array( 'response' => array( 'code' => 404 ) );
G2RD_Test_State::$http['https://mort.test/job/1']['GET']  = array( 'response' => array( 'code' => 404 ) );
G2RD_Test_State::$http['https://protege.test/job/2']['HEAD'] = array( 'response' => array( 'code' => 403 ) );
gofetch_import( 120, 'https://mort.test/job/1' );
gofetch_import( 121, 'https://protege.test/job/2' );
check( 'pendant l’import : aucune requête HTTP, aucune modification', 0 === count( G2RD_Test_State::$http_calls ) && 'https://mort.test/job/1' === application( 120 ) );
$queue->flush();
check( 'les offres sont mises en file et un événement WP-Cron est planifié', array( 120, 121 ) === get_option( UrlCheckQueue::OPTION ) && false !== wp_next_scheduled( UrlCheckQueue::CRON_HOOK ) );
do_action( UrlCheckQueue::CRON_HOOK );
check( 'URL 404 => remplacée (raison http_404)', DEF === application( 120 ) && 'http_404' === G2RD_Test_State::$log_rows[0]['reason'] );
check( 'URL 403 => conservée (la page peut exister)', 'https://protege.test/job/2' === application( 121 ) );
check( 'file vidée après traitement', array() === get_option( UrlCheckQueue::OPTION ) );

echo PHP_EOL . '13. UrlChecker' . PHP_EOL;
boot();
$checker = new UrlChecker();
$r       = $checker->check( 'ftp://site.test/file' );
check( 'schéma non autorisé => problème', $r['problem'] && 'bad_scheme' === $r['reason'] );
$r = $checker->check( 'javascript:alert(1)' );
check( 'javascript: => problème, sans requête', $r['problem'] && 0 === count( G2RD_Test_State::$http_calls ) );
$r = $checker->check( 'https://' );
check( 'URL syntaxiquement invalide => problème', $r['problem'] && 'invalid_syntax' === $r['reason'] );
G2RD_Test_State::$dns['inexistant.invalid'] = false;
$r = $checker->check( 'https://inexistant.invalid/job' );
check( 'erreur DNS => problème', $r['problem'] && 'dns_error' === $r['reason'] );
$r = $checker->check( 'http://192.168.1.10/admin' );
check( 'IP privée => bloquée sans requête (anti-SSRF)', $r['problem'] && 'unsafe_target' === $r['reason'] && 0 === count( G2RD_Test_State::$http_calls ) );
G2RD_Test_State::$http['https://nohead.test/j']['HEAD'] = array( 'response' => array( 'code' => 405 ) );
G2RD_Test_State::$http['https://nohead.test/j']['GET']  = array( 'response' => array( 'code' => 200 ) );
$r = $checker->check( 'https://nohead.test/j' );
check( 'HEAD non supporté (405) puis GET 200 => OK', ! $r['problem'] );
foreach ( array( 401, 403, 429, 500, 503 ) as $code ) {
	G2RD_Test_State::$http[ 'https://c' . $code . '.test/' ]['HEAD'] = array( 'response' => array( 'code' => $code ) );
	$r = $checker->check( 'https://c' . $code . '.test/' );
	check( $code . ' => non considéré comme inexistant', ! $r['problem'] );
}
G2RD_Test_State::$http['https://gone.test/j']['HEAD'] = array( 'response' => array( 'code' => 410 ) );
G2RD_Test_State::$http['https://gone.test/j']['GET']  = array( 'response' => array( 'code' => 410 ) );
$r = $checker->check( 'https://gone.test/j' );
check( '410 confirmé par GET => problème', $r['problem'] && 'http_410' === $r['reason'] );
G2RD_Test_State::$http['https://ssl.test/j']['HEAD'] = new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: certificate has expired' );
$r = $checker->check( 'https://ssl.test/j' );
check( 'échec SSL => problème', $r['problem'] && 'ssl_error' === $r['reason'] );
G2RD_Test_State::$http['https://loop.test/j']['HEAD'] = new WP_Error( 'http_request_failed', 'Too many redirects.' );
$r = $checker->check( 'https://loop.test/j' );
check( 'redirection cassée => problème', $r['problem'] && 'redirect_error' === $r['reason'] );
G2RD_Test_State::$http['https://lent.test/j']['HEAD'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' );
$r = $checker->check( 'https://lent.test/j' );
check( 'timeout => non concluant, rien n’est modifié', ! $r['problem'] );
$before = count( G2RD_Test_State::$http_calls );
$checker->check( 'https://gone.test/j' );
check( 'résultat mis en cache (pas de nouvelle requête)', count( G2RD_Test_State::$http_calls ) === $before );

$results = $GLOBALS['g2rd_results'];
echo PHP_EOL . sprintf( 'Résultat : %d réussis, %d échoués', $results['pass'], $results['fail'] ) . PHP_EOL;
exit( $results['fail'] ? 1 : 0 );
