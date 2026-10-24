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

use G2RD\WPJMGeo\AdminMenu;
use G2RD\WPJMGeo\AdminScreen;
use G2RD\WPJMGeo\ApplicationNormalizer;
use G2RD\WPJMGeo\GeocodeQueue;
use G2RD\WPJMGeo\GeocodingIntegration;
use G2RD\WPJMGeo\GoFetchIntegration;
use G2RD\WPJMGeo\GoogleAssets;
use G2RD\WPJMGeo\LocationAutocomplete;
use G2RD\WPJMGeo\Logger;
use G2RD\WPJMGeo\MigrationTool;
use G2RD\WPJMGeo\PhotonGeocoder;
use G2RD\WPJMGeo\PlaceParser;
use G2RD\WPJMGeo\PlaceLocator;
use G2RD\WPJMGeo\PrivacyPolicy;
use G2RD\WPJMGeo\Regions;
use G2RD\WPJMGeo\RegeocodeTool;
use G2RD\WPJMGeo\RestController;
use G2RD\WPJMGeo\Settings;
use G2RD\WPJMGeo\UrlChecker;
use G2RD\WPJMGeo\UrlCheckQueue;

$GLOBALS['g2rd_results'] = array( 'pass' => 0, 'fail' => 0 );

// Tout avertissement PHP compte comme un échec (les tests « sans avertissement » le vérifient vraiment).
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) {
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}
		$GLOBALS['g2rd_results']['fail']++;
		echo '  [FAIL] PHP : ' . $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')' . PHP_EOL;
		return true;
	}
);

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
check( 'texte libre (ni URL ni email) => remplacé : WP Job Manager en fait un lien de candidature', DEF === application( 112 ) );
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

echo PHP_EOL . '14. Correctifs de la revue de code' . PHP_EOL;
boot();
$checker = new UrlChecker();
G2RD_Test_State::$http['http://careers.acme.com:8443/apply/17']['HEAD'] = array( 'response' => array( 'code' => 200 ) );
$r = $checker->check( 'careers.acme.com:8443/apply/17' );
check( 'hôte:port sans schéma n’est pas un « schéma non autorisé » (vérifié par HTTP, OK)', ! $r['problem'] && 1 === count( G2RD_Test_State::$http_calls ) );
check( 'mailto: reste un schéma non autorisé', $checker->check( 'mailto:rh@x.fr' )['reason'] === 'bad_scheme' );
G2RD_Test_State::$http['https://bloque.test/j']['HEAD'] = new WP_Error( 'http_request_not_executed', 'User has blocked requests through HTTP.' );
$r = $checker->check( 'https://bloque.test/j' );
check( 'HTTP sortant bloqué par l’hébergeur => non concluant, URL conservée', ! $r['problem'] );
G2RD_Test_State::$http['https://redir.test/j']['HEAD'] = new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
$r = $checker->check( 'https://redir.test/j' );
check( 'redirection vers une destination interdite => problème (unsafe_target)', $r['problem'] && 'unsafe_target' === $r['reason'] );
G2RD_Test_State::$dns['ipv6only.test'] = false;
G2RD_Test_State::$dns6['ipv6only.test'] = true;
$r = $checker->check( 'https://ipv6only.test/job' );
check( 'hôte IPv6 uniquement => non concluant (pas de remplacement), sans requête HTTP', ! $r['problem'] && 'inconclusive' === $r['reason'] );
G2RD_Test_State::$dns['nulle-part.test'] = false;
check( 'hôte sans A ni AAAA => erreur DNS', 'dns_error' === $checker->check( 'https://nulle-part.test/job' )['reason'] );
check( 'journal : l’URL encodée est conservée telle quelle', 'https://jobs.example.com/offre/ing%C3%A9nieur%20r%C3%A9seau?id=42' === Logger::redact_url( 'https://jobs.example.com/offre/ing%C3%A9nieur%20r%C3%A9seau?id=42' ) );
check( 'journal : les balises sont toujours retirées', false === strpos( Logger::redact_url( 'https://x.test/<script>a</script>/p' ), '<' ) );

// File différée : les IDs empilés pendant un lot ne sont pas perdus, et seuls les IDs traités sont retirés.
list( , , $queue ) = boot( array( 'gofetch_scope' => 'invalid' ) );
gofetch_import( 140, 'ftp://site.example/a' );
gofetch_import( 141, 'ftp://site.example/b' );
gofetch_import( 142, 'ftp://site.example/c' );
$queue->flush();
check( 'file initiale [140,141,142]', array( 140, 141, 142 ) === get_option( UrlCheckQueue::OPTION ) );
add_action( 'g2rd_wpjm_geo_application_replaced', function ( $job_id ) {
	// Simule un import concurrent qui écrit dans l’option pendant le traitement du lot.
	if ( 140 === $job_id ) {
		update_option( UrlCheckQueue::OPTION, array_merge( get_option( UrlCheckQueue::OPTION ), array( 777 ) ), false );
	}
} );
unset( G2RD_Test_State::$scheduled[ UrlCheckQueue::CRON_HOOK ] ); // WP-Cron retire l’événement avant de l’exécuter.
do_action( UrlCheckQueue::CRON_HOOK );
check( 'lot de 2: #140 et #141 traités, #142 et l’ID concurrent #777 toujours en file', DEF === application( 140 ) && DEF === application( 141 ) && 'ftp://site.example/c' === application( 142 ) && array( 142, 777 ) === get_option( UrlCheckQueue::OPTION ) );
check( 'un nouveau lot est planifié', false !== wp_next_scheduled( UrlCheckQueue::CRON_HOOK ) );

// File générique : nouvel essai, délai imposé, verrou libéré après une exception.
G2RD_Test_State::reset();
$probe = new class() extends \G2RD\WPJMGeo\JobQueue {
	const OPTION     = 'g2rd_test_queue';
	const CRON_HOOK  = 'g2rd_test_queue_run';
	const LOCK       = 'g2rd_test_queue_lock';
	const BATCH_SIZE = 3;
	public $fail  = array();
	public $throw = 0;
	public $seen  = array();
	protected function process_item( $job_id ) {
		$this->seen[] = $job_id;
		if ( $job_id === $this->throw ) {
			throw new \RuntimeException( 'panne' );
		}
		return ! in_array( $job_id, $this->fail, true );
	}
};
$probe->push( 1 );
$probe->push( 2 );
$probe->push( 3 );
$probe->flush();
G2RD_Test_State::$scheduled['g2rd_test_queue_run'] = time() + MINUTE_IN_SECONDS; // planifié par un import concurrent
$probe->fail = array( 2 );
$probe->process();
check( 'file générique : lot arrêté au premier échec, offre remise en fin de file', array( 1, 2 ) === $probe->seen && array( 3, 2 ) === get_option( 'g2rd_test_queue' ) );
check( 'file générique : nouvel essai après RETRY_DELAY, même si un passage était prévu plus tôt', wp_next_scheduled( 'g2rd_test_queue_run' ) >= time() + \G2RD\WPJMGeo\JobQueue::RETRY_DELAY - 10 );
check( 'file générique : verrou libéré', false === get_transient( 'g2rd_test_queue_lock' ) );
$probe->fail  = array();
$probe->seen  = array();
$probe->throw = 3;
G2RD_Test_State::$scheduled = array();
$thrown = false;
try {
	$probe->process();
} catch ( \RuntimeException $e ) {
	$thrown = true;
}
check( 'file générique : exception propagée, verrou libéré, file relancée', $thrown && false === get_transient( 'g2rd_test_queue_lock' ) && array( 2 ) === get_option( 'g2rd_test_queue' ) && false !== wp_next_scheduled( 'g2rd_test_queue_run' ) );

echo PHP_EOL . '15. Réglages du fournisseur' . PHP_EOL;
list( $s ) = boot();
check( 'fournisseur par défaut : google', 'google' === $s->get_provider() );
update_option( Settings::OPTION, array( 'provider' => 'osm' ) );
$s->flush_cache();
check( 'fournisseur osm lu', 'osm' === $s->get_provider() );
update_option( Settings::OPTION, array( 'provider' => 'autre' ) );
$s->flush_cache();
check( 'valeur inconnue : google', 'google' === $s->get_provider() );
check( 'URL https acceptée et rognée', 'https://photon.example.org/api/' === Settings::https_url( ' https://photon.example.org/api/ ' ) );
check( 'URL http refusée', '' === Settings::https_url( 'http://photon.example.org/api/' ) );
check( 'URL sans hôte refusée', '' === Settings::https_url( 'https:///api' ) );
check( 'javascript: refusé', '' === Settings::https_url( 'javascript:alert(1)' ) );
list( $s ) = boot();
G2RD_Test_State::$errors = array();
$c = $s->sanitize( array( 'provider' => 'osm', 'osm_style_url' => 'http://insecure.test/style', 'photon_url' => 'https://photon.mon-serveur.fr/api/' ) );
check( 'sanitize : fournisseur osm', 'osm' === $c['provider'] );
check( 'sanitize : style http refusé, valeur précédente gardée, message', Settings::DEFAULT_OSM_STYLE_URL === $c['osm_style_url'] && 'invalid_osm_style_url' === G2RD_Test_State::$errors[0]['code'] );
check( 'sanitize : URL Photon https acceptée', 'https://photon.mon-serveur.fr/api/' === $c['photon_url'] );
$c = $s->sanitize( array() );
check( 'sanitize : champs absents, valeurs par défaut sans message', 'google' === $c['provider'] && Settings::DEFAULT_PHOTON_URL === $c['photon_url'] && 1 === count( G2RD_Test_State::$errors ) );
check( 'URL https en majuscules acceptée', '' !== Settings::https_url( 'HTTPS://photon.example.org/api/' ) );
check( 'URL avec identifiants refusée', '' === Settings::https_url( 'https://user:secret@photon.example.org/api/' ) );
check( 'URL avec utilisateur seul refusée', '' === Settings::https_url( 'https://user@photon.example.org/api/' ) );
check( 'valeur non textuelle refusée', '' === Settings::https_url( array( 'https://photon.example.org/api/' ) ) );
check( 'URL trop longue refusée', '' === Settings::https_url( 'https://photon.example.org/' . str_repeat( 'a', 2048 ) ) );
list( $s ) = boot( array( 'photon_url' => 'https://photon.perso.fr/api/', 'osm_style_url' => 'https://tuiles.perso.fr/style' ) );
G2RD_Test_State::$errors = array();
$c = $s->sanitize( array( 'photon_url' => 'ftp://x', 'osm_style_url' => '' ) );
check( 'sanitize : URL invalide, valeur personnalisée gardée, un message', 'https://photon.perso.fr/api/' === $c['photon_url'] && 1 === count( G2RD_Test_State::$errors ) );
check( 'sanitize : champ vidé, style par défaut', Settings::DEFAULT_OSM_STYLE_URL === $c['osm_style_url'] );
G2RD_Test_State::$errors = array();
$c = $s->sanitize( array( 'photon_url' => array( 'https://a.test/' ) ) );
check( 'sanitize : tableau refusé, valeur gardée, message', 'https://photon.perso.fr/api/' === $c['photon_url'] && 'invalid_photon_url' === G2RD_Test_State::$errors[0]['code'] );
check( 'sanitize : champ absent, valeur personnalisée gardée', 'https://tuiles.perso.fr/style' === $c['osm_style_url'] );
G2RD_Test_State::$errors = array();
$again = $s->sanitize( $s->sanitize( array( 'provider' => 'osm', 'photon_url' => 'https://photon.perso.fr/api/' ) ) );
check( 'sanitize idempotent, sans message', 'osm' === $again['provider'] && 'https://photon.perso.fr/api/' === $again['photon_url'] && 0 === count( G2RD_Test_State::$errors ) );

