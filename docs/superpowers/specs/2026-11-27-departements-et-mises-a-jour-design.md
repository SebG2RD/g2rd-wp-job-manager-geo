# 1.4.0 : départements et mises à jour depuis GitHub

Conception validée le 2026-11-27.

## 1. Départements

Besoin : une offre peut viser une ou plusieurs villes, une ou plusieurs régions,
et désormais un ou plusieurs départements.

### Périmètre

- Les 95 départements de métropole (codes 01 à 95, 2A, 2B), sauf Paris (75) :
  la ville et le département couvrent le même territoire, Paris reste une ville.
- Les départements d'outre-mer (971 à 976) restent des régions, déjà gérées.
- Mode OpenStreetMap seulement, comme les régions.

### Reconnaissance (serveur, sans appel réseau)

Classe `Departments`, sur le modèle de `Regions` : code INSEE => nom officiel,
centre, boîte englobante et code de la région. Accents, casse, ponctuation,
article et préfixe « Département (de la, du, des, de l') » sont ignorés.

`PlaceLocator` :

- un nom de département sans indice (« Calvados ») donne le département ;
- avec un indice de département ou de code postal (« Vienne (38) »), c'est une ville, comme pour les régions ;
- règle de la virgule : « Calvados, Normandie » et « Calvados, France » donnent le département seul. Si
  la suite du morceau contient autre chose (« Vienne, Isère »), le morceau est
  d'abord cherché comme une ville. Seule une vraie commune est retenue, pas la limite administrative
  renvoyée par Photon. Sinon chaque segment est repris seul ;
- doublons : une région couverte par un département du même morceau est retirée,
  un département couvert par une ville du même morceau aussi (« Caen, Calvados »).

Lieu enregistré : `{ type: 'department', label, lat, lng, department }`, adresse
de WP Job Manager = « Calvados, France », région du département en `state_long`.

### Saisie (navigateur)

- L'autocomplétion propose les départements, mention « Département », icône carte.
- Une ville homonyme d'un département (Vienne, Mayenne…) reçoit son indice
  « (38) » à l'insertion, comme la commune « Bretagne ».
- Les codes des départements sont préfixés « D » côté navigateur : le
  département 28 (Eure-et-Loir) ne doit pas être confondu avec la région 28 (Normandie).

### Carte

Un département s'affiche comme une région : contour orange, étiquette, bulle
« Calvados (tout le département) », liste sous la carte, cadrage sur sa boîte.
Contours : data.gouv.fr, contours administratifs 2025 (1000 m, ODbL), fichier
`assets/data/departements-fr.geojson` chargé seulement si un département est affiché.

## 2. Mises à jour depuis GitHub

Besoin : une release publiée sur GitHub est proposée en mise à jour par
WordPress sur les sites qui ont le plugin.

- Classe `Updater`, sur le modèle de `G2RD\Promotions\Updater`
  (g2rd-woocommerce-promotions) : filtre `update_plugins_github.com` (déclenché
  par l'en-tête `Update URI` existant), limité au fichier de ce plugin car les
  autres plugins G2RD utilisent le même filtre.
- Source : `https://api.github.com/repos/SebG2RD/g2rd-wp-job-manager-geo/releases/latest`,
  paquet = l'asset `g2rd-wpjm-geo-X.Y.Z.zip`. URL du paquet acceptée seulement
  sous `https://github.com/SebG2RD/g2rd-wp-job-manager-geo/releases/download/`.
- Cache 6 h, 15 min après un échec. Le cache est vidé quand WordPress vide le sien,
  par exemple avec « Vérifier à nouveau ».
- « Voir les détails » : notes de la release, en texte échappé.
- WordPress compare lui-même les versions.

Limite : les sites en 1.3.0 ou avant n'ont pas ce module. Il faut installer
la 1.4.0 une fois à la main ; les versions suivantes seront proposées automatiquement.
