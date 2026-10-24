# Carte, liste des offres et console d'administration (1.2.1)

Trois corrections d'interface, constatées sur le site après la 1.2.0. Conception validée le 2026-10-24.

## Constat

1. **Regroupement trop large.** Vue de loin, une bulle « 47 » placée entre Paris et la Normandie réunit Paris (37), Évreux (6), le Nord (2) et le sud d'Orléans (2). L'utilisateur croit qu'il n'y a rien ailleurs. Cause : rayon de regroupement de 50 px, regroupement jusqu'au zoom 14 (MapLibre) ; à l'échelle de la France, 1 px ≈ 2 km. La carte Google (MarkerClusterer) a le même défaut.
2. **Liste des offres sous la carte illisible.** Elle réutilise le contenu et les styles des fenêtres de la carte (`buildInfoContent`, `.g2rd-wpjm-geo-iw` limité à 280 px) : liste étroite alors que la carte prend toute la largeur, défilement interne par groupe (`max-height: 220px`), bouton rouge pour une offre seule mais liens pour un groupe, blocs collés, intertitre « 5 offres à cet endroit » sans le lieu, cadre noir autour de la barre d'ouverture.
3. **Sommaire de la console peu visible.** `SettingsPage::toc()` utilise `g2rd-chip g2rd-chip--muted`, variante prévue pour les fonds clairs (11 px, fond `#EEF2F7`), posée sur le bandeau sombre.

## 1. Répartition des points

- **MapLibre** (`assets/js/jobs-map-osm.js`) : source `g2rd-jobs` avec `clusterRadius: 25` (au lieu de 50) et `clusterMaxZoom: 10` (au lieu de 14). Les deux valeurs deviennent des constantes nommées en tête du fichier.
- **Google** (`assets/js/jobs-map.js`) : `MarkerClusterer` reçoit l'algorithme `new markerClusterer.SuperClusterAlgorithm( { radius: 25, maxZoom: 11 } )` (le zoom Google, en tuiles de 256 px, vaut le zoom MapLibre + 1). Si la bibliothèque chargée n'expose pas `SuperClusterAlgorithm`, le comportement par défaut reste.
- Le nombre affiché dans une bulle ne change pas.

## 2. Liste des offres sous la carte

`placesList( container, groups, i18n, regions )` (`assets/js/map-common.js`) construit désormais son propre balisage ; les fenêtres de la carte (`buildInfoContent`, `buildRegionContent`) ne changent pas.

```html
<details class="g2rd-wpjm-geo-map__places">
  <summary>Liste des offres de la carte (64 offres)</summary>
  <div class="g2rd-wpjm-geo-places">
    <section class="g2rd-wpjm-geo-places__group">
      <h3 class="g2rd-wpjm-geo-places__heading">Bezons, France — 20 offres</h3>
      <ul class="g2rd-wpjm-geo-places__jobs">
        <li class="g2rd-wpjm-geo-places__job">
          <a class="g2rd-wpjm-geo-places__title" href="…">Assistante commerciale</a>
          <span class="g2rd-wpjm-geo-places__meta">SAV PRO GROUPE · CDI</span>
          <span class="g2rd-wpjm-geo-places__also">Aussi à : Lyon, Normandie</span>
        </li>
      </ul>
    </section>
  </div>
</details>
```

- **Résumé** : `i18n.places` suivi, entre parenthèses, du nombre d'offres **distinctes** (identifiants `id` uniques sur l'ensemble des lieux et des régions ; une offre à deux villes compte une fois), écrit avec `jobCount()`.
- **Sections** : une par lieu (groupe de coordonnées), puis une par région. Intertitre d'un lieu : `placeLabel()` du premier poste + « — » + `jobCount()`. Intertitre d'une région : `regionTitle()` (inchangé). Les lieux sont triés par ordre alphabétique de leur intertitre (`localeCompare` en français, sans tenir compte de la casse ni des accents) ; les régions viennent après, triées de la même façon.
- **Offre** : titre en lien (`safeUrl`), ou en texte s'il n'y a pas d'URL ; entreprise et type de contrat joints par « · », parties vides omises ; ligne « Aussi à » (`i18n.alsoAt`) si l'offre a d'autres lieux. Aucun bouton.
- Tout le texte passe par `textContent`. Le code JS reste en ASCII (« · » et « — » en `·` et `—`).
- **Styles** (`assets/css/frontend.css`) :
  - la liste prend 100 % de la largeur du parent (`box-sizing: border-box`), aucun `max-width`, aucun défilement interne ;
  - barre d'ouverture : filet clair en haut et en bas, chevron dessiné en CSS qui pivote à l'ouverture, marqueur natif masqué, pas de contour à la souris, contour `#a93226` au focus clavier (`:focus-visible`) ;
  - intertitre : taille et marges fixées par le plugin, indépendantes du thème ;
  - offre : une ligne flexible, titre à gauche (`#a93226`, gras), « entreprise · contrat » à droite (`#50575e`, 13 px) ; « Aussi à » sur sa propre ligne ; sous 600 px, l'entreprise et le contrat passent sous le titre ; un filet léger sépare les offres.

## 3. Sommaire de la console d'administration

- `SettingsPage::toc()` : la navigation reçoit la classe `g2rd-geo-toc`, chaque lien la classe `g2rd-geo-toc__link` (plus de `g2rd-chip--muted`).
- Nouvelle feuille `assets/css/admin-geo.css`, chargée par `AdminScreen::enqueue()` sur les écrans du plugin **dans les deux cas** (console du thème G2RD ou feuille de repli du plugin), avec des sélecteurs préfixés `.g2rd-app` :
  - pastilles **blanches** à texte bleu nuit `#0b1b2b`, 13 px, graisse 600, liseré `rgba(11,27,43,0.15)`, rayon 999 px, marges internes 6 px × 14 px, ombre légère, sans soulignement ;
  - au survol et au focus : fond `#e6eef6`, ombre un peu plus marquée ;
  - au focus clavier : contour turquoise `#2dd4bf` de 2 px, décalé de 2 px.
- Pourquoi des pastilles blanches plutôt que le « verre » du bandeau : le bandeau a une hauteur fixe (336 px, 380 px sur écran moyen) ; sur écran étroit, les tuiles s'empilent et le sommaire passe sous le bandeau, sur fond clair, où un texte blanc deviendrait invisible. Les pastilles blanches restent lisibles dans les deux cas (contraste d'environ 12:1).

## Version

1.2.1 : en-tête du plugin, constante, `Stable tag` et journal des modifications. La release n'est publiée qu'avec l'accord de l'utilisateur.

## Tests

- **JS (jsdom)** :
  - options de la source `g2rd-jobs` (rayon 25, zoom 10) ;
  - algorithme passé à `MarkerClusterer` (rayon 25, zoom 11), avec et sans `SuperClusterAlgorithm` ;
  - liste : largeur sans `max-width` (classe), résumé avec le nombre d'offres distinctes, ordre alphabétique (accents compris), régions après les villes, intertitres, ligne « entreprise · contrat », « Aussi à », absence de bouton et de défilement interne, titre malveillant rendu en texte.
- **PHP** : classes du sommaire ; `admin-geo.css` chargée avec et sans le thème G2RD.
- **Docker** (`tools/docker/verify.sh`) : la page des réglages charge `admin-geo.css`.
- Les tests existants qui lisent la liste (sections H, M, N) restent verts, adaptés seulement là où le balisage change.