echo PHP_EOL . '16. PhotonGeocoder' . PHP_EOL;

function photon_feature( $name, $key, $value, $lng, $lat, $extra = array() ) {
	return array(
		'type'       => 'Feature',
		'geometry'   => array( 'type' => 'Point', 'coordinates' => array( $lng, $lat ) ),
		'properties' => array_merge( array( 'name' => $name, 'osm_key' => $key, 'osm_value' => $value ), $extra ),
	);
}
function photon_reply( array $features, $code = 200 ) {
	return array( 'response' => array( 'code' => $code ), 'body' => json_encode( array( 'type' => 'FeatureCollection', 'features' => $features ) ) );
}

check( 'clean : parenthèses retirées', 'Lyon' === PhotonGeocoder::clean( 'Lyon (69)' ) );
check( 'clean : numéro isolé retiré', 'Paris' === PhotonGeocoder::clean( '75008 Paris' ) );
check( 'clean : « Paris 6 » donne « Paris »', 'Paris' === PhotonGeocoder::clean( 'Paris 6' ) );
check( 'clean : chiffre accolé à une lettre conservé', 'A4 Sud' === PhotonGeocoder::clean( 'A4 Sud' ) );
check( 'clean : séparateurs de bord retirés', 'Rouen' === PhotonGeocoder::clean( ' - Rouen, ' ) );
check( 'normalize : accents, casse, ponctuation', 'ile de france' === PhotonGeocoder::normalize( 'Île-de-France' ) );
check( 'language : fr, de, sinon default', 'fr' === PhotonGeocoder::language( 'fr_FR' ) && 'de' === PhotonGeocoder::language( 'de_DE_formal' ) && 'default' === PhotonGeocoder::language( 'es_ES' ) );

list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'q=Londres' ) ) {
		return photon_reply( array( photon_feature( 'Londres', 'place', 'city', -0.1276, 51.5073, array( 'state' => 'Angleterre', 'country' => 'Royaume-Uni', 'countrycode' => 'gb' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=IDF' ) ) {
		return photon_reply( array( photon_feature( 'Idfu', 'place', 'town', 32.87, 24.98, array( 'country' => 'Égypte' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Brest' ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}
	if ( false !== strpos( $url, 'q=Atlantis' ) ) {
		return photon_reply( array( photon_feature( 'Atlantis', 'place', 'city', 10, 200 ) ) );
	}
	return photon_reply( array() );
};

$r = $geo->geocode( 'Londres, Angleterre, Royaume-Uni' );
check( 'Londres : coordonnées et ville', is_array( $r ) && '51.5073' === $r['lat'] && '-0.1276' === $r['long'] && 'Londres' === $r['city'] );
check( 'Londres : adresse au format WPJM', is_array( $r ) && 'Londres, Angleterre, Royaume-Uni' === $r['formatted_address'] && 'Angleterre' === $r['state_long'] && 'Royaume-Uni' === $r['country_long'] && 'GB' === $r['country_short'] );
check( 'requête : texte encodé, limite, langue, filtres de lieu', 1 === count( $urls ) && 0 === strpos( $urls[0], 'https://photon.komoot.io/api/?q=Londres%2C%20Angleterre%2C%20Royaume-Uni&limit=5&lang=fr' ) && false !== strpos( $urls[0], '&osm_tag=place&osm_tag=boundary%3Aadministrative' ) );
check( 'premier appel : réseau', false === $geo->last_was_cached() );
$geo->geocode( 'Londres, Angleterre, Royaume-Uni' );
check( 'deuxième appel : cache', 1 === count( $urls ) && $geo->last_was_cached() );
check( 'IDF : homonyme lointain refusé', null === $geo->geocode( 'IDF' ) );
$before = count( $urls );
check( 'IDF : refus mis en cache', null === $geo->geocode( 'IDF' ) && count( $urls ) === $before );
check( 'coordonnées hors bornes refusées', null === $geo->geocode( 'Atlantis' ) );
check( 'erreur réseau : WP_Error', is_wp_error( $geo->geocode( 'Brest' ) ) );
$before = count( $urls );
$geo->geocode( 'Brest' );
check( 'erreur réseau : non mise en cache', count( $urls ) === $before + 1 );
check( 'texte vide après nettoyage : null sans requête', null === $geo->geocode( ' (75) ' ) && count( $urls ) === $before + 1 );

list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'osm_tag' ) ) {
		return array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"bad"}' );
	}
	return photon_reply(
		array(
			photon_feature( 'Trappes', 'shop', 'bakery', 1.0, 1.0 ),
			photon_feature( 'Trappes', 'place', 'town', 2.0099, 48.7775, array( 'country' => 'France' ) ),
		)
	);
};
$r = $geo->geocode( 'TRAPPES' );
check( 'HTTP 400 : nouvel essai sans filtre', 2 === count( $urls ) && false === strpos( $urls[1], 'osm_tag' ) );
check( 'repli : seuls les lieux sont gardés', is_array( $r ) && '48.7775' === $r['lat'] && 'Trappes' === $r['city'] );

G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"bad"}' );
};
check( 'HTTP 400 même sans filtre : introuvable, pas une panne', null === $geo->geocode( 'Texte refusé' ) );
G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 503 ), 'body' => '' );
};
check( 'HTTP 503 : panne (WP_Error)', is_wp_error( $geo->geocode( 'Nantes' ) ) );

list( $s ) = boot( array( 'photon_url' => 'https://photon.local/api/' ) );
$geo = new PhotonGeocoder( $s );
add_filter( 'g2rd_wpjm_geo_photon_allow_private_host', '__return_true' );
$during = array();
G2RD_Test_State::$http_responder = function () use ( &$during ) {
	$during = array( apply_filters( 'http_request_host_is_external', false, 'photon.local' ), apply_filters( 'http_request_host_is_external', false, 'evil.test' ) );
	return photon_reply( array() );
};
$geo->geocode( 'Rouen' );
check( 'Photon auto-hébergé : seul son hôte est autorisé pendant la requête', array( true, false ) === $during );
check( 'autorisation retirée après la requête', false === apply_filters( 'http_request_host_is_external', false, 'photon.local' ) );

// Clé de cache par segment, arrondissements, homonymes, textes vagues, erreurs, robustesse.
check( 'clean : « Paris 15e » donne « Paris »', 'Paris' === PhotonGeocoder::clean( 'Paris 15e' ) );
check( 'clean : « Lyon 1er » et « Marseille 13e Arrondissement »', 'Lyon' === PhotonGeocoder::clean( 'Lyon 1er' ) && 'Marseille' === PhotonGeocoder::clean( 'Marseille 13e Arrondissement' ) );
check( 'clean : UTF-8 invalide, chaîne vide sans avertissement', '' === PhotonGeocoder::clean( "Lyon \xC3\x28" ) );
check( 'normalize : St et Ste lus Saint et Sainte', 'saint etienne' === PhotonGeocoder::normalize( 'St-Étienne' ) && 'sainte genevieve des bois' === PhotonGeocoder::normalize( 'Ste-Geneviève-des-Bois' ) );
check( 'normalize : accents combinants retirés', 'saint etienne' === PhotonGeocoder::normalize( "Saint-E\u{0301}tienne" ) );
check( 'hint : département, code postal, Corse, outre-mer', '53' === PhotonGeocoder::hint( 'Laval (53)' ) && '75' === PhotonGeocoder::hint( '75008 Paris' ) && '20' === PhotonGeocoder::hint( 'Ajaccio (2A)' ) && '974' === PhotonGeocoder::hint( 'Saint-Denis (974)' ) && '' === PhotonGeocoder::hint( 'Lyon' ) );

