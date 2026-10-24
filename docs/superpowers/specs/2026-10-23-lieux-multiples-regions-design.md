# Plusieurs lieux et régions par offre (1.2.0)

Validé avec le propriétaire le 2026-10-23, partie par partie : il a choisi dans le compagnon visuel la saisie à étiquettes (A) et l'affichage des régions en zone colorée (B).

## Constat

Le lieu d'une offre se saisit uniquement dans le champ texte « Lieu » de WP Job Manager (`_job_location`), en texte libre. C'est aussi ce champ que remplissent les imports Go Fetch Jobs. Une offre peut concerner plusieurs villes ou une région entière, et l'employeur l'écrit dans ce champ.

Aujourd'hui, le plugin place **un seul point** par offre :

| Saisie | Résultat actuel |
|---|---|
| `Paris, Lyon` | Paris seul : Lyon est lu comme un indice de contexte |
| `Paris / Lyon` | aucun point, l'offre disparaît de la carte |
| `Normandie` | un point au centre de la région, qui se lit comme une ville précise |
| `Rouen, Caen, Le Havre` | Rouen seul |

## Faits vérifiés (2026-10-23)

| Sujet | Constat | Source |
|---|---|---|
| Géocodage actuel | `geocode()` envoie à Photon **tout** le texte nettoyé (`q=Rouen, Caen, Normandie`, 5 résultats). `pick()` ne garde que les résultats dont le nom égale le premier segment. Il ajoute +2 si le pays, la région (`state`) ou le département (`county`) du résultat figure parmi les autres segments, +1 pour un département cité, +4 pour un code postal indiqué (`hint()` : « Laval (53) », « 75008 »). Une requête refusée avec filtres (HTTP 400) est relancée sans filtres : 2 appels au plus. | includes/PhotonGeocoder.php, lignes 136, 179 et 229 |
| Départements et régions étrangères | La requête accepte `boundary=administrative` : un département (« Seine-Maritime ») est déjà placé comme un point au centre donné par Photon. | includes/PhotonGeocoder.php, lignes 330 et 390 |
| Photon et les régions françaises | Avec les filtres de l'autocomplétion, « Norm » ne propose que des villages. Avec `layer=state`, « Normandie » arrive mêlée à des régions étrangères (« Nord » : Cameroun, Haïti, Soudan…). « Ile de France » sans accent ne trouve pas la région. Les régions viendront donc d'une liste intégrée. | essais en direct sur photon.komoot.io |
| Contours des régions | Jeu « Contours administratifs » (data.gouv.fr), fichier `2025/geojson/regions-1000m.geojson` : 147 960 octets, SHA-256 `7f44a5c4deaeb710c9dc2b043ed4debcf50068a1b0740619a5873f9e878f9786`. Il contient 26 entités (`code`, `nom`) : les 18 régions et 8 collectivités d'outre-mer (codes 975 à 989). L'extrait des 18 régions mesure **119 162 octets** (33 828 compressé). **Licence ODbL.** | téléchargé et mesuré le 2026-10-23 |
| Affichage du lieu | `get_the_job_location()` passe le texte par le filtre `the_job_location( $job_location, $post )`. La colonne « Lieu » de l'administration l'utilise aussi. | wp-job-manager-template.php, ligne 871 ; class-wp-job-manager-cpt.php, ligne 649 (2.4.7) |
| Formulaire d'édition | La valeur du champ est lue directement par `get_post_meta( $job->ID, '_job_location' )`, sans ce filtre. Le champ n'est pas obligatoire par défaut (`required => false`), mais un thème ou un plugin peut le rendre obligatoire. | class-wp-job-manager-form-submit-job.php, lignes 255 et 675 |
| Recherche par lieu | `search_location` est découpé sur « ; », et chaque morceau est cherché avec `LIKE` dans `_job_location`, `geolocation_formatted_address` et `geolocation_state_long`. Une recherche « Lyon » trouve donc l'offre `Paris ; Lyon`. | wp-job-manager-functions.php, lignes 105 à 123 |
| Autocomplétion actuelle | Un lieu choisi s'écrit `Nom, Région, Pays`. | assets/js/location-autocomplete.js, ligne 92 |
| Fichiers tiers | `tools/verify-plugin-zip.sh` exige les fichiers MapLibre et MarkerClusterer et leurs licences. Le dépôt n'a pas encore de liste d'empreintes. | tools/verify-plugin-zip.sh, lignes 66 à 69 |

