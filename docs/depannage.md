# Dépannage

Chaque section part d'un symptôme. Pour la carte et l'autocomplétion, ouvrez
d'abord la console du navigateur (F12) : la plupart des causes y apparaissent.

## La carte n'apparaît pas du tout

Connecté en administrateur, la page affiche un message à la place de la
carte ; les visiteurs ne voient rien.

- « La carte des offres est désactivée » : activez-la dans la section Carte
  des réglages.
- « Aucune clé Google Maps Browser API Key » : en mode Google, renseignez la
  clé navigateur, ou passez en mode OpenStreetMap.
- Aucun message, aucune carte : vérifiez que WP Job Manager est actif. Sans
  lui, le shortcode ne rend rien. Si WP Job Manager réserve la consultation
  des offres à certains visiteurs, la carte suit la même règle.

## La carte affiche « La carte n'a pas pu être chargée »

- Ouvrez `/wp-json/g2rd-wpjm-geo/v1/jobs` dans le navigateur. Une erreur 403 ou
  404 vient de la route elle-même ou d'une extension de sécurité qui filtre
  l'API REST.
- Mode libre : MapLibre a besoin de WebGL. Si le site envoie une politique de
  sécurité du contenu (CSP), elle doit autoriser `worker-src blob:` et, dans
  `connect-src` et `img-src`, l'hôte du style (`tiles.openfreemap.org` par
  défaut) et celui de Photon (`photon.komoot.io` par défaut).
- Mode Google : un message de Google dans la console
  (`RefererNotAllowedMapError`, `ApiNotActivatedMapError`) désigne une
  restriction de la clé ou une API non activée.

## La carte est vide : « Aucune offre géolocalisée à afficher »

- **Mode libre, juste après le changement de fournisseur** : la carte libre
  n'affiche que les offres placées par Photon. Lancez Outils → Re-géocoder les
  offres. L'en-tête des réglages indique combien d'offres restent à traiter.
- **Mode Google** : le géocodage est celui de WP Job Manager, avec sa propre
  clé serveur (Offres d'emploi → Réglages). Sans elle, les offres n'ont pas de
  coordonnées.
- Les offres expirées, pourvues ou protégées par mot de passe ne sont jamais
  affichées.

## Une offre précise n'apparaît pas sur la carte libre

- Le géocodage refuse un homonyme lointain plutôt que de mal placer l'offre.
  « Télétravail », « À distance » ou « Remote » ne sont jamais placés.
- Corrigez le lieu de l'offre, de préférence en choisissant la ville dans
  l'autocomplétion, puis relancez Outils → Re-géocoder les offres. L'outil
  liste les offres introuvables.
- Un texte ambigu (« Lyon, Rhône », « Vienne, Poitiers ») est découpé selon la
  règle de la virgule : séparez les lieux par « ; » pour lever le doute. Les
  cas connus sont listés dans la FAQ du `readme.txt`, section « Limites connues
  des lieux multiples ».

## Les offres importées restent sans coordonnées (mode libre)

Pendant un import, une tâche WP-Cron ou WP-CLI, le géocodage passe par une
file traitée par WP-Cron (hook `g2rd_wpjm_geo_geocode`, 10 offres par passage).

- Si `DISABLE_WP_CRON` est défini dans `wp-config.php`, une vraie tâche cron
  doit appeler `wp-cron.php`, sinon la file n'avance pas.
- Si Photon ne répond pas, le plugin bascule toutes les nouvelles offres vers
  la file pendant 5 minutes, et la file attend 10 minutes avant de réessayer.
  Vérifiez que le serveur peut joindre le serveur Photon en HTTPS (pare-feu
  sortant de l'hébergeur).
- Une file bloquée se relance d'elle-même : un passage de secours est planifié
  à chaque prise de verrou (5 minutes).

## L'autocomplétion ne propose rien

- Vérifiez les trois interrupteurs de la section Autocomplétion (général,
  formulaire public, administration).
- Mode Google : la clé navigateur doit autoriser le référent du site et
  l'API « Places API (New) », et pas seulement l'ancienne « Places API ».
- Mode libre : la console du navigateur indique si l'appel à Photon est bloqué
  (CSP, bloqueur de publicité, service indisponible). La saisie libre reste
  toujours possible.
- Un champ « Lieu » ajouté par un thème ou une extension avec un autre
  sélecteur se déclare par les filtres
  `g2rd_wpjm_geo_frontend_location_selectors` et
  `g2rd_wpjm_geo_admin_location_selectors`.

## Un changement de réglage ne se voit pas sur le site

Le cache de pages de WP Rocket est vidé automatiquement quand un réglage
public change (fournisseur, style, Photon, carte, autocomplétion). Les autres
caches (serveur, CDN, autres extensions) doivent être vidés à la main.

Avec WP Rocket :

- « Retarder l'exécution du JavaScript » : la carte ne démarre qu'à la
  première interaction. Excluez `g2rd-wpjm-geo` si la carte est en haut de
  page.
- « Supprimer le CSS inutilisé » : ajoutez `g2rd-wpjm-geo-` et `maplibregl-`
  aux sélecteurs à conserver.

## Les candidatures Go Fetch Jobs ne sont pas remplacées

- Go Fetch Jobs doit être actif ; sinon la section l'indique et les réglages
  sont sans effet.
- Seules les offres importées par Go Fetch Jobs (métadonnée
  `_goft_wpjm_is_external`) sont concernées. Une offre déposée par un employeur
  ou créée dans wp-admin n'est jamais modifiée.
- En mode « URL invalides ou inaccessibles », la vérification est différée
  (WP-Cron, hook `g2rd_wpjm_geo_check_urls`) : le remplacement n'est pas
  immédiat, et une URL qui répond reste en place.
- Pour les offres importées avant l'installation ou l'activation du
  remplacement : Outils → Analyser, puis Simuler, puis Corriger.
- Le journal (section Journal) montre chaque remplacement avec l'ancienne
  valeur et la raison.

## Le menu du plugin n'apparaît pas

- La page est réservée aux comptes qui ont la capacité `manage_options`.
- Avec le thème G2RD (console v2), elle est dans G2RD → Offres géolocalisées ;
  sinon, dans le menu G2RD Geo, sous Offres d'emploi.
- Sans WP Job Manager actif, seule une notice s'affiche.

## Les tests JS échouent en local mais pas en CI

La CI installe jsdom avec `npm ci` depuis `tools/jsdom/package-lock.json`.
Réinstallez de la même façon, surtout après une mise à jour de jsdom par
Dependabot :

```sh
npm ci --prefix tools/jsdom --ignore-scripts
composer test:js
```

## Aucune release n'est publiée après un commit de version

- `auto-tag.yml` ne réagit qu'à un commit poussé sur `main` dont le sujet
  commence exactement par `version: X.Y.Z`. Un sujet comme
  `chore(version) : 1.2.1` ne déclenche rien.
- Il a besoin du secret `GH_PAT` : une étiquette poussée avec le jeton par
  défaut ne déclenche pas `release.yml`.
- Il échoue si l'étiquette existe déjà ou si les versions ne sont pas alignées
  (`bash tools/verify-release-version.sh X.Y.Z`).
- Solution de repli : `git tag vX.Y.Z && git push origin vX.Y.Z`.