list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'q=Laval' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Laval', 'place', 'city', -73.75, 45.57, array( 'postcode' => 'H7T', 'country' => 'Canada', 'countrycode' => 'CA' ) ),
				photon_feature( 'Laval', 'place', 'town', -0.77, 48.07, array( 'postcode' => '53000', 'country' => 'France', 'countrycode' => 'FR' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=Valence' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Valence', 'place', 'city', 4.89, 44.93, array( 'country' => 'France', 'countrycode' => 'FR' ) ),
				photon_feature( 'Valence', 'place', 'city', -0.38, 39.47, array( 'country' => 'Espagne', 'countrycode' => 'ES' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=Saint-Denis' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Saint-Denis', 'place', 'city', 55.4504, -20.8789, array( 'postcode' => '97400', 'state' => 'La Réunion', 'country' => 'France', 'countrycode' => 'FR' ) ),
				photon_feature( 'Saint-Denis', 'place', 'city', 2.3574, 48.9362, array( 'postcode' => '93200', 'state' => 'Île-de-France', 'country' => 'France', 'countrycode' => 'FR' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=Montr%C3%A9al' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Montréal', 'place', 'city', -73.5698, 45.5031, array( 'state' => 'Québec', 'country' => 'Canada', 'countrycode' => 'CA' ) ),
				photon_feature( 'Montréal', 'place', 'village', 2.1431, 43.1997, array( 'state' => 'Occitanie', 'country' => 'France', 'countrycode' => 'FR' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=La%20D%C3%A9fense' ) ) {
		return photon_reply( array( photon_feature( 'La Défense', 'place', 'suburb', 2.2384, 48.892, array( 'city' => 'Puteaux', 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Vanoise' ) ) {
		return photon_reply( array( photon_feature( 'Vanoise', 'boundary', 'national_park', 6.8, 45.4 ) ) );
	}
	if ( false !== strpos( $url, 'q=Paris' ) ) {
		return photon_reply( array( photon_feature( 'Paris', 'place', 'city', 2.3522, 48.8566, array( 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Lyon' ) ) {
		return photon_reply( array( photon_feature( 'Lyon', 'amenity', 'bar', 2.35, 48.85 ) ) );
	}
	return photon_reply( array() );
};
$r = $geo->geocode( 'Laval (53)' );
check( 'homonymes : le département saisi l’emporte', is_array( $r ) && '48.07' === $r['lat'] && 'FR' === $r['country_short'] );
$r = $geo->geocode( 'Laval' );
check( 'homonymes : sans indice, le pays du site (fr_FR) l’emporte', is_array( $r ) && 'FR' === $r['country_short'] );
$r = $geo->geocode( 'Valence, Espagne' );
check( 'homonymes : le pays cité dans le texte l’emporte', is_array( $r ) && 'ES' === $r['country_short'] );
$r = $geo->geocode( 'Saint-Denis (93)' );
check( 'homonymes français : le département saisi départage', is_array( $r ) && '48.9362' === $r['lat'] );
$r = $geo->geocode( 'Saint-Denis, Réunion' );
check( 'homonymes : la région citée, sans article, départage', is_array( $r ) && '-20.8789' === $r['lat'] );
$r = $geo->geocode( 'Montréal' );
check( 'homonymes : un village du pays du site ne l’emporte pas sur la grande ville', is_array( $r ) && 'CA' === $r['country_short'] );
$r = $geo->geocode( 'La Défense' );
check( 'quartier : la ville est celle de Photon', is_array( $r ) && 'Puteaux' === $r['city'] );
check( 'limite non administrative ignorée', null === $geo->geocode( 'Vanoise' ) );
$prefer_ca = function () {
	return 'CA';
};
add_filter( 'g2rd_wpjm_geo_photon_preferred_country', $prefer_ca );
$r = $geo->geocode( 'Laval' );
check( 'filtre du pays préféré pris en compte, cache distinct', is_array( $r ) && 'CA' === $r['country_short'] );
remove_filter( 'g2rd_wpjm_geo_photon_preferred_country', $prefer_ca );
$before = count( $urls );
check( '« Paris France » : premier segment différent, introuvable', null === $geo->geocode( 'Paris France' ) );
$r = $geo->geocode( 'Paris, France' );
check( '« Paris, France » : clé de cache distincte, trouvé', is_array( $r ) && '48.8566' === $r['lat'] && count( $urls ) === $before + 2 );
$r = $geo->geocode( 'Paris 15e' );
check( '« Paris 15e » : trouvé', is_array( $r ) && 'Paris' === $r['city'] );
check( 'commerce renvoyé malgré le filtre : ignoré', null === $geo->geocode( 'Lyon' ) );
$before = count( $urls );
check( 'texte vague (Remote, Télétravail) : null sans requête', null === $geo->geocode( 'Remote' ) && null === $geo->geocode( 'Télétravail' ) && count( $urls ) === $before && $geo->last_was_cached() );
check( 'écriture non latine : null sans requête', null === $geo->geocode( '東京' ) && count( $urls ) === $before );

G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 429 ), 'body' => '' );
};
$e = $geo->geocode( 'Nancy' );
check( 'HTTP 429 : WP_Error g2rd_photon_http après un vrai appel', is_wp_error( $e ) && 'g2rd_photon_http' === $e->get_error_code() && false === $geo->last_was_cached() );
G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 200 ), 'body' => '<html>maintenance</html>' );
};
$e = $geo->geocode( 'Nancy' );
check( 'HTML au lieu de JSON : g2rd_photon_invalid', is_wp_error( $e ) && 'g2rd_photon_invalid' === $e->get_error_code() );
G2RD_Test_State::$http_responder = function ( $method, $url ) {
	if ( false !== strpos( $url, 'osm_tag' ) ) {
		return array( 'response' => array( 'code' => 400 ), 'body' => '' );
	}
	return array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"Language is not supported. Supported are: default, en, de, fr"}' );
};
$e = $geo->geocode( 'Nancy' );
check( 'HTTP 400 sur la langue : erreur de configuration, pas « introuvable »', is_wp_error( $e ) && 'g2rd_photon_config' === $e->get_error_code() );
G2RD_Test_State::$http_responder = function () {
	return photon_reply( array( photon_feature( 'Greenwich', 'place', 'village', 0.00001, 51.4769, array( 'state' => str_repeat( 'a', 500 ) ) ) ) );
};
$r = $geo->geocode( 'Greenwich' );
check( 'coordonnée proche de zéro en notation décimale', is_array( $r ) && '0.00001' === $r['long'] );
check( 'champs tronqués à 200 caractères', is_array( $r ) && 200 === strlen( $r['state_long'] ) );
G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'features' => array( array( 'geometry' => array( 'coordinates' => array( 'a' => 1, 'b' => 2 ) ), 'properties' => array( 'name' => 'Metz', 'osm_key' => 'place', 'osm_value' => 'city' ) ) ) ) ) );
};
check( 'coordonnées mal formées : introuvable, sans avertissement', null === $geo->geocode( 'Metz' ) );
G2RD_Test_State::$locale         = 'en_US';
G2RD_Test_State::$user_locale    = 'de_DE';
G2RD_Test_State::$http_responder = function () {
	return photon_reply( array() );
};
$geo->geocode( 'Caen' );
check( 'langue du site (en_US), pas celle de l’utilisateur (de_DE) : lang=en', false !== strpos( end( G2RD_Test_State::$http_calls ), '&lang=en' ) );

list( $s ) = boot( array( 'photon_url' => 'https://photon.local/api/#ancre' ) );
$geo = new PhotonGeocoder( $s );
add_filter( 'g2rd_wpjm_geo_photon_allow_private_host', '__return_true' );
G2RD_Test_State::$http_responder = function () {
	throw new \RuntimeException( 'filtre tiers en erreur' );
};
$thrown = false;
try {
	$geo->geocode( 'Rouen' );
} catch ( \RuntimeException $e ) {
	$thrown = true;
}
check( 'exception pendant la requête : autorisation d’hôte retirée quand même', $thrown && false === apply_filters( 'http_request_host_is_external', false, 'photon.local' ) );
check( 'fragment retiré de l’URL Photon', 0 === strpos( end( G2RD_Test_State::$http_calls ), 'GET https://photon.local/api/?q=Rouen' ) );

echo PHP_EOL . '17. Géocodage libre des offres' . PHP_EOL;