## Objectif

En **mode OpenStreetMap**, une offre peut porter jusqu'à **10 lieux**, en mêlant villes et régions françaises.

- **Saisie** : autocomplétion à étiquettes dans le champ « Lieu » natif.
- **Reconnaissance** : les saisies libres, les anciennes offres et les imports Go Fetch sont lus selon la même règle.
- **Affichage** : un repère par ville et une zone colorée par région.

## Hors périmètre

- **Mode Google** : rien ne change. Le point unique est calculé par WP Job Manager, et la liste de lieux du plugin n'est pas utilisée.
- **Régions étrangères, départements, « France entière »** : pas de zone. Ces textes sont placés comme aujourd'hui, par un point au centre donné par Photon.
- **Mentions sans lieu précis** (« Télétravail », « À distance », « Hybride »…) : ignorées pour la carte, comme aujourd'hui.
- **Contours de villes ou de départements.**
- **Comptage exact des regroupements** : une offre présente dans deux villes d'un même regroupement compte deux fois dans le cercle numéroté.
- **Réécriture du texte saisi** : le plugin n'écrit jamais `_job_location` côté serveur.

## 1. Saisie à étiquettes

S'applique, en mode OpenStreetMap seulement, au formulaire de dépôt et à l'éditeur d'offre (`job_listing`).

### Champ natif

- Le champ « Lieu » natif reste le champ enregistré.
- Quand le JavaScript fonctionne, il reçoit l'attribut `hidden`. Sa valeur part toujours avec le formulaire.
- Le libellé natif (`<label for>`) est redirigé vers la saisie des étiquettes.
- Si le champ natif est obligatoire (`required`), cet attribut est retiré du champ masqué. La saisie des étiquettes signale alors l'erreur avec `setCustomValidity()` tant qu'aucune étiquette n'existe.

### Propositions

- Au fil de la frappe, au plus 6 propositions, qui se resserrent à chaque lettre.
  - **Régions** : liste intégrée (§ 2), sans tenir compte des accents ni de la casse, sigles et anciens noms compris.
  - **Villes** : Photon, avec les filtres actuels.
- Les régions qui correspondent passent en tête, puis viennent les villes, la France d'abord, comme aujourd'hui.
- Chaque proposition a une icône (📍 ville, 🗺️ région) et un libellé (« Rouen — Seine-Maritime, Normandie » ou « Normandie — Région »).
- Choix à la souris ou au clavier (flèches, Entrée, Échap). Le lieu devient une étiquette, puis la saisie se vide pour le lieu suivant.

### Étiquettes

- **Affichage** : une étiquette montre le premier segment de son texte (« Rouen »), et le texte complet en infobulle. Les étiquettes de région ont une couleur à part.
- **Retrait** : ✕ retire une étiquette ; Retour arrière dans une saisie vide retire la dernière.
- **Doublons** : refusés, en comparant sans accents ni casse.
- **Plafond** : 10 lieux, avec un message visible et annoncé.
- **Texte libre** : Entrée sans proposition sélectionnée, ou la sortie du champ, transforme le texte en cours en étiquette non vérifiée. L'employeur n'est jamais bloqué, et Entrée n'envoie pas le formulaire.
- **Collage** : un texte collé qui contient des séparateurs forts (§ 2) donne plusieurs étiquettes.

### Valeur enregistrée

