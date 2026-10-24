# Carte, liste des offres et console (1.2.1) — plan d'exécution

> **Pour les agents :** sous-compétence requise : superpowers:subagent-driven-development (recommandée) ou superpowers:executing-plans, tâche par tâche. Les étapes utilisent des cases à cocher (`- [ ]`).

**Objectif :** points moins regroupés quand on dézoome, liste des offres sous la carte en pleine largeur et classée par lieu, sommaire de la console lisible.

**Architecture :** deux constantes de regroupement par moteur de carte (`jobs-map-osm.js`, `jobs-map.js`) ; `placesList()` de `map-common.js` construit son propre balisage, distinct des fenêtres de la carte ; une petite feuille `admin-geo.css` chargée sur les écrans du plugin avec ou sans le thème G2RD.

**Pile :** JavaScript ES5 sans build, PHP (WordPress), CSS ; bancs `tests/run.php` (PHP) et `tests/js/run.js` (jsdom) ; `tools/docker/verify.sh`.

**Spécification :** `docs/superpowers/specs/2026-10-24-carte-liste-console-design.md`.

---

## Règles communes à toutes les tâches

- Dépôt : `C:\Users\gerar\Downloads\DeveloppementWeb\G2RD-WP-Job-Manager-Geo`, Git Bash, commandes depuis la racine. Branche `fix/carte-liste-console` (ne pas en changer, ne rien pousser).
- Bancs : `php g2rd-wpjm-geo/tests/run.php | tail -1` (base : `Résultat : 375 réussis, 0 échoués`) et `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1` (base : `Resultat JS : 239 reussis, 0 echoues`). Nouvelles sections JS juste avant la ligne `console.log('\nResultat JS : ' + pass + ' reussis, ' + fail + ' echoues');` ; nouvelles sections PHP juste avant `$results = $GLOBALS['g2rd_results'];`.
- TDD : test d'abord, observer l'échec, puis le code.
- Le **code** JS reste en ASCII (les commentaires peuvent avoir des accents). Les arguments des outils passent par du JSON : `\u00b7` tapé arrive décodé en « · », `\\u00b7` arrive avec deux barres. Méthode fiable : écrire un repère (`__U00B7__`, `__U2014__`), puis le remplacer par un script Node en ligne de commande qui insère `String.fromCharCode( 92 ) + 'u00b7'` ; vérifier avec `grep -nP '[^\x00-\x7F]' <fichier>` (seuls des commentaires).
- Fins de ligne LF ; éditions ciblées ; aucune commande en arrière-plan ; ne rien copier depuis le scratchpad.
- Avant chaque commit : les deux bancs, `composer run lint`, `composer run lint:security` et `composer run analyse` passent.
- Interdit : modifier WP Job Manager, Go Fetch Jobs, Astra, WordPress, `functions.php` ; ouvrir `tools/docker/cookies.txt`.

---

### Tâche 1 : regroupement des points

**Fichiers :**
- Modifier : `g2rd-wpjm-geo/assets/js/jobs-map-osm.js` (constantes en tête, options de la source vers la ligne 526)
- Modifier : `g2rd-wpjm-geo/assets/js/jobs-map.js` (constantes en tête, `MarkerClusterer` vers la ligne 112)
- Test : `g2rd-wpjm-geo/tests/js/run.js` (aide `map()`, nouvelle section P)

- [ ] **Étape 1 : faux `markerClusterer` du banc**

Dans l'aide `map()` de `run.js`, remplacer :

```js
async function map({ mapId, clustering, data, restFail, nonce }) {
```

par :

```js
async function map({ mapId, clustering, data, restFail, nonce, noAlgorithm = false }) {
```

et remplacer :

```js
  w.markerClusterer = { MarkerClusterer: function (o) { st.cluster = o; } };
```

par :

```js
  w.markerClusterer = { MarkerClusterer: function (o) { st.cluster = o; } };
  if (!noAlgorithm) w.markerClusterer.SuperClusterAlgorithm = function (o) { this.options = o; };
```

- [ ] **Étape 2 : tests (ils échouent)**

Section à insérer avant la ligne `console.log('\nResultat JS : …');` :

