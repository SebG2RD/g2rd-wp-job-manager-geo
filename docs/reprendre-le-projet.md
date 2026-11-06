# Reprendre le projet

Parcours pour se remettre dans le projet après une pause, dans l'ordre.

## 1. Remettre l'environnement en état (10 minutes)

Outils : PHP 7.4 ou plus récent en ligne de commande, Composer, Node.js 22 ou
plus récent (la CI utilise Node 22), Git, et Docker Desktop pour la
vérification de bout en bout.

```sh
git switch main && git pull
composer install
npm ci --prefix tools/jsdom --ignore-scripts
composer ci          # tests PHP, WPCS, Security Audit, PHPStan
composer test:js     # tests JS dans jsdom
```

Tout doit passer avant de toucher au code. Si le comportement dans WordPress
est en jeu (hooks, activation, imports, appels Photon), lancez aussi la
vérification Docker :

```sh
cd tools/docker
./fetch-plugins.sh   # première fois seulement
./verify.sh
```

## 2. Comprendre où en est le projet (15 minutes)

1. **Version** : comparez le `Stable tag` de `g2rd-wpjm-geo/readme.txt` à la
   dernière [release GitHub](https://github.com/SebG2RD/g2rd-wp-job-manager-geo/releases).
   Un écart veut dire qu'une version attend d'être publiée.
2. **Changements récents** : le changelog du `readme.txt`, puis
   `git log --oneline -20`.
3. **Travail en cours** : les branches locales et distantes, `gh pr list`,
   et les alertes Dependabot (onglet Security du dépôt).
4. **Pourquoi c'est fait ainsi** : la conception de chaque évolution est dans
   [superpowers/specs/](superpowers/specs/), son découpage en tâches dans
   [superpowers/plans/](superpowers/plans/). L'[index](index.md) les classe par
   version.
5. **Comment c'est construit** : [architecture.md](architecture.md).

### Vérifié en local, pas encore sur le site de production

Le plugin est vérifié par les tests et dans le WordPress jetable de Docker
(PHP 8.2 et 8.4, WP Job Manager, Go Fetch Jobs gratuit, vrais appels Photon).
Ces points dépendent du site réel et restent à confirmer sur place :

- rendu WebGL de MapLibre sur mobile ;
- mise en page sous le thème du site ;
- WP Rocket : cache de pages, « Retarder l'exécution du JavaScript »,
  « Supprimer le CSS inutilisé » ;
- politique de sécurité du contenu (CSP), si le site en envoie une ;
- accès sortant du serveur vers `photon.komoot.io` ;
- WP-Cron réel après un import Go Fetch Jobs ;
- API Google avec de vraies clés ;
- Go Fetch Jobs premium (son planificateur n'a pas pu être lu) ;
- volume réel d'offres et part des offres introuvables (lancer Analyser et
  Simuler pour le mesurer).

Retirez un point de cette liste dès qu'il est constaté sur le site.

## 3. Règles à ne jamais casser

- **Ne jamais modifier WP Job Manager, Go Fetch Jobs ni le thème.** Passer par
  leurs hooks et leurs méthodes publiques.
- **Aucune donnée d'offre n'est insérée comme du HTML.** Carte, liste, outils :
  `textContent` ou nœuds DOM uniquement.
- **Aucun hook `save_post` global.** Pour les candidatures, seule
  `_application` est écrite, par `update_post_meta()`.
- **Mieux vaut une offre absente qu'une offre mal placée.** Le géocodage refuse
  les homonymes lointains, et l'outil de re-géocodage n'efface jamais de
  coordonnées.
- **Pas d'étape de compilation.** Pas de JSX ni de bundler ; MapLibre et
  MarkerClusterer sont embarqués avec leur licence.
- **Aucune dépendance Composer à l'exécution.** Composer et npm ne servent
  qu'à l'outillage.
- **PHP 7.4 minimum.** PHPCompatibility est bloquant en CI.
- **Tout avertissement PHP est un échec** dans le harnais de tests.
- **La version est écrite à trois endroits** : en-tête `Version`, constante
  `G2RD_WPJM_GEO_VERSION`, `Stable tag` du `readme.txt`.
- **Requêtes sortantes encadrées** : https seulement pour le style et Photon,
  `wp_safe_remote_*()` pour les URL de candidature.

## 4. Faire une modification

1. Créez une branche depuis `main` : `fix/…` pour une correction, `feat/…`
   pour une évolution.
2. Pour une évolution, écrivez d'abord la conception dans
   `docs/superpowers/specs/`, puis le plan dans `docs/superpowers/plans/`.
3. Écrivez le test qui échoue (`tests/run.php` ou `tests/js/run.js`), puis le
   code.
4. Lancez `composer ci`, `composer test:js` et, si WordPress est en jeu,
   `tools/docker/verify.sh`.
5. Cas particuliers :
   - styles de la console repris du thème G2RD :
     `php tools/build-console-css.php ../G2RD-theme` ;
   - contours des régions : `node tools/regions/extract-regions.js`, puis
     mettre à jour `tools/regions/SHA256SUMS` (la CI vérifie l'empreinte).
6. Ouvrez une PR vers `main` et attendez que la CI, PHPCS Security Audit et
   Plugin Check soient verts.

## 5. Publier une version

Voir [Publier une version](../README.md#publier-une-version) dans le README.
Pensez à ajouter l'entrée du changelog dans le `readme.txt` avant.

## 6. Se faire aider

Voir [Demander de l'aide efficacement](index.md#demander-de-laide-efficacement).