- À chaque ajout ou retrait, le champ natif reçoit les textes des étiquettes joints par ` ; ` : `Paris ; Lyon ; Normandie`.
- Une ville choisie dans la liste garde son libellé complet (`Rouen, Normandie, France`), ce qui distingue les homonymes. Une région choisie s'écrit sous son nom officiel.
- À l'ouverture d'une offre existante, le texte est redécoupé en étiquettes sur les seuls séparateurs forts. Une étiquette dont le texte est une région de la liste reprend la couleur des régions.
- Un texte ancien sans séparateur fort (`Paris, Lyon`) donne donc une seule étiquette, que l'employeur peut corriger. Le serveur le découpe quand même (§ 3).

### Accessibilité

- La liste des propositions garde le modèle combobox actuel.
- Chaque ✕ porte l'intitulé « Retirer Paris ».
- Une zone d'annonce signale les ajouts, les retraits et le plafond.

**Sans JavaScript**, le champ texte natif reste utilisable, avec l'aide « Séparez plusieurs lieux par ; ». Cette aide (`LocationHelp`) s'ajoute à la description du champ, en public et dans l'administration, en mode libre, même sans autocomplétion.

## 2. Découpage et régions (sans réseau)

**`PlaceParser::split( $text )`** transforme un texte en une liste ordonnée de morceaux.

1. Le texte est découpé sur `;`, `|`, `/`, le retour à la ligne et ` et ` (avec une espace de chaque côté, casse ignorée). Les tirets ne séparent rien (`Ille-et-Vilaine`).
2. Chaque morceau est nettoyé de ses espaces et de la ponctuation qui l'entoure.
3. Les morceaux vides ou vagues (liste `VAGUE` actuelle) sont écartés.
4. Les doublons sont fusionnés, en comparant avec `PhotonGeocoder::normalize()`.

La virgule ne sépare rien à cette étape : elle est traitée au § 3.

**`Regions`** contient les 18 régions, avec pour chacune :

- le code INSEE ;
- le nom officiel ;
- un centre fixe : centre de gravité du plus grand polygone, vérifié à l'intérieur du contour et précalculé depuis le fichier des contours (§ 6) ;
- des variantes, dont les noms anglais et allemands que Photon renvoie selon la langue du site (« Normandy », « Brittany », « Corsica », « Korsika », « Occitania »…), pour que la règle de la virgule reconnaisse la région d'un résultat quelle que soit la langue du site.

| Codes | Régions |
|---|---|
| 01, 02, 03, 04, 06 | Guadeloupe, Martinique, Guyane, La Réunion, Mayotte |
| 11, 24, 27, 28, 32, 44, 52 | Île-de-France, Centre-Val de Loire, Bourgogne-Franche-Comté, Normandie, Hauts-de-France, Grand Est, Pays de la Loire |
| 53, 75, 76, 84, 93, 94 | Bretagne, Nouvelle-Aquitaine, Occitanie, Auvergne-Rhône-Alpes, Provence-Alpes-Côte d'Azur, Corse |

Variantes et sigles :

| Région | Variantes |
|---|---|
| Île-de-France | « IDF », « Région parisienne » |
| Provence-Alpes-Côte d'Azur | « PACA », « Région Sud » |
| Auvergne-Rhône-Alpes | « AURA » |
| Hauts-de-France | « HDF » |
| Bourgogne-Franche-Comté | « BFC » |
| Centre-Val de Loire | « Région Centre » |
| La Réunion | « Réunion » |
| Guyane | « Guyane française » |

Anciennes régions, rattachées à l'actuelle :

| Anciennes régions | Région actuelle |
|---|---|
| Alsace, Lorraine, Champagne-Ardenne | Grand Est |
| Aquitaine, Limousin, Poitou-Charentes | Nouvelle-Aquitaine |
| Auvergne, Rhône-Alpes | Auvergne-Rhône-Alpes |
| Bourgogne, Franche-Comté | Bourgogne-Franche-Comté |
| Midi-Pyrénées, Languedoc-Roussillon | Occitanie |
| Nord-Pas-de-Calais, Picardie | Hauts-de-France |
| Haute-Normandie, Basse-Normandie | Normandie |