function geo_boot( $provider = 'osm' ) {
	list( $s ) = boot( array( 'provider' => $provider ) );
	$geocoder = new PhotonGeocoder( $s );
	$GLOBALS['g2rd_sleeps'] = 0;
	$queue = new GeocodeQueue(
		$s,
		$geocoder,
		function () {
			$GLOBALS['g2rd_sleeps']++;
		}
	);
	$queue->register();
	( new GeocodingIntegration( $s, $geocoder, $queue ) )->register();
	G2RD_Test_State::$http_responder = function ( $method, $url ) {
		if ( false !== strpos( $url, 'q=Montr%C3%A9al' ) ) {
			return photon_reply( array( photon_feature( 'Montréal', 'place', 'city', -73.5698, 45.5031, array( 'state' => 'Québec', 'country' => 'Canada', 'countrycode' => 'CA' ) ) ) );
		}
		if ( false !== strpos( $url, 'q=Rouen' ) ) {
			return photon_reply( array( photon_feature( 'Rouen', 'place', 'city', 1.0999, 49.4432, array( 'state' => 'Normandie', 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
		}
		if ( false !== strpos( $url, 'q=Brest' ) ) {
			return new WP_Error( 'http_request_failed', 'timeout' );
		}
		return photon_reply( array() );
	};
	return array( $s, $geocoder, $queue );
}
function job_with_google_coords( $id ) {
	G2RD_Test_State::$post_types[ $id ]               = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['geolocated']       = 1;
	G2RD_Test_State::$meta[ $id ]['geolocation_lat']  = '1.5';
	G2RD_Test_State::$meta[ $id ]['geolocation_long'] = '2.5';
}
$src = GeocodingIntegration::META_SOURCE;

geo_boot();
check( 'mode libre : géocodage Google de WP Job Manager coupé', false === apply_filters( 'job_manager_geolocation_enabled', true ) );
job_with_google_coords( 200 );
do_action( 'job_manager_job_location_edited', 200, 'Montréal, Québec, Canada' );
$m = G2RD_Test_State::$meta[200];
check( 'saisie : coordonnées Photon au format WPJM', '45.5031' === $m['geolocation_lat'] && '-73.5698' === $m['geolocation_long'] && 'Montréal' === $m['geolocation_city'] && 'CA' === $m['geolocation_country_short'] && 1 === $m['geolocated'] );
check( 'saisie : source osm', 'osm' === $m[ $src ] );

job_with_google_coords( 201 );
do_action( 'job_manager_job_location_edited', 201, 'IDF' );
check( 'introuvable : anciennes coordonnées effacées, pas de source', ! isset( G2RD_Test_State::$meta[201]['geolocation_lat'] ) && ! isset( G2RD_Test_State::$meta[201]['geolocated'] ) && ! isset( G2RD_Test_State::$meta[201][ $src ] ) );

job_with_google_coords( 202 );
do_action( 'job_manager_job_location_edited', 202, '' );
check( 'localisation vide : coordonnées effacées', ! isset( G2RD_Test_State::$meta[202]['geolocated'] ) );

G2RD_Test_State::$post_types[203]              = 'post';
G2RD_Test_State::$meta[203]['geolocation_lat'] = '9';
do_action( 'job_manager_job_location_edited', 203, 'Rouen' );
check( 'contenu qui n’est pas une offre : ignoré', '9' === G2RD_Test_State::$meta[203]['geolocation_lat'] );

list( , , $queue ) = geo_boot();
G2RD_Test_State::$doing_cron = true;
job_with_google_coords( 210 );
do_action( 'job_manager_job_location_edited', 210, 'Rouen' );
check( 'différé : aucune requête, anciennes coordonnées effacées', 0 === count( G2RD_Test_State::$http_calls ) && ! isset( G2RD_Test_State::$meta[210]['geolocated'] ) );
$queue->flush();
check( 'différé : offre en file, cron planifié', array( 210 ) === get_option( GeocodeQueue::OPTION ) && false !== wp_next_scheduled( GeocodeQueue::CRON_HOOK ) );
G2RD_Test_State::$meta[210]['_job_location'] = 'Rouen'; // WordPress écrit la valeur après le hook.
unset( G2RD_Test_State::$scheduled[ GeocodeQueue::CRON_HOOK ] ); // WordPress retire l'événement avant de l'exécuter.
do_action( GeocodeQueue::CRON_HOOK );
check( 'cron : offre géocodée, source osm, file vide', 'Rouen' === G2RD_Test_State::$meta[210]['geolocation_city'] && 'osm' === G2RD_Test_State::$meta[210][ $src ] && array() === get_option( GeocodeQueue::OPTION ) );
check( 'cron : pause d’une seconde après un appel réseau', 1 === $GLOBALS['g2rd_sleeps'] );

foreach ( array( 211 => 'Brest', 212 => 'Rouen' ) as $id => $loc ) {
	G2RD_Test_State::$post_types[ $id ]            = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $loc;
	$queue->push( $id );
}
$queue->flush();
unset( G2RD_Test_State::$scheduled[ GeocodeQueue::CRON_HOOK ] );
do_action( GeocodeQueue::CRON_HOOK );
check( 'panne : lot arrêté, offre remise en fin de file', array( 212, 211 ) === get_option( GeocodeQueue::OPTION ) );
check( 'panne : nouvel essai dans 10 minutes', wp_next_scheduled( GeocodeQueue::CRON_HOOK ) >= time() + 590 );
G2RD_Test_State::$doing_cron = false;

list( , , $queue ) = geo_boot();
job_with_google_coords( 213 );
G2RD_Test_State::$meta[213][ $src ] = 'osm';
do_action( 'job_manager_job_location_edited', 213, 'Brest' );
check( 'saisie, Photon en panne : coordonnées et source effacées', ! isset( G2RD_Test_State::$meta[213]['geolocated'] ) && ! isset( G2RD_Test_State::$meta[213][ $src ] ) );
$queue->flush();
check( 'saisie, Photon en panne : offre mise en file', array( 213 ) === get_option( GeocodeQueue::OPTION ) && false !== wp_next_scheduled( GeocodeQueue::CRON_HOOK ) );
job_with_google_coords( 214 );
$calls = count( G2RD_Test_State::$http_calls );
do_action( 'job_manager_job_location_edited', 214, 'Rouen' );
check( 'Photon signalé en panne : saisie suivante différée, sans appel réseau', count( G2RD_Test_State::$http_calls ) === $calls && ! isset( G2RD_Test_State::$meta[214]['geolocated'] ) );

geo_boot();
add_filter( 'g2rd_wpjm_geo_defer_geocoding', '__return_true' );
job_with_google_coords( 215 );
do_action( 'job_manager_job_location_edited', 215, 'Rouen' );
check( 'filtre g2rd_wpjm_geo_defer_geocoding : géocodage différé', 0 === count( G2RD_Test_State::$http_calls ) && ! isset( G2RD_Test_State::$meta[215]['geolocated'] ) );

geo_boot( 'google' );
check( 'mode Google : géocodage de WP Job Manager actif', true === apply_filters( 'job_manager_geolocation_enabled', true ) );
G2RD_Test_State::$post_types[220]   = 'job_listing';
G2RD_Test_State::$meta[220][ $src ] = 'osm';
do_action( 'job_manager_job_location_edited', 220, 'Paris' );
check( 'mode Google : ancienne source osm oubliée', ! isset( G2RD_Test_State::$meta[220][ $src ] ) );

list( $s, , $queue ) = geo_boot( 'google' );
G2RD_Test_State::$post_types[221]              = 'job_listing';
G2RD_Test_State::$meta[221]['_job_location'] = 'Rouen';
update_option( GeocodeQueue::OPTION, array( 221 ) );
do_action( GeocodeQueue::CRON_HOOK );
check( 'file héritée du mode libre : vidée sans géocoder en mode Google', array() === get_option( GeocodeQueue::OPTION ) && ! isset( G2RD_Test_State::$meta[221]['geolocated'] ) );

geo_boot( 'google' );
G2RD_Test_State::$post_types[230] = 'job_listing';
G2RD_Test_State::$meta[230]       = array( 'geolocated' => 1, 'geolocation_lat' => '49.4432', $src => 'osm' );
WP_Job_Manager_Geocode::save_location_data( 230, array( 'lat' => '49.4431', 'long' => '1.0993' ) ); // update_location_data() de WP Job Manager, en mode Google.
check( 'coordonnées écrites par un tiers : source osm retirée', ! isset( G2RD_Test_State::$meta[230][ $src ] ) );

echo PHP_EOL . '18. Carte : filtre par source' . PHP_EOL;
$osm = RestController::source_meta_query( 'osm' );
check( 'mode libre : seulement la source osm', GeocodingIntegration::META_SOURCE === $osm['key'] && 'osm' === $osm['value'] && '=' === $osm['compare'] );
$google = RestController::source_meta_query( 'google' );
check( 'mode Google : source absente ou google', 'OR' === $google['relation'] && 'NOT EXISTS' === $google[0]['compare'] && 'google' === $google[1]['value'] );
list( $s ) = boot( array( 'provider' => 'osm' ) );
$args = ( new RestController( $s ) )->require_coordinates( array() );
check( 'requête : coordonnées exigées et filtre de source', 'AND' === $args['meta_query']['relation'] && $osm === $args['meta_query'][2] );
$args = ( new RestController( $s ) )->require_coordinates( array( 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_featured', 'value' => '1' ) ) ) );
check( 'requête : un relation OR existant ne rend pas les clauses facultatives', 'AND' === $args['meta_query']['relation'] && 'OR' === $args['meta_query'][0]['relation'] && $osm === $args['meta_query'][1][2] );

geo_boot();
job_with_google_coords( 240 );
do_action( 'job_manager_job_location_edited', 240, 'Rouen' );
check( 'source modifiée : cache des offres de WP Job Manager pas encore renouvelé', 0 === G2RD_Test_State::$cache_bumps );
do_action( 'shutdown' );
check( 'source modifiée : cache des offres renouvelé une fois en fin de requête', 1 === G2RD_Test_State::$cache_bumps );

echo PHP_EOL . '19. Rattachement au menu' . PHP_EOL;
boot();
AdminMenu::reset();
$menu = new AdminMenu( '__return_false' );
$menu->attach_to_console( 'g2rd' );
$menu->maybe_add_standalone();
$top = G2RD_Test_State::$menus[0];
check( 'sans le thème G2RD : menu autonome « G2RD Geo »', 1 === count( G2RD_Test_State::$menus ) && 'top' === $top['type'] && 'g2rd-wpjm-geo' === $top['slug'] && 'G2RD Geo' === $top['title'] && 'dashicons-location-alt' === $top['icon'] && 31 === $top['position'] );
check( 'sans le thème : écran reconnu', AdminMenu::is_plugin_screen( 'toplevel_page_g2rd-wpjm-geo' ) && ! AdminMenu::is_attached() && ! AdminMenu::is_plugin_screen( 'job_listing_page_g2rd-wpjm-geo' ) );

require __DIR__ . '/fixtures/fake-admin-console.php';
boot();
AdminMenu::reset();
$menu = new AdminMenu( '__return_false' );
$menu->attach_to_console( 'g2rd' );
$menu->maybe_add_standalone();
$sub = G2RD_Test_State::$menus[0];
check( 'avec la console G2RD : sous-menu du menu « G2RD », pas de menu autonome', 1 === count( G2RD_Test_State::$menus ) && 'sub' === $sub['type'] && 'g2rd' === $sub['parent'] && 'Offres géolocalisées' === $sub['title'] );
check( 'avec la console : écran reconnu', AdminMenu::is_attached() && AdminMenu::is_plugin_screen( 'g2rd_page_g2rd-wpjm-geo' ) );
AdminMenu::reset();

echo PHP_EOL . '20. Re-géocodage des offres' . PHP_EOL;
$rows = array(
	array( 'id' => '300', 'location' => 'Rouen', 'source' => null, 'geolocated' => '1' ),
	array( 'id' => '301', 'location' => 'Montréal', 'source' => 'osm', 'geolocated' => '1' ),
	array( 'id' => '302', 'location' => 'IDF', 'source' => null, 'geolocated' => null ),
	array( 'id' => '303', 'location' => 'Brest', 'source' => null, 'geolocated' => '1' ),
	array( 'id' => '303', 'location' => 'Brest', 'source' => null, 'geolocated' => '1' ),
);
$sum = RegeocodeTool::summarize( $rows, 'osm' );
check( 'résumé libre : 4 offres (doublon ignoré), 2 Google, 1 libre, 1 sans coordonnées', 4 === $sum['total'] && 2 === $sum['google'] && 1 === $sum['osm'] && 1 === $sum['none'] );
check( 'résumé libre : 1 affichée, 3 à re-géocoder', 1 === $sum['shown'] && 3 === $sum['todo'] && array( 300, 302, 303 ) === $sum['todo_ids'] );
$sum = RegeocodeTool::summarize( $rows, 'google' );
check( 'résumé Google : 2 affichées, 2 à re-géocoder', 2 === $sum['shown'] && array( 301, 302 ) === $sum['todo_ids'] );

list( $s ) = geo_boot();
foreach ( $rows as $row ) {
	$id = (int) $row['id'];
	job_with_google_coords( $id );
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $row['location'];
}
$sleeps = 0;
$tool   = new RegeocodeTool(
	$s,
	new PhotonGeocoder( $s ),
	function () use ( &$sleeps ) {
		$sleeps++;
	}
);
$a = $tool->handle( 'analyze', 0, $rows );
check( 'analyse : terminée sans appel réseau', true === $a['done'] && array() === $a['rows'] && 3 === $a['summary']['todo'] && 0 === count( G2RD_Test_State::$http_calls ) && ! isset( $a['summary']['todo_ids'] ) );
$sim = $tool->handle( 'simulate', 0, $rows );
$st  = array_column( $sim['rows'], 'status', 'id' );
check( 'simulation : Rouen prévu, IDF non trouvée, Brest en erreur', 'ok' === $st[300] && 'not_found' === $st[302] && 'error' === $st[303] && 'Rouen, France' === $sim['rows'][0]['result'] );
check( 'simulation : arrêt sur panne, aucune écriture', true === $sim['done'] && true === $sim['stopped'] && '1.5' === G2RD_Test_State::$meta[300]['geolocation_lat'] && ! isset( G2RD_Test_State::$meta[300][ $src ] ) );
check( 'simulation : lien de modification fourni', 'https://example.test/wp-admin/post.php?post=300&action=edit' === $sim['rows'][0]['edit'] );
G2RD_Test_State::$http_responder = function ( $method, $url ) {
	if ( false !== strpos( $url, 'q=Rouen' ) ) {
		return photon_reply( array( photon_feature( 'Rouen', 'place', 'city', 1.0999, 49.4432, array( 'country' => 'France' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Brest' ) ) {
		return photon_reply( array( photon_feature( 'Brest', 'place', 'city', -4.4861, 48.3904, array( 'country' => 'France' ) ) ) );
	}
	return photon_reply( array() );
};
$before = $sleeps;
$run    = $tool->handle( 'run', 0, $rows );
$st     = array_column( $run['rows'], 'status', 'id' );
check( 'exécution : Rouen et Brest écrits, IDF non trouvée', 'done' === $st[300] && 'done' === $st[303] && 'not_found' === $st[302] && true === $run['done'] );
check( 'exécution : pause seulement après un vrai appel (Brest), pas sur le cache (Rouen, IDF)', 1 === $sleeps - $before );
check( 'exécution : coordonnées libres et source osm', '49.4432' === G2RD_Test_State::$meta[300]['geolocation_lat'] && 'osm' === G2RD_Test_State::$meta[300][ $src ] && 'osm' === G2RD_Test_State::$meta[303][ $src ] );
check( 'exécution : offre non trouvée laissée intacte', '1.5' === G2RD_Test_State::$meta[302]['geolocation_lat'] );
check( 'exécution : curseur sur la dernière offre traitée', 303 === $run['cursor'] );

list( $s ) = geo_boot( 'google' );
foreach ( array( 310 => 'Paris', 311 => 'Nulle part' ) as $id => $loc ) {
	G2RD_Test_State::$post_types[ $id ]            = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $loc;
	G2RD_Test_State::$meta[ $id ]['geolocated']    = 1;
	G2RD_Test_State::$meta[ $id ][ $src ]          = 'osm';
}
G2RD_Test_State::$wpjm_geocode['Paris'] = array( 'lat' => '48.85', 'long' => '2.35', 'city' => 'Paris', 'country_long' => 'France' );
$grows = array(
	array( 'id' => '310', 'location' => 'Paris', 'source' => 'osm', 'geolocated' => '1' ),
	array( 'id' => '311', 'location' => 'Nulle part', 'source' => 'osm', 'geolocated' => '1' ),
);
$tool = new RegeocodeTool( $s, new PhotonGeocoder( $s ), '__return_false' );
$sim  = $tool->handle( 'simulate', 0, $grows );
check( 'Google, simulation : aucune requête, à l’exécution', 'pending' === $sim['rows'][0]['status'] && 0 === count( G2RD_Test_State::$http_calls ) );
$run = $tool->handle( 'run', 0, $grows );
$st  = array_column( $run['rows'], 'status', 'id' );
check( 'Google, exécution : Paris géocodée par WPJM, source google', 'done' === $st[310] && 'google' === G2RD_Test_State::$meta[310][ $src ] && '48.85' === G2RD_Test_State::$meta[310]['geolocation_lat'] );
check( 'Google, exécution : échec de géocodage listé, offre intacte', 'not_found' === $st[311] && 'osm' === G2RD_Test_State::$meta[311][ $src ] );
foreach ( array( 312 => 'Lyon', 313 => ',' ) as $id => $loc ) {
	G2RD_Test_State::$post_types[ $id ]            = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $loc;
	G2RD_Test_State::$meta[ $id ]['geolocated']    = 1;
	G2RD_Test_State::$meta[ $id ][ $src ]          = 'osm';
}
$lyon = array( array( 'id' => '312', 'location' => 'Lyon', 'source' => 'osm', 'geolocated' => '1' ) );
G2RD_Test_State::$wpjm_geocode['Lyon'] = new WP_Error( 'error', 'Geocoding error' );
$run = $tool->handle( 'run', 0, $lyon );
check( 'Google, clé refusée ou panne : erreur et arrêt, offre intacte', 'error' === $run['rows'][0]['status'] && true === $run['stopped'] && 'osm' === G2RD_Test_State::$meta[312][ $src ] );
G2RD_Test_State::$wpjm_geocode['Lyon'] = false;
$run = $tool->handle( 'run', 0, $lyon );
check( 'Google, quota mémorisé ou géocodage coupé : erreur et arrêt', 'error' === $run['rows'][0]['status'] && true === $run['stopped'] );
$run = $tool->handle( 'run', 0, array( array( 'id' => '313', 'location' => ',', 'source' => 'osm', 'geolocated' => '1' ) ) );
check( 'localisation sans rien de géocodable : non trouvée, sans arrêt', 'not_found' === $run['rows'][0]['status'] && false === $run['stopped'] );

echo PHP_EOL . '21. Adresse de la page' . PHP_EOL;
check( 'page_url pointe sur admin.php', 'https://example.test/wp-admin/admin.php?page=g2rd-wpjm-geo' === Settings::page_url() );
AdminMenu::reset();
check( 'is_settings_screen suit AdminMenu (aucune page enregistrée)', false === Settings::is_settings_screen( 'job_listing_page_g2rd-wpjm-geo' ) );

// Ressources et gabarit de la page (la console simulée est chargée depuis la section 19).
boot();
AdminMenu::reset();
$menu   = new AdminMenu( '__return_false' );
$screen = new AdminScreen();
$menu->maybe_add_standalone();
$screen->enqueue( 'edit.php' );
check( 'autre écran : aucune ressource', array() === G2RD_Test_State::$enqueued );
$screen->enqueue( 'toplevel_page_g2rd-wpjm-geo' );
check( 'menu autonome : copie de la feuille, polices et script', isset( G2RD_Test_State::$enqueued['style:g2rd-wpjm-geo-console'], G2RD_Test_State::$enqueued['inline:g2rd-wpjm-geo-console'], G2RD_Test_State::$enqueued['script:g2rd-wpjm-geo-admin-settings'] ) );
AdminMenu::reset();
G2RD_Test_State::$enqueued    = array();
\G2RD\AdminConsole::$styles = 0;
$menu->attach_to_console( 'g2rd' );
$screen->enqueue( 'g2rd_page_g2rd-wpjm-geo' );
check( 'rattaché : feuille du thème chargée, pas la copie', 1 === \G2RD\AdminConsole::$styles && ! isset( G2RD_Test_State::$enqueued['style:g2rd-wpjm-geo-console'] ) && isset( G2RD_Test_State::$enqueued['script:g2rd-wpjm-geo-admin-settings'] ) );
AdminScreen::open( array( 'title' => 'Titre délégué' ) );
check( 'rattaché : gabarit délégué à la console du thème', 'Titre délégué' === end( \G2RD\AdminConsole::$opened )['title'] );
AdminMenu::reset();

echo PHP_EOL . '22. Rendu de la page de réglages' . PHP_EOL;
list( $s ) = boot( array( 'provider' => 'google', 'browser_api_key' => 'AIza"<k>' ) );
AdminMenu::reset();
ob_start();
( new \G2RD\WPJMGeo\SettingsPage( $s ) )->render();
$html    = ob_get_clean();
$missing = array();
foreach ( array_keys( Settings::defaults() ) as $key ) {
	if ( false === strpos( $html, 'name="' . Settings::OPTION . '[' . $key . ']"' ) ) {
		$missing[] = $key;
	}
}
check( 'chaque réglage a son champ (une case absente serait enregistrée décochée)', array() === $missing );
check( 'adresse générique : option dédiée dans le formulaire', false !== strpos( $html, 'name="' . Settings::FALLBACK_EMAIL_OPTION . '"' ) );
check( 'formulaire options.php du groupe enregistré', false !== strpos( $html, 'action="options.php"' ) && false !== strpos( $html, 'name="option_page" value="' . Settings::GROUP . '"' ) );
check( 'valeur de la clé échappée', false !== strpos( $html, 'value="AIza&quot;&lt;k&gt;"' ) && false === strpos( $html, 'AIza"<k>' ) );
check( 'mode Google : lignes OpenStreetMap masquées mais présentes', 3 === substr_count( $html, 'data-g2rd-provider="osm" style="display:none"' ) && false === strpos( $html, 'data-g2rd-provider="google" style=' ) );
check( 'remplacement Go Fetch Jobs coché par défaut', 1 === preg_match( '/is-checked"><input[^>]+name="g2rd_wpjm_geo_settings\[gofetch_replace\]"[^>]+checked/', $html ) );
preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
preg_match_all( '/aria-(?:describedby|labelledby)="([^"]+)"/', $html, $refs );
check( 'aucune référence ARIA pendante', array() === array_diff( $refs[1], $ids[1] ) );
AdminMenu::reset();

echo PHP_EOL . '23. Outil Go Fetch Jobs et journal : rendu' . PHP_EOL;
list( $s, $normalizer ) = boot( array( 'log_enabled' => true ) );
$hostile_log = new class( $s ) extends Logger {
	public function latest( $limit = 50 ) {
		return array( array( 'id' => 1, 'job_id' => 7, 'created_at' => '2026-01-01 00:00:00', 'old_value' => 'javascript:alert(1)//"><svg onload=alert(1)>', 'new_value' => '<b>x</b>@ex.fr', 'reason' => '<i>r</i>', 'source' => '<a href="javascript:alert(2)">x</a>' ) );
	}
};
ob_start();
( new MigrationTool( $s, $normalizer, $hostile_log ) )->render();
$html = ob_get_clean();
check( 'journal : valeurs importées échappées', false === strpos( $html, '<svg' ) && false === strpos( $html, '<b>x' ) && false === strpos( $html, '<i>r' ) && false === strpos( $html, 'href="javascript' ) && false !== strpos( $html, 'javascript:alert(1)//&quot;&gt;&lt;svg' ) );
check( 'outil : points d’accroche du script présents', 1 === preg_match( '/class="g2rd-wpjm-geo-tools"[^>]*data-ajax-url="[^"]+"[^>]*data-nonce="[^"]+"[^>]*data-i18n="/s', $html ) && false !== strpos( $html, 'g2rd-wpjm-geo-tools__rows' ) && false !== strpos( $html, 'data-g2rd-run="fix"' ) && false !== strpos( $html, 'data-g2rd-dry-run' ) );
check( 'sections équilibrées', 2 === substr_count( $html, '<section' ) && 2 === substr_count( $html, '</section>' ) );

echo PHP_EOL . '24. Réglages visibles côté public : cache de pages' . PHP_EOL;
if ( ! function_exists( 'rocket_clean_domain' ) ) {
	function rocket_clean_domain() {
		$GLOBALS['g2rd_rocket_purges']++;
	}
}
$GLOBALS['g2rd_rocket_purges'] = 0;
Settings::maybe_purge_page_cache( array( 'provider' => 'google', 'log_retention_days' => 90 ), array( 'provider' => 'google', 'log_retention_days' => 30 ) );
check( 'réglage d’administration seul : pas de purge', 0 === $GLOBALS['g2rd_rocket_purges'] );
Settings::maybe_purge_page_cache( array( 'provider' => 'google' ), array( 'provider' => 'osm' ) );
check( 'fournisseur changé : cache de pages purgé', 1 === $GLOBALS['g2rd_rocket_purges'] );
Settings::maybe_purge_page_cache( array( 'map_height' => 480 ), array( 'map_height' => 600 ) );
check( 'hauteur de la carte changée : cache de pages purgé', 2 === $GLOBALS['g2rd_rocket_purges'] );
Settings::maybe_purge_page_cache_on_add( Settings::OPTION, array( 'provider' => 'osm' ) );
check( 'premier enregistrement (add_option) : cache de pages purgé', 3 === $GLOBALS['g2rd_rocket_purges'] );
Settings::maybe_purge_page_cache_on_add( Settings::OPTION, Settings::defaults() );
check( 'premier enregistrement aux valeurs par défaut : pas de purge', 3 === $GLOBALS['g2rd_rocket_purges'] );

echo PHP_EOL . '25. Texte de confidentialité' . PHP_EOL;
list( $s ) = boot( array( 'provider' => 'osm', 'photon_url' => 'https://photon.mon-serveur.fr/api/' ) );
$text = ( new PrivacyPolicy( $s ) )->text();
check( 'mode libre : hôtes réellement configurés cités', false !== strpos( $text, 'tiles.openfreemap.org' ) && false !== strpos( $text, 'photon.mon-serveur.fr' ) && false === strpos( $text, 'Google' ) );
list( $s ) = boot();
check( 'mode Google : Google cité', false !== strpos( ( new PrivacyPolicy( $s ) )->text(), 'Google' ) );

echo PHP_EOL . '26. Homonymes d’une même région' . PHP_EOL;
$features = array(
	photon_feature( 'Saint-Martin', 'place', 'village', 0.1, 43.1, array( 'county' => 'Hautes-Pyrénées', 'state' => 'Occitanie', 'country' => 'France', 'countrycode' => 'FR' ) ),
	photon_feature( 'Saint-Martin', 'place', 'village', 0.5, 43.6, array( 'county' => 'Gers', 'state' => 'Occitanie', 'country' => 'France', 'countrycode' => 'FR' ) ),
);
$best = PhotonGeocoder::pick( $features, 'Saint-Martin, Gers, Occitanie, France' );
check( 'département cité : il départage deux homonymes de la même région', is_array( $best ) && 'Gers' === $best['properties']['county'] );

echo PHP_EOL . '27. Configuration de l’autocomplétion' . PHP_EOL;
function g2rd_ac_config() {
	$inline = isset( G2RD_Test_State::$enqueued['inline:g2rd-wpjm-geo-autocomplete'] ) ? G2RD_Test_State::$enqueued['inline:g2rd-wpjm-geo-autocomplete'] : '';
	return array( $inline, json_decode( (string) preg_replace( '/^window\.g2rdWpjmGeoAutocomplete = |;$/', '', $inline ), true ) );
}
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_OSM, 'browser_api_key' => 'SECRET-KEY' ) );
( new GoogleAssets( $s ) )->register_assets();
check( 'mode libre : autocomplétion sans dépendance au chargeur Google', array() === G2RD_Test_State::$enqueued['register:g2rd-wpjm-geo-autocomplete'] );
( new LocationAutocomplete( $s ) )->enqueue();
list( $inline, $c ) = g2rd_ac_config();
check( 'mode libre : Photon en https, langue du site, attribution OpenStreetMap, aucune clé', 'photon' === $c['source'] && 'https://photon.komoot.io/api/' === $c['photonUrl'] && 'fr' === $c['lang'] && false !== strpos( $c['i18n']['poweredBy'], 'OpenStreetMap' ) && false === strpos( $inline, 'SECRET' ) );
check( 'mode libre : chargeur Google absent', ! isset( G2RD_Test_State::$enqueued['script:g2rd-wpjm-geo-google-loader'] ) );
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_GOOGLE, 'browser_api_key' => 'BROWSER' ) );
G2RD_Test_State::$locale = 'es_ES';
( new GoogleAssets( $s ) )->register_assets();
check( 'mode Google : dépendance au chargeur', array( GoogleAssets::HANDLE_LOADER ) === G2RD_Test_State::$enqueued['register:g2rd-wpjm-geo-autocomplete'] );
( new LocationAutocomplete( $s ) )->enqueue();
list( $inline, $c ) = g2rd_ac_config();
check( 'mode Google : pas d’URL Photon, langue non prise en charge par Photon = default', 'google' === $c['source'] && '' === $c['photonUrl'] && 'default' === $c['lang'] );
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_GOOGLE, 'browser_api_key' => '' ) );
( new LocationAutocomplete( $s ) )->enqueue();
check( 'mode Google sans clé : rien en file', array() === G2RD_Test_State::$enqueued );

echo PHP_EOL . '28. Autocomplétion avec un thème bloc' . PHP_EOL;
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_OSM ) );
( new LocationAutocomplete( $s ) )->enqueue();
check( 'script pas encore enregistré : configuration différée', ! isset( G2RD_Test_State::$enqueued['inline:g2rd-wpjm-geo-autocomplete'] ) );
do_action( 'wp_enqueue_scripts' );
list( $inline, $c ) = g2rd_ac_config();
check( 'configuration ajoutée après l’enregistrement des scripts', is_array( $c ) && 'photon' === $c['source'] );

echo PHP_EOL . '29. Re-géocodage : offres publiées d’abord' . PHP_EOL;
$mixed = array(
	array( 'id' => '400', 'status' => 'expired', 'location' => 'Rouen', 'source' => null, 'geolocated' => null ),
	array( 'id' => '401', 'status' => 'publish', 'location' => 'Rouen', 'source' => null, 'geolocated' => null ),
	array( 'id' => '402', 'status' => 'draft', 'location' => 'Rouen', 'source' => null, 'geolocated' => null ),
);
$sum = RegeocodeTool::summarize( $mixed, 'osm' );
check( 'offres publiées traitées avant les autres', array( 401, 400, 402 ) === $sum['todo_ids'] );
list( $s ) = geo_boot();
foreach ( $mixed as $row ) {
	G2RD_Test_State::$post_types[ (int) $row['id'] ]            = 'job_listing';
	G2RD_Test_State::$meta[ (int) $row['id'] ]['_job_location'] = 'Rouen';
}
$first = ( new RegeocodeTool( $s, new PhotonGeocoder( $s ), '__return_false' ) )->handle( 'simulate', 0, $mixed );
check( 'premier lot : offre publiée en tête, curseur de parcours', 401 === $first['rows'][0]['id'] && $first['cursor'] > RegeocodeTool::KEY_OFFSET && true === $first['done'] );

echo PHP_EOL . '30. Correctifs de l’audit de sécurité' . PHP_EOL;
list( , $normalizer ) = boot();
// Formes conservées par le filtre de stockage de WP Job Manager (esc_url_raw) que WPJM affiche
// pourtant comme lien de candidature (« http:// » + valeur) vers l'hôte choisi par le flux.
$bypass = array(
	300 => '//apply.attacker.example/job/1',
	301 => '/apply.attacker.example/job/1',
	302 => 'jobs@apply.attacker.example/?ref=a:b',
	303 => 'apply_attacker.example/?ref=a:b',
	304 => 'a.php_x.attacker.example/job/1',
);
foreach ( $bypass as $id => $value ) {
	check( sprintf( 'classify() : « %s » est une URL', $value ), ApplicationNormalizer::TYPE_URL === $normalizer->classify( $value ) );
	gofetch_import( $id, $value );
	check( sprintf( 'import Go Fetch : « %s » remplacée', $value ), DEF === application( $id ) );
}
check( 'classify() : une adresse email reste un email', ApplicationNormalizer::TYPE_EMAIL === $normalizer->classify( 'rh@pompiersparis.fr' ) );
check( 'classify() : une valeur vide reste vide', ApplicationNormalizer::TYPE_EMPTY === $normalizer->classify( '   ' ) );

boot();
G2RD_Test_State::$translations['L’URL du serveur Photon doit être une adresse https:// valide, sans identifiant : elle n’a pas été enregistrée.'] = '<img src=x onerror=alert(1)>Photon';
( new Settings() )->sanitize( array( 'photon_url' => 'http://non-securise.test/' ) );
$notice = isset( G2RD_Test_State::$errors[0]['message'] ) ? G2RD_Test_State::$errors[0]['message'] : '';
check( 'message de réglage traduit : balisage échappé avant settings_errors()', '' !== $notice && false === strpos( $notice, '<img' ) && false !== strpos( $notice, '&lt;img' ) );

boot();
G2RD_Test_State::$translations['G2RD Geo']             = '<b>G2RD Geo</b>';
G2RD_Test_State::$translations['Offres géolocalisées'] = '<b>Offres</b>';
AdminMenu::reset();
( new AdminMenu( '__return_false' ) )->maybe_add_standalone();
check( 'menu autonome : titre traduit échappé', '&lt;b&gt;G2RD Geo&lt;/b&gt;' === G2RD_Test_State::$menus[0]['title'] );
AdminMenu::reset();
G2RD_Test_State::$menus = array();
( new AdminMenu( '__return_false' ) )->attach_to_console( 'g2rd' );
check( 'sous-menu de la console G2RD : titre traduit échappé', '&lt;b&gt;Offres&lt;/b&gt;' === G2RD_Test_State::$menus[0]['title'] );
AdminMenu::reset();
G2RD_Test_State::$translations = array();

$header     = (string) file_get_contents( dirname( __DIR__ ) . '/g2rd-wpjm-geo.php', false, null, 0, 4096 );
$update_uri = preg_match( '/^\s*\*\s*Update URI:\s*(\S+)/m', $header, $m ) ? $m[1] : '';
$update_host = (string) parse_url( $update_uri, PHP_URL_HOST );
check( 'en-tête Update URI vers le dépôt GitHub : ni mise à jour ni paquet de langue par slug wordpress.org', 'https://github.com/SebG2RD/g2rd-wp-job-manager-geo' === $update_uri && ! in_array( $update_host, array( 'wordpress.org', 'w.org' ), true ) );

echo PHP_EOL . '31. Cache Photon : une variante ne décide pas du résultat des autres offres' . PHP_EOL;
list( $s ) = boot();
$geo         = new PhotonGeocoder( $s );
$paris_calls = 0;
// Réponses relevées sur photon.komoot.io le 2026-10-17 pour ces trois textes.
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$paris_calls ) {
	if ( false !== strpos( $url, 'q=Paris&' ) || false !== strpos( $url, 'q=PARIS&' ) ) {
		$paris_calls++;
		return photon_reply( array( photon_feature( 'Paris', 'place', 'city', 2.3522, 48.8566, array( 'state' => 'Île-de-France', 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
	}
	if ( false !== strpos( $url, '%D0%9C' ) ) { // « Москва… » : aucun résultat.
		return photon_reply( array() );
	}
	if ( false !== strpos( $url, '%EF%BC%B4' ) ) { // « ＴＥＸＡＳ » pleine chasse : seul Paris (Texas).
		return photon_reply( array( photon_feature( 'Paris', 'place', 'town', -95.5555, 33.6609, array( 'state' => 'Texas', 'country' => 'États-Unis', 'countrycode' => 'US' ) ) ) );
	}
	return photon_reply( array() );
};
check( 'variante cyrillique : introuvable', null === $geo->geocode( 'Paris Москва Токио Пекин' ) );
$paris = $geo->geocode( 'Paris' );
check( 'puis « Paris » : sa propre requête, Paris (France)', 1 === $paris_calls && is_array( $paris ) && 'FR' === $paris['country_short'] );
$texas = $geo->geocode( 'Paris ＴＥＸＡＳ' );
check( 'variante pleine chasse : sa propre requête, pas le résultat mis en cache pour « Paris »', is_array( $texas ) && 'US' === $texas['country_short'] );
$again = $geo->geocode( 'Paris' );
check( '« Paris » de nouveau : servi par le cache, toujours la France', is_array( $again ) && 'FR' === $again['country_short'] && 1 === $paris_calls );
$upper = $geo->geocode( 'PARIS' );
check( 'même texte en majuscules : servi par le même cache', is_array( $upper ) && 'FR' === $upper['country_short'] && 1 === $paris_calls );
G2RD_Test_State::$http_responder = null;

echo PHP_EOL . '32. Régions françaises' . PHP_EOL;
check( '18 régions, codes INSEE', array( '01', '02', '03', '04', '06', '11', '24', '27', '28', '32', '44', '52', '53', '75', '76', '84', '93', '94' ) === Regions::codes() );
$n = Regions::find( 'Normandie' );
check( 'nom officiel : code, nom, centre', is_array( $n ) && '28' === $n['code'] && 'Normandie' === $n['name'] && '49.1202' === $n['lat'] && '0.1128' === $n['lng'] );
check( 'accents, casse, tirets et article ignorés', '11' === Regions::find( 'ile de france' )['code'] && '11' === Regions::find( "l'Île-de-France" )['code'] && '04' === Regions::find( 'la réunion' )['code'] && '93' === Regions::find( 'provence alpes cote d azur' )['code'] );
check( 'sigles et variantes', '11' === Regions::find( 'IDF' )['code'] && '11' === Regions::find( 'Région parisienne' )['code'] && '93' === Regions::find( 'paca' )['code'] && '93' === Regions::find( 'Région Sud' )['code'] && '84' === Regions::find( 'AURA' )['code'] && '32' === Regions::find( 'HDF' )['code'] && '27' === Regions::find( 'BFC' )['code'] && '24' === Regions::find( 'Région Centre' )['code'] && '04' === Regions::find( 'Réunion' )['code'] && '03' === Regions::find( 'Guyane française' )['code'] );
check( 'anciennes régions rattachées à l’actuelle', '44' === Regions::find( 'Alsace' )['code'] && '75' === Regions::find( 'Poitou-Charentes' )['code'] && '84' === Regions::find( 'Rhône-Alpes' )['code'] && '27' === Regions::find( 'Franche-Comté' )['code'] && '76' === Regions::find( 'Midi-Pyrénées' )['code'] && '32' === Regions::find( 'Picardie' )['code'] && '28' === Regions::find( 'Haute-Normandie' )['code'] );
check( 'préfixe « Région » accepté', '53' === Regions::find( 'Région Bretagne' )['code'] );
$every = 0;
foreach ( Regions::DATA as $code => $row ) {
	$every += ( (string) $code === Regions::find( $row[0] )['code'] ) ? 1 : 0;
}
foreach ( Regions::ALIASES as $alias => $code ) {
	$every += ( $code === Regions::find( (string) $alias )['code'] ) ? 1 : 0;
}
check( 'chaque nom officiel et chaque variante retrouve sa région (44 entrées)', 44 === $every );
check( 'apostrophe typographique, « Région » seul, nom complet malgré la variante « Région Centre »', '11' === Regions::find( 'l’Île-de-France' )['code'] && null === Regions::find( 'Région' ) && '24' === Regions::find( 'Région Centre-Val de Loire' )['code'] );
check( 'texte qui n’est pas une région : null', null === Regions::find( 'Rouen' ) && null === Regions::find( 'Centre' ) && null === Regions::find( 'Bretagne (90)' ) && null === Regions::find( '' ) && null === Regions::get( '99' ) );
$all = Regions::all();
check( 'liste pour l’autocomplétion : 18 entrées, codes en chaîne, autres noms', 18 === count( $all ) && '11' === $all[5]['code'] && 'Île-de-France' === $all[5]['name'] && in_array( 'IDF', $all[5]['aliases'], true ) && in_array( 'Alsace', $all[10]['aliases'], true ) && array() === $all[0]['aliases'] );

$shapes = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/assets/data/regions-fr.geojson' ), true );
$codes  = array_map(
	function ( $feature ) {
		return $feature['properties']['code'];
	},
	$shapes['features']
);
sort( $codes );
check( 'contours embarqués : les 18 régions, propriétés code et nom seulement', Regions::codes() === $codes && array( 'code', 'nom' ) === array_keys( $shapes['features'][0]['properties'] ) );
function g2rd_in_ring( array $point, array $ring ) {
	$inside = false;
	for ( $i = 0, $j = count( $ring ) - 1; $i < count( $ring ); $j = $i++ ) {
		if ( ( $ring[ $i ][1] > $point[1] ) !== ( $ring[ $j ][1] > $point[1] ) && $point[0] < ( $ring[ $j ][0] - $ring[ $i ][0] ) * ( $point[1] - $ring[ $i ][1] ) / ( $ring[ $j ][1] - $ring[ $i ][1] ) + $ring[ $i ][0] ) {
			$inside = ! $inside;
		}
	}
	return $inside;
}
$inside = 0;
$names  = 0;
foreach ( $shapes['features'] as $feature ) {
	$region   = Regions::get( $feature['properties']['code'] );
	$polygons = 'Polygon' === $feature['geometry']['type'] ? array( $feature['geometry']['coordinates'] ) : $feature['geometry']['coordinates'];
	$names   += ( $region && $region['name'] === $feature['properties']['nom'] ) ? 1 : 0;
	foreach ( $polygons as $polygon ) {
		if ( $region && g2rd_in_ring( array( (float) $region['lng'], (float) $region['lat'] ), $polygon[0] ) ) {
			++$inside;
			break;
		}
	}
}
check( 'noms identiques à ceux des contours', 18 === $names );
check( 'chaque centre est à l’intérieur du contour de sa région', 18 === $inside );

echo PHP_EOL . '33. Découpage du champ « Lieu »' . PHP_EOL;
check( 'séparateurs forts : ; | / retour à la ligne', array( 'Paris', 'Lyon', 'Lille', 'Nantes', 'Brest' ) === PlaceParser::split( "Paris ; Lyon|Lille / Nantes\nBrest" ) );
check( '« et » entouré d’espaces, casse ignorée', array( 'Paris', 'Lyon', 'Caen' ) === PlaceParser::split( 'Paris et Lyon ET Caen' ) );
check( 'tirets et virgules ne séparent pas', array( 'Ille-et-Vilaine', 'Rouen, Normandie' ) === PlaceParser::split( 'Ille-et-Vilaine ; Rouen, Normandie' ) );
check( 'ponctuation de bord et morceaux vides retirés, parenthèses gardées', array( 'Laval (53)', 'Rouen' ) === PlaceParser::split( ' ; - Laval (53). ;; , Rouen, ' ) );
check( 'mentions vagues écartées', array( 'Paris' ) === PlaceParser::split( 'Télétravail / Paris ; Remote' ) );
check( 'doublons fusionnés (accents, casse)', array( 'Évreux', 'Lyon' ) === PlaceParser::split( 'Évreux ; EVREUX ; Lyon ; lyon' ) );
check( 'texte vide ou UTF-8 invalide : aucun morceau, sans avertissement', array() === PlaceParser::split( '' ) && array() === PlaceParser::split( "Lyon \xC3\x28" ) );
check( 'au plus 30 morceaux', 30 === count( PlaceParser::split( implode( ';', range( 1, 50 ) ) ) ) );
check( 'texte importé : entités décodées, balises retirées (<br> vaut un retour à la ligne)', array( "Villeneuve-d'Ascq", 'Paris & Lyon', 'Lille' ) === PlaceParser::split( 'Villeneuve-d&#039;Ascq ; Paris &amp; Lyon<br />Lille' ) );
$long  = 'Paris' . str_repeat( "\u{00A0}", 20000 ) . 'b ; Lyon';
$start = microtime( true );
$parts = PlaceParser::split( $long );
check( 'texte démesuré : longueur bornée à 2 000 caractères avant les expressions régulières', array( 'Paris' ) === $parts && microtime( true ) - $start < 1 );

echo PHP_EOL . '34. Géocodeur : département et nom du résultat, cache' . PHP_EOL;
list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'q=Rouen' ) ) {
		return photon_reply( array( photon_feature( 'Rouen', 'place', 'city', 1.0999, 49.4432, array( 'county' => 'Seine-Maritime', 'state' => 'Normandie', 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
	}
	return photon_reply( array() );
};
check( 'texte jamais vu : pas en cache, aucune requête', false === $geo->is_cached( 'Rouen, Seine-Maritime' ) && 0 === count( $urls ) );
$d = $geo->geocode_detailed( 'Rouen, Seine-Maritime' );
check( 'résultat détaillé : adresse WPJM, département, nom', is_array( $d ) && '49.4432' === $d['address']['lat'] && 'Seine-Maritime' === $d['county'] && 'Rouen' === $d['name'] && ! isset( $d['address']['county'] ) && false === $geo->last_was_cached() );
check( 'puis en cache', $geo->is_cached( 'Rouen, Seine-Maritime' ) && 1 === count( $urls ) );
$again = $geo->geocode_detailed( 'Rouen, Seine-Maritime' );
check( 'département relu depuis le cache, sans requête', is_array( $again ) && 'Seine-Maritime' === $again['county'] && $geo->last_was_cached() && 1 === count( $urls ) );
$address = $geo->geocode( 'Rouen, Seine-Maritime' );
check( 'geocode() inchangé : adresse seule', is_array( $address ) && '1.0999' === $address['long'] && ! isset( $address['address'] ) );
check( 'introuvable : null, mis en cache', null === $geo->geocode_detailed( 'Atlantide' ) && $geo->is_cached( 'Atlantide' ) );
check( 'texte sans lieu (vague, vide) : rien à demander à Photon', $geo->is_cached( 'Télétravail' ) && $geo->is_cached( ' (75) ' ) );
check( 'version du cache : 4', '4' === PhotonGeocoder::CACHE_VERSION );
check( 'écriture non latine : rien à demander à Photon', $geo->is_cached( '東京' ) && 2 === count( $urls ) );
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	return array( 'response' => array( 'code' => 503 ), 'body' => '' );
};
$down = $geo->geocode_detailed( 'Caen' );
check( 'panne de Photon : erreur non mise en cache, l’appel suivant interroge Photon', is_wp_error( $down ) && false === $geo->is_cached( 'Caen' ) && is_wp_error( $geo->geocode_detailed( 'Caen' ) ) && 4 === count( $urls ) );
G2RD_Test_State::$transients[ array_keys( G2RD_Test_State::$transients )[0] ] = array( 'r' => array( 'foo' => 'bar' ) );
check( 'entrée de cache mal formée (sans coordonnées) : ignorée', null === $geo->geocode_detailed( 'Rouen, Seine-Maritime' ) );
G2RD_Test_State::$http_responder = null;

echo PHP_EOL . '35. Lieux d’une offre : règle de la virgule' . PHP_EOL;

/** Villes connues du Photon simulé : longitude, latitude, département, région, pays, code pays, code postal. */
function g2rd_world_feature( $name ) {
	$cities = array(
		'Rouen'    => array( 1.0999, 49.4432, 'Seine-Maritime', 'Normandie', 'France', 'FR', '76000' ),
		'Caen'     => array( -0.3707, 49.1829, 'Calvados', 'Normandie', 'France', 'FR', '14000' ),
		'Le Havre' => array( 0.1079, 49.4938, 'Seine-Maritime', 'Normandie', 'France', 'FR', '76600' ),
		'Paris'    => array( 2.3522, 48.8566, 'Paris', 'Île-de-France', 'France', 'FR', '75001' ),
		'Lyon'     => array( 4.8357, 45.764, 'Métropole de Lyon', 'Auvergne-Rhône-Alpes', 'France', 'FR', '69001' ),
		'Brest'    => array( -4.4861, 48.3904, 'Finistère', 'Bretagne', 'France', 'FR', '29200' ),
		'Bretagne' => array( 6.9917, 47.5986, 'Territoire de Belfort', 'Bourgogne-Franche-Comté', 'France', 'FR', '90130' ),
		'London'   => array( -81.2497, 42.9837, 'Middlesex County', 'Ontario', 'Canada', 'CA', 'N6A' ),
	);
	list( $lng, $lat, $county, $state, $country, $code, $postcode ) = $cities[ $name ];
	return photon_feature( $name, 'place', 'city', $lng, $lat, array( 'county' => $county, 'state' => $state, 'country' => $country, 'countrycode' => $code, 'postcode' => $postcode ) );
}
/**
 * Photon simulé : $replies associe un texte exact envoyé à Photon aux villes renvoyées
 * (dans l'ordre de Photon) ; un texte égal au nom d'une ville connue renvoie cette ville.
 */
function g2rd_world( array $replies ) {
	$GLOBALS['g2rd_world_queries']   = array();
	G2RD_Test_State::$http_responder = function ( $method, $url ) use ( $replies ) {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$q                                 = isset( $query['q'] ) ? (string) $query['q'] : '';
		$GLOBALS['g2rd_world_queries'][] = $q;
		$known                             = array( 'Rouen', 'Caen', 'Le Havre', 'Paris', 'Lyon', 'Brest', 'Bretagne', 'London' );
		$names                             = isset( $replies[ $q ] ) ? $replies[ $q ] : ( in_array( $q, $known, true ) ? array( $q ) : array() );
		return photon_reply( array_map( 'g2rd_world_feature', $names ) );
	};
}
/** « type:libellé » de chaque lieu, joints par « | ». */
function g2rd_labels( $result ) {
	if ( ! is_array( $result ) ) {
		return 'erreur';
	}
	return implode(
		' | ',
		array_map(
			function ( $place ) {
				return $place['type'] . ':' . $place['label'];
			},
			$result['places']
		)
	);
}

list( $s ) = boot();
$sleeps    = 0;
$locator   = new PlaceLocator(
	new PhotonGeocoder( $s ),
	function () use ( &$sleeps ) {
		$sleeps++;
	}
);
g2rd_world(
	array(
		'London, Ontario, Canada' => array( 'London' ),
		'Rouen, Normandie'        => array( 'Rouen' ),
		'Rouen, Seine-Maritime'   => array( 'Rouen' ),
		'Paris, Lyon'             => array( 'Paris', 'Lyon' ),
		'Rouen, Caen, Normandie'  => array( 'Caen' ), // Rouen absente des 5 premiers résultats.
		'Paris, Normandie'        => array( 'Paris' ),
		'Paris, télétravail'      => array( 'Paris' ),
		'Rouen, Caen, France'     => array( 'Caen' ),
		'Rouen, Caen, Calvados'   => array( 'Caen' ),
	)
);
$cases = array(
	'London, Ontario, Canada'  => 'city:London',
	'Rouen, Normandie'         => 'city:Rouen',
	'Rouen, Seine-Maritime'    => 'city:Rouen',
	'Paris, Lyon'              => 'city:Paris | city:Lyon',
	'Rouen, Caen, Normandie'   => 'city:Rouen | city:Caen',
	'Paris, Normandie'         => 'city:Paris | region:Normandie',
	'Normandie, Bretagne'      => 'region:Normandie | region:Bretagne',
	'Paris ; Lyon ; Normandie' => 'city:Paris | city:Lyon | region:Normandie',
);
foreach ( $cases as $text => $expected ) {
	check( sprintf( '« %s » → %s', $text, $expected ), $expected === g2rd_labels( $locator->locate( $text ) ) );
}
check( 'une pause après chaque requête réelle à Photon, aucune sur le cache', $sleeps > 0 && count( $GLOBALS['g2rd_world_queries'] ) === $sleeps );

$r = $locator->locate( 'Rouen, Normandie ; Paris' );
check( 'premier lieu : adresse WPJM ; lieux en chaînes, sans le département', is_array( $r ) && '49.4432' === $r['address']['lat'] && 'Rouen' === $r['address']['city'] && array( 'type' => 'city', 'label' => 'Rouen', 'lat' => '49.4432', 'lng' => '1.0999' ) === $r['places'][0] && false === $r['deferred'] );
$before = count( $GLOBALS['g2rd_world_queries'] );
$r      = $locator->locate( 'Normandie' );
check( 'région seule : centre fixe, adresse régionale, sans requête', is_array( $r ) && array( 'type' => 'region', 'label' => 'Normandie', 'lat' => '49.1202', 'lng' => '0.1128', 'region' => '28' ) === $r['places'][0] && 'Normandie' === $r['address']['state_long'] && 'FR' === $r['address']['country_short'] && '' === $r['address']['city'] && 'Normandie, France' === $r['address']['formatted_address'] && count( $GLOBALS['g2rd_world_queries'] ) === $before );
check( 'homonyme avec indice : « Bretagne (90) » cherché comme une ville', 'city:Bretagne' === g2rd_labels( $locator->locate( 'Bretagne (90)' ) ) && in_array( 'Bretagne', $GLOBALS['g2rd_world_queries'], true ) );
check( 'doublons après placement : « Alsace ; Lorraine » donne une seule zone Grand Est', 'region:Grand Est' === g2rd_labels( $locator->locate( 'Alsace ; Lorraine' ) ) );
check( 'doublons après placement : même ville sous deux écritures', 'city:Rouen' === g2rd_labels( $locator->locate( 'Rouen ; Rouen, Normandie' ) ) );
$twelve = implode( ' ; ', array_slice( array_column( Regions::all(), 'name' ), 0, 12 ) );
check( 'plafond : 10 lieux au plus', 10 === count( $locator->locate( $twelve )['places'] ) );
$r = $locator->locate( 'Paris ; Atlantide' );
check( 'ville introuvable retirée et signalée, les autres restent', 'city:Paris' === g2rd_labels( $r ) && array( 'Atlantide' ) === $r['missing'] );
$r = $locator->locate( 'Paris, télétravail' );
check( 'mention vague après une virgule : ignorée', 'city:Paris' === g2rd_labels( $r ) && array() === $r['missing'] );
check( 'texte vide : aucun lieu', array() === $locator->locate( '' )['places'] && null === $locator->locate( '' )['address'] );
check( 'seconde passe : « France » n’est pas un lieu', 'city:Rouen | city:Caen' === g2rd_labels( $locator->locate( 'Rouen, Caen, France' ) ) );
$r = $locator->locate( 'Rouen, Caen, Calvados' );
check( 'seconde passe : le département d’une ville trouvée n’est pas un lieu', 'city:Rouen | city:Caen' === g2rd_labels( $r ) && array() === $r['missing'] );
check( 'précision avec indice : « Rouen, Seine-Maritime (76) » donne Rouen seul', 'city:Rouen' === g2rd_labels( $locator->locate( 'Rouen, Seine-Maritime (76)' ) ) );
$r = $locator->locate( 'Rouen, 76000' );
check( 'code postal après une virgule : indice, ni lieu ni introuvable', 'city:Rouen' === g2rd_labels( $r ) && array() === $r['missing'] );
check( 'indice sur le morceau : « Bretagne, 90300 » est la commune', 'city:Bretagne' === g2rd_labels( $locator->locate( 'Bretagne, 90300' ) ) );
$r = $locator->locate( 'Atlantide, Atlantide' );
check( 'introuvables sans doublon', array() === $r['places'] && array( 'Atlantide' ) === $r['missing'] );
$pieces = array();
for ( $i = 0; $i < 30; $i++ ) {
	$segments = array();
	for ( $j = 0; $j < 12; $j++ ) {
		$segments[] = 'Lieu' . chr( 97 + $i % 26 ) . chr( 97 + $j ) . $i;
	}
	$pieces[] = implode( ', ', $segments );
}
$before = count( $GLOBALS['g2rd_world_queries'] );
$r      = $locator->locate( implode( ' ; ', $pieces ) );
check( 'texte démesuré : au plus 20 appels à Photon par offre', is_array( $r ) && count( $GLOBALS['g2rd_world_queries'] ) - $before <= PlaceLocator::MAX_LOOKUPS && array() === $r['places'] );

list( $s ) = boot();
$locator   = new PlaceLocator( new PhotonGeocoder( $s ) );
g2rd_world( array() );
$r = $locator->locate( 'Brest', 1 );
check( 'budget 1 : une ville absente du cache est placée', 'city:Brest' === g2rd_labels( $r ) && false === $r['deferred'] );
$r = $locator->locate( 'Caen ; Le Havre', 1 );
check( 'budget 1 : une deuxième ville absente du cache → à différer, au plus une requête', is_array( $r ) && true === $r['deferred'] && array() === $r['places'] && 2 === count( $GLOBALS['g2rd_world_queries'] ) );
$r = $locator->locate( 'Brest ; Normandie ; Caen', 1 );
check( 'budget 1 : villes en cache et régions ne comptent pas', 'city:Brest | region:Normandie | city:Caen' === g2rd_labels( $r ) && false === $r['deferred'] && 2 === count( $GLOBALS['g2rd_world_queries'] ) );
$r = $locator->locate( 'Le Havre', 0 );
check( 'budget 0 : texte absent du cache → à différer, sans requête', true === $r['deferred'] && 2 === count( $GLOBALS['g2rd_world_queries'] ) );
G2RD_Test_State::$http_responder = function () {
	return new WP_Error( 'http_request_failed', 'timeout' );
};
check( 'Photon en panne : WP_Error', is_wp_error( $locator->locate( 'Nantes ; Normandie' ) ) );
G2RD_Test_State::$http_responder = null;

$results = $GLOBALS['g2rd_results'];
echo PHP_EOL . sprintf( 'Résultat : %d réussis, %d échoués', $results['pass'], $results['fail'] ) . PHP_EOL;
exit( $results['fail'] ? 1 : 0 );