```js
  console.log('\nP. Regroupement des points');
  o = await osmMap({ data: jobs });
  check('carte libre : regroupement sur 25 px, jusqu au zoom 10', 25 === o.st.sources['g2rd-jobs'].clusterRadius && 10 === o.st.sources['g2rd-jobs'].clusterMaxZoom);
  let gm = await map({ clustering: true, data: jobs });
  check('carte Google : algorithme SuperCluster sur 25 px, jusqu au zoom 11', !!gm.st.cluster && !!gm.st.cluster.algorithm && 25 === gm.st.cluster.algorithm.options.radius && 11 === gm.st.cluster.algorithm.options.maxZoom);
  gm = await map({ clustering: true, data: jobs, noAlgorithm: true });
  check('carte Google sans SuperClusterAlgorithm : regroupement par defaut', !!gm.st.cluster && undefined === gm.st.cluster.algorithm);
```

(Si la variable `o` n'est pas déclarée à cet endroit du banc, utiliser `let o2 = …` et adapter les deux lignes.)

Run : `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | grep -E "^P|FAIL|Resultat"`
Attendu : `[FAIL]` sur les deux premiers contrôles de P.

- [ ] **Étape 3 : carte libre**

Dans `jobs-map-osm.js`, après `var SOURCE = 'g2rd-jobs';`, ajouter :

```js
	// Regroupement des points : assez serré pour que Paris, Évreux, le Nord ou Lyon restent distincts en vue France.
	var CLUSTER_RADIUS = 25;
	var CLUSTER_MAX_ZOOM = 10;
```

puis, dans les options de `map.addSource( SOURCE, { … } )`, remplacer :

```js
					clusterMaxZoom: 14,
					clusterRadius: 50,
```

par :

```js
					clusterMaxZoom: CLUSTER_MAX_ZOOM,
					clusterRadius: CLUSTER_RADIUS,
```

- [ ] **Étape 4 : carte Google**

Dans `jobs-map.js`, près des autres constantes en tête de la fonction englobante (lire le fichier pour trouver l'endroit), ajouter :

```js
	// Mêmes réglages que la carte libre ; le zoom Google (tuiles de 256 px) vaut le zoom MapLibre + 1.
	var CLUSTER_RADIUS = 25;
	var CLUSTER_MAX_ZOOM = 11;
```

puis remplacer :

```js
			if ( clustering && markers.length > 1 ) {
				new window.markerClusterer.MarkerClusterer( {
					map: map,
					markers: markers,
					renderer: {
```

par :

```js
			if ( clustering && markers.length > 1 ) {
				var clusterOptions = {
					map: map,
					markers: markers,
					renderer: {
```

et, à la fin de cet objet, remplacer la fermeture `} );` de `new window.markerClusterer.MarkerClusterer( { … } );` par :

```js
				};
				if ( 'function' === typeof window.markerClusterer.SuperClusterAlgorithm ) {
					clusterOptions.algorithm = new window.markerClusterer.SuperClusterAlgorithm( { radius: CLUSTER_RADIUS, maxZoom: CLUSTER_MAX_ZOOM } );
				}
				new window.markerClusterer.MarkerClusterer( clusterOptions );
```

Vérifier que le moteur de rendu (`renderer`) reste identique et que l'indentation suit le fichier.

- [ ] **Étape 5 : tests, lint, commit**

Run : les deux bancs (JS : 242 réussis attendus), `composer run lint`, `composer run lint:security`, `composer run analyse`, `grep -nP '[^\x00-\x7F]' g2rd-wpjm-geo/assets/js/jobs-map-osm.js g2rd-wpjm-geo/assets/js/jobs-map.js`.

```bash
git add g2rd-wpjm-geo/assets/js/jobs-map-osm.js g2rd-wpjm-geo/assets/js/jobs-map.js g2rd-wpjm-geo/tests/js/run.js
git commit -m "fix(carte) : points moins regroupés quand on dézoome"
```

---

### Tâche 2 : liste des offres sous la carte

**Fichiers :**
- Modifier : `g2rd-wpjm-geo/assets/js/map-common.js` (`placesList()` et ses aides)
- Modifier : `g2rd-wpjm-geo/assets/css/frontend.css` (bloc « Liste des offres sous la carte »)
- Test : `g2rd-wpjm-geo/tests/js/run.js` (contrôle de la section I adapté, nouvelle section O)

- [ ] **Étape 1 : tests (ils échouent)**

Dans la section I, remplacer :

```js
  check('liste des offres sous la carte, pour le clavier', !!places && 'Liste' === places.querySelector('summary').textContent && places.children.length > 1);
```

par :

```js
  check('liste des offres sous la carte, pour le clavier', !!places && /^Liste \(\d+ offres?\)$/.test(places.querySelector('summary').textContent) && places.children.length > 1);
```

Section à insérer avant la ligne `console.log('\nResultat JS : …');` :

```js
  console.log('\nO. Liste des offres sous la carte');
  {
    const lw = new JSDOM('<div id="wrap"><div id="map"></div></div>', { runScripts: 'outside-only', url: 'https://x.test/' }).window;
    lw.eval(src('js/map-common.js'));
    const C = lw.g2rdWpjmGeoMapCommon;
    const li18n = { places: 'Liste des offres de la carte', alsoAt: 'Aussi à :', regionTitle: '%s (toute la région)', oneJob: '1 offre', manyJobsShort: '%d offres' };
    const sp = C.splitPlaces([
      { id: 1, title: 'Multi', permalink: 'https://x.test/job/1', company: 'M', job_type: 'CDI', city: 'Paris', country: 'France', latitude: 48.85, longitude: 2.35, places: [{ type: 'city', label: 'Paris', lat: 48.85, lng: 2.35 }, { type: 'city', label: 'Évreux', lat: 49.02, lng: 1.15 }, { type: 'region', label: 'Normandie', lat: 49.12, lng: 0.11, region: '28' }] },
      { id: 2, title: '<img src=x onerror=alert(1)>', permalink: 'javascript:alert(1)', company: '', job_type: '', city: 'Bezons', country: 'France', latitude: 48.92, longitude: 2.21 },
      { id: 3, title: 'Agent', permalink: 'https://x.test/job/3', company: 'CEA', job_type: 'CDI', city: 'Bruyères-le-Châtel', country: 'France', latitude: 48.59, longitude: 2.19 },
      { id: 4, title: 'Région seule', permalink: 'https://x.test/job/4', company: 'R', job_type: 'CDD', city: '', country: 'France', latitude: 47.75, longitude: 1.67, places: [{ type: 'region', label: 'Centre-Val de Loire', lat: 47.75, lng: 1.67, region: '24' }] }
    ]);
    const box = lw.document.getElementById('map');
    const details = C.places(box, C.groupByCoordinates(sp.points), li18n, sp.regions);
    const heads = [...details.querySelectorAll('.g2rd-wpjm-geo-places__heading')].map(h => h.textContent);
    const jobsLi = [...details.querySelectorAll('.g2rd-wpjm-geo-places__job')];
    check('liste juste apres la carte, dans le meme parent', box.nextElementSibling === details && 'wrap' === details.parentNode.id);
    check('resume : nombre d offres distinctes', 'Liste des offres de la carte (4 offres)' === details.querySelector('summary').textContent);
    check('sections : lieux par ordre alphabetique (accents compris), puis regions', JSON.stringify(['Bezons, France — 1 offre', 'Bruyères-le-Châtel, France — 1 offre', 'Évreux — 1 offre', 'Paris, France — 1 offre', 'Centre-Val de Loire (toute la région) — 1 offre', 'Normandie (toute la région) — 1 offre']) === JSON.stringify(heads));
    check('intertitres de niveau 3', 6 === details.querySelectorAll('h3.g2rd-wpjm-geo-places__heading').length);
    const agent = jobsLi.find(li => /Agent/.test(li.textContent));
    check('offre : titre en lien, « entreprise · contrat », aucun bouton', 'https://x.test/job/3' === agent.querySelector('a.g2rd-wpjm-geo-places__title').href && 'CEA · CDI' === agent.querySelector('.g2rd-wpjm-geo-places__meta').textContent && !details.querySelector('button, .g2rd-wpjm-geo-iw__link'));
    const multi = jobsLi.find(li => /Multi/.test(li.textContent) && 0 === li.closest('section').querySelector('h3').textContent.indexOf('Paris'));
    check('offre a plusieurs lieux : ligne « Aussi a »', !!multi && 'Aussi à : Évreux, Normandie' === multi.querySelector('.g2rd-wpjm-geo-places__also').textContent);
    const evil = jobsLi.find(li => /img/.test(li.textContent));
    check('titre malveillant en texte, lien dangereux ecarte, parties vides omises', 0 === details.querySelectorAll('img').length && !evil.querySelector('a') && !evil.querySelector('.g2rd-wpjm-geo-places__meta'));
    check('aucun balisage des fenetres de carte dans la liste', !details.querySelector('.g2rd-wpjm-geo-iw'));
    const css = src('css/frontend.css');
    const block = css.slice(css.indexOf('Liste des offres sous la carte'), css.indexOf('Champ « Lieu »'));
    check('styles : pleine largeur, aucun defilement interne, focus clavier', /width:\s*100%/.test(block) && !/max-height|overflow-y/.test(block) && /:focus-visible/.test(block));
  }
```

Run : `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | grep -E "^O|FAIL|Resultat"`
Attendu : le contrôle adapté de la section I et ceux de O en `[FAIL]` (ou `ERREUR BANC DE TEST`).

- [ ] **Étape 2 : `map-common.js`**

Après la fonction `addLine()`, ajouter :

```js
	function addText( parent, tagName, className, text ) {
		if ( ! text ) {
			return;
		}
		var node = document.createElement( tagName );
		node.className = className;
		node.textContent = text;
		parent.appendChild( node );
	}
```

Remplacer toute la fonction `placesList()` (et son docblock, à mettre à jour) par :

```js
	// Ligne d'une offre dans la liste sous la carte : titre, « entreprise · contrat », autres lieux.
	function placesJob( job, i18n ) {
		var item = document.createElement( 'li' );
		item.className = 'g2rd-wpjm-geo-places__job';
		var url = safeUrl( job.permalink );
		var title = document.createElement( url ? 'a' : 'span' );
		title.className = 'g2rd-wpjm-geo-places__title';
		title.textContent = job.title || '';
		if ( url ) {
			title.href = url;
		}
		item.appendChild( title );
		var meta = [ job.company, job.job_type ].filter( function ( part ) {
			return 'string' === typeof part && '' !== part.trim();
		} ).join( ' __U00B7__ ' );
		addText( item, 'span', 'g2rd-wpjm-geo-places__meta', meta );
		if ( Array.isArray( job._also ) && job._also.length ) {
			addText( item, 'span', 'g2rd-wpjm-geo-places__also', ( i18n.alsoAt ? i18n.alsoAt + ' ' : '' ) + job._also.join( ', ' ) );
		}
		return item;
	}

	function placesSection( heading, jobs, i18n ) {
		var section = document.createElement( 'section' );
		section.className = 'g2rd-wpjm-geo-places__group';
		addText( section, 'h3', 'g2rd-wpjm-geo-places__heading', heading );
		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-places__jobs';
		jobs.forEach( function ( job ) {
			list.appendChild( placesJob( job, i18n ) );
		} );
		section.appendChild( list );
		return section;
	}

	function byLabel( a, b ) {
		return a.label.localeCompare( b.label, 'fr', { sensitivity: 'base' } );
	}

	/**
	 * Liste repliable des offres de la carte, juste après elle et sur toute la largeur
	 * du parent : accès au clavier et aux lecteurs d'écran quand les points sont dessinés
	 * dans un canevas (MapLibre). Une section par lieu (ordre alphabétique), puis une par région.
	 *
	 * @param {Element} container Conteneur de la carte.
	 * @param {Array}   groups    Groupes de groupByCoordinates().
	 * @param {Object}  i18n      Textes.
	 * @param {Array}   regions   Régions de splitPlaces() (facultatif).
	 * @return {Element} Élément details inséré.
	 */
	function placesList( container, groups, i18n, regions ) {
		var previous = container.nextElementSibling;
		if ( previous && previous.classList.contains( 'g2rd-wpjm-geo-map__places' ) ) {
			previous.parentNode.removeChild( previous );
		}
		// Une offre à plusieurs lieux apparaît dans plusieurs sections mais ne compte qu'une fois.
		var seen = Object.create( null );
		var count = 0;
		function remember( job ) {
			var key = undefined === job.id || null === job.id ? null : String( job.id );
			if ( null === key || ! seen[ key ] ) {
				if ( null !== key ) {
					seen[ key ] = true;
				}
				count++;
			}
		}
		var cities = groups.map( function ( group ) {
			group.jobs.forEach( remember );
			var label = placeLabel( group.jobs[ 0 ] );
			return { label: label, heading: label + ' __U2014__ ' + jobCount( group.jobs.length, i18n ), jobs: group.jobs };
		} ).sort( byLabel );
		var areas = ( regions || [] ).map( function ( region ) {
			region.jobs.forEach( remember );
			return { label: region.label || '', heading: regionTitle( region, i18n ), jobs: region.jobs };
		} ).sort( byLabel );

		var details = document.createElement( 'details' );
		details.className = 'g2rd-wpjm-geo-map__places';
		var summary = document.createElement( 'summary' );
		summary.textContent = ( i18n.places || '' ) + ' (' + jobCount( count, i18n ) + ')';
		details.appendChild( summary );
		var body = document.createElement( 'div' );
		body.className = 'g2rd-wpjm-geo-places';
		cities.concat( areas ).forEach( function ( entry ) {
			body.appendChild( placesSection( entry.heading, entry.jobs, i18n ) );
		} );
		details.appendChild( body );
		container.parentNode.insertBefore( details, container.nextSibling );
		return details;
	}
```

Remplacer ensuite les repères `__U00B7__` et `__U2014__` par leurs échappements ASCII (`\u00b7`, `\u2014`) avec le script Node décrit dans les règles communes. `buildInfoContent()` et `buildRegionContent()` ne changent pas.

- [ ] **Étape 3 : `frontend.css`**

Remplacer tout le bloc :

```css
/* ---------- Liste des offres sous la carte (accès au clavier) ---------- */
.g2rd-wpjm-geo-map__places {
	margin-top: 12px;
}
.g2rd-wpjm-geo-map__places > summary {
	cursor: pointer;
	font-weight: 600;
}
```

par :

```css
/* ---------- Liste des offres sous la carte (accès au clavier) ---------- */
/* Sélecteurs préfixés par la liste : ils l'emportent sur les styles de contenu du thème (« .entry-content h3 », « .entry-content ul »). */
.g2rd-wpjm-geo-map__places {
	box-sizing: border-box;
	width: 100%;
	max-width: none;
	margin: 12px 0 0;
	color: #1d2327;
	font: 400 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif;
	border-top: 1px solid #dcdcde;
	border-bottom: 1px solid #dcdcde;
}

.g2rd-wpjm-geo-map__places > summary {
	display: flex;
	gap: 10px;
	align-items: center;
	padding: 10px 2px;
	font-weight: 600;
	font-size: 15px;
	list-style: none;
	cursor: pointer;
}

.g2rd-wpjm-geo-map__places > summary::-webkit-details-marker {
	display: none;
}

/* Chevron dessiné en CSS : pointe à droite, vers le bas une fois ouvert. */
.g2rd-wpjm-geo-map__places > summary::before {
	content: "";
	flex: none;
	width: 7px;
	height: 7px;
	border-right: 2px solid currentColor;
	border-bottom: 2px solid currentColor;
	transform: rotate(-45deg);
	transition: transform 0.15s ease;
}

.g2rd-wpjm-geo-map__places[open] > summary::before {
	transform: rotate(45deg);
}

.g2rd-wpjm-geo-map__places > summary:focus {
	outline: none;
}

.g2rd-wpjm-geo-map__places > summary:focus-visible {
	outline: 2px solid #a93226;
	outline-offset: 2px;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places {
	padding: 0 0 12px;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__group {
	margin: 0;
	padding: 0;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__heading {
	margin: 16px 0 4px;
	padding: 0;
	color: #1d2327;
	font: 700 15px/1.3 system-ui, -apple-system, "Segoe UI", sans-serif;
	letter-spacing: normal;
	text-transform: none;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__jobs {
	margin: 0;
	padding: 0;
	list-style: none;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__job {
	display: flex;
	flex-wrap: wrap;
	gap: 2px 16px;
	align-items: baseline;
	justify-content: space-between;
	margin: 0;
	padding: 8px 0;
	list-style: none;
	border-top: 1px solid #f0f0f1;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__title {
	color: #a93226;
	font-weight: 600;
	text-decoration: none;
}

.g2rd-wpjm-geo-map__places a.g2rd-wpjm-geo-places__title:hover,
.g2rd-wpjm-geo-map__places a.g2rd-wpjm-geo-places__title:focus {
	text-decoration: underline;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__meta {
	color: #50575e;
	font-size: 13px;
	text-align: right;
}

.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__also {
	flex-basis: 100%;
	color: #646970;
	font-size: 13px;
}

@media (max-width: 600px) {
	.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__job {
		flex-direction: column;
	}

	.g2rd-wpjm-geo-map__places .g2rd-wpjm-geo-places__meta {
		text-align: left;
	}
}

@media (prefers-reduced-motion: reduce) {
	.g2rd-wpjm-geo-map__places > summary::before {
		transition: none;
	}
}
```

- [ ] **Étape 4 : tests, lint, commit**

Run : les deux bancs (JS : 251 réussis attendus, soit 242 + 9), le lint, l'analyse, `grep -nP '[^\x00-\x7F]' g2rd-wpjm-geo/assets/js/map-common.js` (seuls des commentaires). Les contrôles existants des sections M et N qui lisent la liste (« Normandie (toute la région) — 2 offres ») doivent rester verts sans modification.

```bash
git add g2rd-wpjm-geo/assets/js/map-common.js g2rd-wpjm-geo/assets/css/frontend.css g2rd-wpjm-geo/tests/js/run.js
git commit -m "fix(carte) : liste des offres pleine largeur, classée par lieu"
```

---

### Tâche 3 : sommaire de la console d'administration

**Fichiers :**
- Créer : `g2rd-wpjm-geo/assets/css/admin-geo.css`
- Modifier : `g2rd-wpjm-geo/includes/SettingsPage.php` (`toc()`, vers la ligne 139)
- Modifier : `g2rd-wpjm-geo/includes/AdminScreen.php` (`enqueue()`, constantes)
- Modifier : `tools/docker/verify.sh` (sections 1 et 2)
- Test : `g2rd-wpjm-geo/tests/run.php` (contrôles des ressources de l'écran vers la ligne 875, rendu de la page section 22)

- [ ] **Étape 1 : tests PHP (ils échouent)**

Vers la ligne 875 de `run.php`, après le contrôle `menu autonome : copie de la feuille, polices et script`, ajouter :

```php
check( 'menu autonome : feuille du plugin pour le sommaire', isset( G2RD_Test_State::$enqueued['style:g2rd-wpjm-geo-admin'] ) && false !== strpos( G2RD_Test_State::$enqueued['style:g2rd-wpjm-geo-admin'], 'assets/css/admin-geo.css' ) );
```

et, après le contrôle `rattaché : feuille du thème chargée, pas la copie`, ajouter :

```php
check( 'rattaché : feuille du plugin pour le sommaire aussi chargée', isset( G2RD_Test_State::$enqueued['style:g2rd-wpjm-geo-admin'] ) );
```

Dans la section 22, après le contrôle `formulaire options.php du groupe enregistré`, ajouter :

```php
check( 'sommaire : nav dédiée, six liens lisibles, plus de puces discrètes', false !== strpos( $html, 'class="g2rd-row g2rd-row--wrap g2rd-geo-toc"' ) && 6 === substr_count( $html, 'class="g2rd-geo-toc__link"' ) && false === strpos( $html, 'g2rd-chip--muted' ) );
```

(Vérifier d'abord avec `grep -n "g2rd-chip--muted" g2rd-wpjm-geo/includes/*.php` qu'aucun autre élément de la page n'utilise cette classe ; sinon, restreindre le troisième critère au contenu de la balise `<nav>` du sommaire.)

Run : `php g2rd-wpjm-geo/tests/run.php | grep -E "FAIL|Résultat"`
Attendu : trois `[FAIL]`.

- [ ] **Étape 2 : `SettingsPage::toc()`**

Remplacer :

```php
		echo '<nav class="g2rd-row g2rd-row--wrap" aria-label="' . esc_attr__( 'Sommaire', 'g2rd-wpjm-geo' ) . '">';
		foreach ( $items as $id => $label ) {
			echo '<a class="g2rd-chip g2rd-chip--muted" href="#g2rd-geo-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
		}
```

par :

```php
		echo '<nav class="g2rd-row g2rd-row--wrap g2rd-geo-toc" aria-label="' . esc_attr__( 'Sommaire', 'g2rd-wpjm-geo' ) . '">';
		foreach ( $items as $id => $label ) {
			echo '<a class="g2rd-geo-toc__link" href="#g2rd-geo-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
		}
```

- [ ] **Étape 3 : `AdminScreen::enqueue()`**

Après `const SCRIPT_HANDLE = 'g2rd-wpjm-geo-admin-settings';`, ajouter (aligner les `=` comme le veut PHPCS) :

```php
	const ADMIN_STYLE_HANDLE = 'g2rd-wpjm-geo-admin';
```

Puis, juste avant `wp_enqueue_script( self::SCRIPT_HANDLE, … );`, ajouter :

```php
		// Styles propres au plugin (sommaire), chargés avec ou sans la console du thème G2RD.
		$admin_css = G2RD_WPJM_GEO_DIR . 'assets/css/admin-geo.css';
		wp_enqueue_style( self::ADMIN_STYLE_HANDLE, G2RD_WPJM_GEO_URL . 'assets/css/admin-geo.css', array(), file_exists( $admin_css ) ? (string) filemtime( $admin_css ) : G2RD_WPJM_GEO_VERSION );
```

Mettre à jour le docblock de la classe (elle charge aussi `admin-geo.css`).

- [ ] **Étape 4 : `assets/css/admin-geo.css`**

```css
/**
 * G2RD WP Job Manager Geo : styles propres au plugin dans la console G2RD.
 * Chargés sur les écrans du plugin avec ou sans le thème G2RD.
 */

/* Sommaire des réglages : pastilles blanches, lisibles sur le bandeau sombre comme
   sur le fond clair (sur écran étroit, le sommaire passe sous le bandeau). */
.g2rd-app .g2rd-geo-toc {
	gap: 8px;
}

.g2rd-app .g2rd-geo-toc__link {
	display: inline-flex;
	align-items: center;
	padding: 6px 14px;
	color: #0b1b2b;
	font-weight: 600;
	font-size: 13px;
	line-height: 1.4;
	white-space: nowrap;
	text-decoration: none;
	background: #fff;
	border: 1px solid rgba(11, 27, 43, 0.15);
	border-radius: 999px;
	box-shadow: 0 1px 3px rgba(11, 27, 43, 0.25);
	transition: background-color 0.15s ease, box-shadow 0.15s ease;
}

.g2rd-app .g2rd-geo-toc__link:hover,
.g2rd-app .g2rd-geo-toc__link:focus {
	color: #0b1b2b;
	background: #e6eef6;
	box-shadow: 0 2px 6px rgba(11, 27, 43, 0.3);
}

.g2rd-app .g2rd-geo-toc__link:focus {
	outline: none;
}

.g2rd-app .g2rd-geo-toc__link:focus-visible {
	outline: 2px solid #2dd4bf;
	outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
	.g2rd-app .g2rd-geo-toc__link {
		transition: none;
	}
}
```

Si la nav du sommaire n'a pas d'espacement vertical avec les cartes qui suivent (lire les règles de `.g2rd-row` dans `console.css`), ajouter une marge basse à `.g2rd-app .g2rd-geo-toc` et le signaler.

- [ ] **Étape 5 : contrôle Docker**

Dans `tools/docker/verify.sh`, section « 1. Administration sans le thème G2RD », après `ok 'feuille de repli du plugin' …`, ajouter :

```bash
ok 'feuille du sommaire du plugin' "$(has "$P" 'g2rd-wpjm-geo/assets/css/admin-geo.css')"
```

et, section « 2. Administration avec le thème G2RD », après `ok 'pas de feuille de repli' …`, ajouter :

```bash
  ok 'feuille du sommaire du plugin, aussi avec le thème' "$(has "$P" 'assets/css/admin-geo.css')"
```

Vérifier la syntaxe : `bash -n tools/docker/verify.sh`. Ne pas lancer le script (tâche 4).

- [ ] **Étape 6 : tests, lint, commit**

Run : les deux bancs (PHP : 378 réussis attendus), `composer run lint`, `composer run lint:security`, `composer run analyse`. Vérifier que `tools/verify-plugin-zip.sh` n'exige pas une liste fermée de fichiers CSS ; s'il le fait, y ajouter `admin-geo.css`.

```bash
git add g2rd-wpjm-geo/assets/css/admin-geo.css g2rd-wpjm-geo/includes/SettingsPage.php g2rd-wpjm-geo/includes/AdminScreen.php g2rd-wpjm-geo/tests/run.php tools/docker/verify.sh
git commit -m "fix(console) : sommaire des réglages lisible"
```

---

### Tâche 4 : version 1.2.1 et vérifications complètes

**Fichiers :**
- Modifier : `g2rd-wpjm-geo/g2rd-wpjm-geo.php` (en-tête `Version`, constante `G2RD_WPJM_GEO_VERSION`)
- Modifier : `g2rd-wpjm-geo/readme.txt` (`Stable tag`, journal des modifications)

- [ ] **Étape 1 : version**

Dans `g2rd-wpjm-geo/g2rd-wpjm-geo.php`, remplacer `* Version:           1.2.0` par `* Version:           1.2.1` et `define( 'G2RD_WPJM_GEO_VERSION', '1.2.0' );` par `define( 'G2RD_WPJM_GEO_VERSION', '1.2.1' );`. Dans `readme.txt`, remplacer `Stable tag: 1.2.0` par `Stable tag: 1.2.1`.

- [ ] **Étape 2 : journal des modifications**

Dans `readme.txt`, juste après `== Changelog ==` (avant `= 1.2.0 =`), ajouter :

```
= 1.2.1 =
* Carte : points moins regroupés quand on dézoome (rayon de 25 px ; plus de regroupement à l'échelle d'une agglomération), sur la carte libre comme sur la carte Google.
* Liste des offres sous la carte : pleine largeur, une section par lieu (ordre alphabétique, régions à la fin), une ligne par offre, nombre d'offres dans la barre d'ouverture.
* Console d'administration : sommaire des réglages plus lisible.
```

- [ ] **Étape 3 : vérifications complètes**

```bash
php g2rd-wpjm-geo/tests/run.php | tail -1
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
composer run lint && composer run lint:security && composer run analyse
bash tools/verify-release-version.sh 1.2.1
php tools/build-zip.php && bash tools/verify-plugin-zip.sh g2rd-wpjm-geo.zip; rm -f g2rd-wpjm-geo.zip
docker info >/dev/null 2>&1 && bash tools/docker/verify.sh
```

Attendu : PHP 378, JS 251 ; normes et analyse sans erreur ; versions alignées sur 1.2.1 ; `ZIP conforme.` ; Docker : tous les contrôles réussis (42 attendus). Si Docker n'est pas démarré, le signaler sans marquer l'étape comme faite.

- [ ] **Étape 4 : commit**

```bash
git add g2rd-wpjm-geo/g2rd-wpjm-geo.php g2rd-wpjm-geo/readme.txt
git commit -m "chore(version) : 1.2.1"
```

La fusion dans `main`, l'envoi et la release `v1.2.1` se font ensuite, avec l'accord de l'utilisateur.