### Recherche dans `Regions`

- `Regions::find( $text )` compare la forme `normalize()` (sans accents, minuscules, ponctuation remplacée par une espace) sans l'article de tête (`without_article()`). « Ile de France », « île-de-france » et « l'Île-de-France » donnent donc la même région.
- `Regions::all()` fournit la liste (codes, noms, variantes) transmise au navigateur dans les données du script. C'est une liste fixe, sans aucune donnée d'offre. L'autocomplétion la filtre dans le navigateur par préfixe, avec la même normalisation.

## 3. Règle de la virgule et placement

**`PlaceLocator::locate( $text, $budget )`** renvoie la liste des lieux placés. Il traite chaque morceau donné par `PlaceParser::split()`. Les segments d'un morceau sont séparés par les virgules ; le premier segment désigne le lieu.

### A. Le premier segment est une région

Cas où `Regions::find()` reconnaît le premier segment et où le morceau ne porte ni indice de département ni code postal (`hint()` vide).

- Le lieu est cette région, placée à son centre, sans appel réseau.
- Un segment suivant qui vaut « France » est un contexte.
- Tout autre segment suivant devient un morceau à part, traité à son tour.
- Avec un indice (`Bretagne (90)`, `Bretagne 90300`), le morceau est traité comme une ville (cas B), pour retrouver la commune homonyme.

### B. Sinon, le premier segment est une ville

1. *Première passe* : `geocode()` sur le morceau entier, comme aujourd'hui. Le comportement et le cache de `London, Ontario, Canada` ne changent pas.
2. *Si un résultat est trouvé*, chaque segment suivant est classé :
   - c'est un **contexte** s'il égale (forme `normalize()`, sans article) le pays, la région ou le département du résultat ;
   - c'est aussi un **contexte** s'il désigne, par `Regions::find()`, la même région que la région du résultat (« Rouen, Haute-Normandie ») ;
   - sinon, il devient un **morceau à part**, traité à son tour (cas A ou B, sans virgule).
3. *Si rien n'est trouvé* et que le morceau a plusieurs segments, *seconde passe* : chaque segment est traité comme un morceau à part. Un segment qui est une région est ensuite retiré s'il égale la région d'une ville trouvée dans le même morceau (`Rouen, Caen, Normandie` → Rouen et Caen, sans zone Normandie).
4. Une ville introuvable est retirée ; les autres lieux restent.

| Texte | Résultat |
|---|---|
| `London, Ontario, Canada` | 1 lieu (London, Ontario) |
| `Rouen, Normandie` | Rouen |
| `Rouen, Seine-Maritime` | Rouen |
| `Paris, Lyon` | Paris, Lyon |
| `Rouen, Caen, Normandie` | Rouen, Caen |
| `Paris, Normandie` | Paris, région Normandie |
| `Normandie, Bretagne` | 2 régions |
| `Paris ; Lyon ; Normandie` | Paris, Lyon, région Normandie |

### Ordre, doublons, plafond

- Les lieux gardent l'ordre du texte. Un morceau issu d'un segment s'insère juste après le lieu dont il vient.
- Doublons fusionnés après placement : même code de région (« Alsace ; Lorraine » donne une seule zone Grand Est), ou ville aux coordonnées identiques.
- Plafond : au-delà de 10 lieux, les suivants sont ignorés.

### Géocodeur

- `PhotonGeocoder::geocode_detailed( $text )` renvoie l'adresse au format WP Job Manager (inchangé), accompagnée du pays, de la région et du département du résultat.
- `geocode()` devient une enveloppe qui ne renvoie que l'adresse.
- Le cache garde aussi le département, et `CACHE_VERSION` passe à `4`. L'ancien cache est ignoré, sans migration.
- `PhotonGeocoder::is_cached( $text )` indique si un texte est en cache, sans appeler Photon.

### Budget d'appels

