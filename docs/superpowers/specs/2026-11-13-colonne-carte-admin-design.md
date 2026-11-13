# Colonne « Carte » dans la liste des offres (1.3.0)

Indicateur, dans `wp-admin/edit.php?post_type=job_listing`, des offres dont les villes sont placées sur la carte. Conception validée le 2026-11-13.

## Constat

Rien dans la liste des offres ne dit si une offre a ses points sur la carte. Pour le savoir, il faut ouvrir la carte publique ou l'outil « Re-géocoder les offres ». Une ville mal saisie, une offre importée encore dans la file WP-Cron ou un champ Lieu vide passent inaperçus.

## Comportement

Nouvelle colonne **« Carte »**, présente sur toutes les pages de la liste. On peut la masquer avec « Options de l'écran », comme toute colonne de WordPress.

| État | Condition | Icône (dashicons) | Couleur | Infobulle et texte pour lecteur d'écran |
| --- | --- | --- | --- | --- |
| `placed` | Coordonnées valides venant du fournisseur actif | `dashicons-yes-alt` (✓) | vert `#00a32a` | « Placée sur la carte : Bezons, Gaillon, Aubevoye, Vernon » |
| `empty` | Champ Lieu vide | `dashicons-dismiss` (✗) | rouge `#d63638` | « Lieu non renseigné » |
| `pending` | Mode libre, offre dans la file de géocodage | `dashicons-clock` | rouge `#d63638` | « En attente de géocodage » |
| `missing` | Tous les autres cas | `dashicons-dismiss` (✗) | rouge `#d63638` | « Ville introuvable : corrigez le lieu ou utilisez « Re-géocoder les offres » dans les réglages du plugin » |

Les conditions sont évaluées dans l'ordre du tableau ; la première vraie l'emporte.

- **Pas seulement la couleur** (règle d'accessibilité : « ne pas transmettre une information par la seule couleur ») : la forme de l'icône change aussi, et un texte `screen-reader-text` reprend l'infobulle. Les deux couleurs sont celles de l'administration WordPress. Contraste d'un élément non textuel, seuil de 3:1 : vert 3,35:1 sur blanc et 3,13:1 sur les lignes grises `#f6f7f7`, rouge 4,7:1.
- **Infobulle** : classe `tips` et attribut `data-tip`. C'est le mécanisme de la colonne « Statut » de WP Job Manager : son script d'administration, chargé sur l'écran `edit-job_listing`, l'affiche comme du texte.
- **Même règle que la carte publique** pour l'état `placed`, reprise de `RestController::prepare_job()` et de `RestController::source_meta_query()` :
  - latitude et longitude numériques, dans les bornes, et différentes de (0, 0), selon `RestController::coordinates()` (voir « Composant ») ;
  - mode libre : source `_g2rd_wpjm_geo_source` égale à `osm` ;
  - mode Google : source absente ou égale à `google`.
- **Libellés des villes** : ceux que la carte affiche, donnés par `RestController::places()`. En mode libre, c'est la liste des lieux (une région porte son nom officiel). Sinon, c'est un lieu unique : la ville de WP Job Manager, à défaut le texte du lieu.
- **Publication non prise en compte** : un brouillon ou une offre expirée dont les villes sont placées est vert. La colonne « Statut » montre déjà qu'il n'est pas sur la carte publique.
- **Mode Google** : l'état `pending` n'existe pas, la file de géocodage ne servant qu'au mode libre.

## Composant

Nouvelle classe `includes/AdminListColumn.php` (`G2RD\WPJMGeo\AdminListColumn`), enregistrée dans `Plugin::boot()`, dans le bloc `is_admin()`, avec les réglages.

Ajustement de `RestController` pour que la colonne et la carte ne puissent pas diverger. La validation des coordonnées de `prepare_job()` (méthode privée) est extraite dans une méthode publique et statique, `RestController::coordinates( $id )`. Elle renvoie `array( lat, lng )` arrondis à 6 décimales, ou `null`. `prepare_job()` et la colonne l'utilisent toutes les deux. Le comportement de l'API ne change pas, et ses tests existants le garantissent.

- `register()` ajoute trois hooks :
  - filtre `manage_edit-job_listing_columns`, priorité 20, après WP Job Manager ;
  - action `manage_job_listing_posts_custom_column`, deux arguments ;
  - action `admin_enqueue_scripts`.
- `columns( $columns )` insère la clé `g2rd_wpjm_geo_map` (titre « Carte ») juste après `job_location`. Si cette clé n'existe pas, parce qu'une autre extension l'a retirée ou renommée, la colonne est ajoutée en fin de tableau.
- `render( $column, $post_id )` n'agit que pour `g2rd_wpjm_geo_map` et affiche :

  ```html
  <span class="g2rd-wpjm-geo-mapcol g2rd-wpjm-geo-mapcol--placed tips" data-tip="Placée sur la carte : Bezons, Gaillon">
    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
    <span class="screen-reader-text">Placée sur la carte : Bezons, Gaillon</span>
  </span>
  ```

  Le texte est échappé : `esc_attr()` pour `data-tip`, `esc_html()` pour le texte.
- `state( $job_id, $provider, array $queued )`, méthode statique et pure, renvoie `array( 'state' => …, 'labels' => list<string> )`. Elle ne lit que les métadonnées de l'offre. Celles-ci sont déjà en cache, car la requête de la liste les charge toutes en une fois : aucune requête SQL de plus par ligne.
- La file (`GeocodeQueue::OPTION`) est lue une seule fois par affichage de la liste, et seulement en mode libre.
- `enqueue( $hook_suffix )` charge `assets/css/admin-list.css` seulement sur `edit.php` pour le type `job_listing`. Version du fichier : `filemtime`, comme `AdminScreen`. Contenu :
  - largeur de colonne `4em`, centrée ;
  - icônes de 20 px ;
  - les deux couleurs.

## Hors périmètre

Filtre « placées / non placées » au-dessus du tableau, tri sur la colonne.

## Version

1.3.0 : nouvelle fonction. Changelog du `readme.txt`, `README.md`, tableau de `docs/index.md`, section administration de `docs/architecture.md`.

## Tests

- **PHP** (`tests/run.php`, nouvelle section) :
  - `state()` en mode libre :
    - offre placée avec la liste des lieux, région comprise ;
    - coordonnées écrites par Google → `missing` ;
    - Lieu vide → `empty` ;
    - offre en file → `pending` ;
    - coordonnées (0, 0) → pas `placed`.
  - `state()` en mode Google :
    - coordonnées sans source → `placed`, libellé de la ville ;
    - offre en file → `missing`, pas `pending`.
  - `columns()` : insertion après `job_location` ; ajout en fin si la clé est absente.
  - `render()` :
    - classes, icône et texte pour lecteur d'écran ;
    - libellé `"><script>` échappé dans `data-tip` et dans le texte ;
    - rien pour une autre colonne.
  - `enqueue()` : feuille chargée sur `edit.php` pour `job_listing`, pas sur un autre écran.
  - `RestController::coordinates()` :
    - coordonnées valides → arrondies à 6 décimales ;
    - (0, 0), hors bornes ou non numériques → `null` ;
    - tests existants de l'API de la carte inchangés.
- **Docker** (`tools/docker/verify.sh`), sur la liste des offres d'un administrateur connecté :
  - en-tête `column-g2rd_wpjm_geo_map` présent ;
  - icône verte sur l'offre à plusieurs lieux ;
  - icône rouge sur une offre au Lieu vide ;
  - feuille `admin-list.css` chargée.