- `$budget` est le nombre de textes absents du cache que `locate()` peut envoyer à Photon.
- Saisie interactive : budget de 1, soit au plus 2 appels HTTP, comme aujourd'hui.
- File d'attente et outil de re-géocodage : budget illimité, avec la pause actuelle entre deux appels réels.
- Si le budget est épuisé, `locate()` s'arrête et répond « à différer ». L'offre part alors dans la file d'attente (`GeocodeQueue`) et apparaît sur la carte au passage suivant.
- Contextes déjà différés aujourd'hui (import Go Fetch ou WordPress, WP-Cron, WP-CLI, indicateur de panne Photon) : file d'attente directe, comme maintenant.

### Enregistrement

- **Méta du plugin** : `_g2rd_wpjm_geo_places`. Le préfixe `_` la protège, et elle n'est pas exposée par l'API REST de WordPress. C'est un tableau ordonné de 10 éléments au plus, chacun de la forme :

  ```json
  { "type": "city", "label": "Rouen", "lat": "49.4431", "lng": "1.0993" }
  ```

  - `type` : `city` ou `region`.
  - `region` : code INSEE, pour une région seulement (`"region": "28"`), avec le centre de `Regions` en `lat` et `lng`.
  - `label` : nom de la ville selon Photon, ou nom officiel de la région.
  - `lat`, `lng` : chaînes à 7 décimales au plus, bornées comme aujourd'hui.
- **Métas `geolocation_*` de WP Job Manager** : elles reçoivent le **premier** lieu par `WP_Job_Manager_Geocode::save_location_data()`. La recherche, le tri et les extensions tierces restent ainsi cohérents.
  - Pour une ville : l'adresse Photon.
  - Pour une région : `formatted_address` « Normandie, France », `state_long` « Normandie », `country_long` « France », `country_short` « FR », et une ville vide.
- **Source** : la méta `_g2rd_wpjm_geo_source = osm` reste posée.
- **Aucun lieu trouvé** : les métas sont effacées comme aujourd'hui pour un lieu introuvable, et la liste de lieux est supprimée.
- **Coordonnées étrangères** : si `geolocation_lat` est écrit par autre chose que le plugin (saisie manuelle, autre extension), la source **et** `_g2rd_wpjm_geo_places` sont effacées. L'offre garde alors ce point unique.

**Outil de re-géocodage** (sur action de l'administrateur seulement, rien n'est lancé à l'activation)

- La simulation liste, pour chaque offre, les lieux trouvés et les lieux introuvables.
- Une offre déjà placée en mode libre est reprise si sa liste de lieux manque (offre antérieure à la 1.2.0).

## 4. Données de la carte (REST)

Dans `GET /wp-json/g2rd-wpjm-geo/v1/jobs`, chaque offre gagne un champ `places`, au même format que la méta.

- Les champs actuels `lat` et `lng` restent (premier lieu), pour la compatibilité.
- **Offre sans liste de lieux** (mode Google, ou ancienne offre pas encore reprise) : `places` contient un seul élément `city`, construit depuis `lat` et `lng`. Son libellé est `geolocation_city`, ou à défaut le texte du lieu.
- Les règles d'accès (offre publiée, visible, sans mot de passe…) et le cache anonyme ne changent pas.
- Les libellés passent par la même fonction de texte que les autres champs. Les coordonnées de chaque lieu sont revérifiées (bornes), et un code de région absent de `Regions` fait écarter l'élément.

## 5. Affichage sur la carte (mode OpenStreetMap)

### Villes

- Chaque ville de chaque offre donne un point. Les points identiques sont réunis comme aujourd'hui (`groupByCoordinates`), et les regroupements ne changent pas.
- La fenêtre d'une offre à plusieurs lieux ajoute la ligne « **Aussi à :** Lyon, Normandie », sans répéter le lieu du point cliqué. Elle est construite en `textContent`.

### Régions

- `assets/data/regions-fr.geojson` n'est chargé, depuis le plugin et une seule fois, que si au moins une offre affichée a une région.
- Chaque région concernée prend la forme :
  - d'une **zone** : couche MapLibre `fill` orange translucide, plus une couche `line` orange ;
  - d'une **étiquette** « Normandie · 2 offres » (« 1 offre » au singulier), dessinée dans la carte, sous les points et les regroupements. Il n'y a plus de pastille bouton ; les options de collision de l'étiquette évitent les chevauchements.
- Un clic sur l'étiquette ou sur la zone ouvre la fenêtre de la région, qui liste ses offres comme pour les villes, sauf si un point se trouve dessous.
- **Une seule fenêtre** est ouverte à la fois.
- Les couches de régions sont dessinées **sous** les points et les regroupements : les villes restent cliquables dans une zone. Le survol renforce le contour.
- **Secours** : si le fichier ne se charge pas, chaque région s'affiche comme un point et son étiquette au centre de la région, sans légende.

### Autour de la carte

- Le cadrage automatique englobe les villes et les boîtes englobantes des régions (`bbox` de `Regions::DATA`, vérifiée par un test contre le GeoJSON). Un point seul donne le zoom 10 pour une ville, 6 pour une région sans boîte.
- La liste des offres sous la carte compte aussi les régions : « Normandie (toute la région) — 2 offres ». C'est l'accès au clavier aux offres de la carte et des régions.
- Une légende, contrôle MapLibre en haut à gauche, s'affiche quand au moins une zone est visible : deux entrées, chacune avec une pastille visuelle (rond : offre dans une ville ; carré : offre sur toute une région) et un texte, sans nom de couleur.
- Le contrôle d'attribution du plugin (1.1.1) ajoute « Contours des régions : data.gouv.fr (ODbL) » quand des zones sont affichées.

### Fiche publique et administration

- Le filtre `the_job_location` remplace ` ; ` par `, ` à l'affichage : « Paris, Lyon, Normandie ».
- Le formulaire d'édition lit la méta directement : il garde les « ; ».

**Mode Google** : rien ne change.

## 6. Sécurité, licence, performances

### Sécurité

- Les libellés sont rendus en `textContent` partout.
- Les régions viennent d'une liste fixe. Le serveur n'accepte aucune coordonnée du navigateur : il ne lit que le texte du champ.
- Les données des régions transmises au navigateur ne contiennent que la liste fixe.

### Provenance du fichier des contours

- `tools/regions/extract-regions.js` lit le fichier source téléchargé et vérifie son empreinte. Il garde les 18 régions sans toucher aux géométries, écrit `assets/data/regions-fr.geojson` et affiche les centres à reporter dans `Regions`.
- `tools/regions/SHA256SUMS` contient les empreintes du fichier source et du fichier embarqué. La CI vérifie celle du fichier embarqué (`sha256sum -c`).
- `tools/verify-plugin-zip.sh` exige `assets/data/regions-fr.geojson` et `assets/data/LICENSE-regions.txt`.

### Licence

- `assets/data/regions-fr.geojson` reste sous **ODbL**. C'est un extrait du jeu publié : 18 entités sur 26, géométries inchangées.
- `assets/data/LICENSE-regions.txt` cite la source (data.gouv.fr, « Contours administratifs », millésime 2025) et la licence. Le readme le mentionne.
- Le code du plugin reste sous GPL. La licence ODbL est déjà celle des données OpenStreetMap affichées.

### Performances

- 10 lieux par offre au plus : la réponse REST reste bornée (500 offres par page au plus).
- Budget de 1 appel en saisie interactive ; le reste passe par la file d'attente.
- Contours chargés une seule fois, et seulement s'ils servent : 119 162 octets, soit 33 828 compressé.

## 7. Tests

**PHP** (`tests/run.php`)

- `PlaceParser` : séparateurs forts, ` et `, tirets non séparateurs, mentions vagues, doublons.
- `Regions` : 18 codes, noms, variantes, sigles, anciens noms, recherche sans accents ni casse et sans article.
- `PlaceLocator`, avec un Photon simulé :
  - les 8 exemples du § 3 ;
  - homonyme avec indice (`Bretagne (90)`) ;
  - seconde passe ;
  - doublons de région (« Alsace ; Lorraine ») ;
  - plafond de 10 ;
  - ville introuvable retirée ;
  - budget épuisé, qui renvoie « à différer ».
- Géocodeur : `geocode_detailed()` (département renvoyé et mis en cache, version 4), `is_cached()`, `geocode()` inchangé.
- Enregistrement :
  - méta de lieux, premier lieu (ville ou région) dans `geolocation_*`, source posée ;
  - aucun lieu trouvé ;
  - `_job_location` jamais réécrit.
- Saisie interactive : une ville absente du cache est placée tout de suite ; deux villes absentes partent en file.
- Coordonnées étrangères : la source et les lieux sont effacés.
- REST : champ `places`, repli sur `lat`/`lng`, bornes, code de région inconnu écarté.
- Outil de re-géocodage : simulation détaillée, reprise des offres 1.1.x.
- Filtre `the_job_location`.

**JavaScript** (`tests/js/run.js`, jsdom)

- Étiquettes :
  - ajout au clic et au clavier ;
  - retrait par ✕ et par Retour arrière ;
  - doublon refusé et plafond annoncé ;
  - texte libre et collage avec séparateurs ;
  - réouverture d'une offre (couleur des régions) ;
  - synchronisation du champ natif, champ obligatoire.
- Propositions mêlant régions (« idf », « paca », « ile de ») et villes (Photon simulé).
- Carte :
  - repères multiples d'une offre et ligne « Aussi à » ;
  - zones et pastilles (source GeoJSON, couches `fill` et `line`) et fenêtre de région ;
  - légende et liste sous la carte ;
  - contours chargés seulement si besoin, secours si le fichier échoue ;
  - attribution des contours.
- Accessibilité : intitulés des ✕, annonces.

**Docker** (`tools/docker/verify.sh`) : offre déposée avec `Paris ; Lyon ; Normandie`, import Go Fetch avec plusieurs lieux, réponse REST avec `places`, aucune erreur PHP.

**Navigateur**, sur le site de test du propriétaire : saisie à étiquettes, zones et fenêtres, affichage sur mobile.

## Découpage

| Fichier | Rôle |
|---|---|
| `includes/Regions.php` (nouveau) | 18 régions : codes, noms, variantes, anciens noms, centres ; `find()`, `all()`. |
| `includes/PlaceParser.php` (nouveau) | Découpage sur les séparateurs forts (§ 2). |
| `includes/PlaceLocator.php` (nouveau) | Règle de la virgule, placement, doublons, plafond, budget (§ 3). |
| `includes/PhotonGeocoder.php` | `geocode_detailed()`, `is_cached()`, cache version 4. |
| `includes/GeocodingIntegration.php` | Écriture de la liste et du premier lieu, file d'attente si le budget est épuisé, effacement sur coordonnées étrangères, filtre `the_job_location`. |
| `includes/GeocodeQueue.php`, `includes/RegeocodeTool.php` | Placement de tous les lieux ; simulation détaillée. |
| `includes/RestController.php` | Champ `places`. |
| `includes/OsmAssets.php`, `includes/LocationAutocomplete.php` | URL du fichier des contours, liste des régions pour l'autocomplétion, textes traduits. |
| `assets/js/location-autocomplete.js` | Étiquettes, propositions de régions locales. |
| `assets/js/map-common.js`, `assets/js/jobs-map-osm.js` | Lieux multiples, « Aussi à », zones, pastilles, légende, liste. |
| `assets/css/frontend.css` | Étiquettes, légende, pastilles. |
| `assets/data/regions-fr.geojson`, `assets/data/LICENSE-regions.txt` (nouveaux) | Contours (ODbL) et mention de licence. |
| `tools/regions/extract-regions.js`, `tools/regions/SHA256SUMS` (nouveaux) | Extraction reproductible et empreintes. |
| `tools/verify-plugin-zip.sh`, `.github/workflows/ci.yml` | Fichiers de contours exigés, empreinte vérifiée. |
| `readme.txt` | Version 1.2.0, journal des modifications, mention des données ODbL. |

## Risques

- **Charge sur Photon** : jusqu'à 10 textes par offre, voire plus en seconde passe. Le cache par texte exact, le budget d'un appel en saisie interactive et la file d'attente la limitent. La saisie à étiquettes produit des « ; », sans recours à la règle de la virgule.
- **Règle de la virgule** : elle ne joue que pour le texte libre et les imports.
  - `Paris, Normandie` : si Photon connaît un lieu nommé « Paris » en Normandie, ce lieu l'emporte. C'est déjà le comportement actuel des indices.
  - Une région écrite autrement que dans Photon et absente des variantes devient un lieu à part, donc une zone en trop.
- **Première passe** : Photon reçoit tout le texte et ne renvoie que 5 résultats. La ville cherchée peut en être absente (`Rouen, Caen, Le Havre`) ; c'est le rôle de la seconde passe.
- **Homonymes de régions** (Bretagne, Lorraine…) : la commune n'est retrouvée qu'avec un indice de département ou de code postal.
- **Anciennes régions** : « Alsace » s'affiche comme toute la zone Grand Est.
- **ODbL** : l'attribution sur la carte et `LICENSE-regions.txt` remplissent l'obligation de citer la source et de garder ce fichier sous ODbL.
- **Retour au mode Google** : la liste de lieux est ignorée, et WP Job Manager géocode `Paris ; Lyon` comme une seule adresse. C'est une limite documentée.

## Limites connues

- Texte libre et imports, règle de la virgule : « Lyon, Rhône » donne deux lieux, Lyon et « Rhône » (Photon rattache Lyon à la « Métropole de Lyon », pas au département du Rhône) ; « Vienne, Poitiers » ne donne que Poitiers (la Vienne est le département de Poitiers) ; « Normandie, Rouen, Caen » donne la zone Normandie plus Rouen et Caen, alors que « Rouen, Caen, Normandie » donne Rouen et Caen seulement. Pour lever le doute, séparer les lieux par « ; ».
- Les noms de régions et leurs variantes sont reconnus en français, ainsi que les noms anglais et allemands que Photon renvoie selon la langue du site (Normandy, Brittany, Korsika…) ; les mentions vagues (« Télétravail », « Remote », « Hybride »…) sont reconnues en français et en anglais.
- Une ancienne région (Alsace, Lorraine…) s'affiche comme la zone actuelle (Grand Est) ; deux anciennes régions de la même zone n'en font qu'une.
- L'option de WP Job Manager « Display Location Address » affiche l'adresse du premier lieu seulement ; le lien Google Maps de la fiche et les données structurées de WP Job Manager portent aussi sur le premier lieu.
- L'affichage public remplace « ; » par « , » (« Paris, Lyon, Normandie »), y compris en mode Google.
- Sur la carte, le nombre d'un regroupement compte les lieux et non les offres distinctes ; à petite échelle, certaines étiquettes de région sont masquées pour éviter les chevauchements (la zone reste cliquable et la région figure dans la liste sous la carte).
- Retour au mode Google : la liste des lieux est ignorée et WP Job Manager géocode « Paris ; Lyon » comme une seule adresse.
- Homonymes dans « Aussi à » : une offre à deux Saint-Denis n'affiche pas l'autre Saint-Denis sur le point d'un Saint-Denis.
- « et » dans un libellé Photon choisi n'est pas neutralisé (contrairement à « / », « | » et « ; »).
- « Lorraine » après « Grand Est » est refusé comme doublon (même région).
- À 360 px, une étiquette de région peut dépasser légèrement le bord de la carte.
- Le serveur peut ne pas compresser un fichier `.geojson` (119 ko au lieu de 34 ko) : vérifier les en-têtes sur le site.
- Licence : les contours sont sous ODbL. Le plugin est distribué par GitHub ; une publication sur WordPress.org demanderait de vérifier que ces données sont admises par les règles du répertoire.
