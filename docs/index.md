# Documentation G2RD WP Job Manager Geo

Point d'entrée pour reprendre le projet à n'importe quel moment, seul ou avec
de l'aide. Chaque document répond à une question précise.

| Question | Document |
| --- | --- |
| Je reprends le projet après une pause, par où commencer ? | [reprendre-le-projet.md](reprendre-le-projet.md) |
| Comment le plugin est-il construit, qui appelle quoi ? | [architecture.md](architecture.md) |
| Ça ne marche pas, où regarder ? | [depannage.md](depannage.md) |
| Comment livrer une version ? | [../README.md#publier-une-version](../README.md#publier-une-version) |
| Conception validée avant la première ligne de code | [superpowers/specs/](superpowers/specs/) |
| Découpage en tâches de chaque évolution | [superpowers/plans/](superpowers/plans/) |

Conceptions et plans, par évolution :

| Version | Évolution | Conception | Plan |
| --- | --- | --- | --- |
| 1.1.0 | Fournisseur libre (MapLibre, OpenFreeMap, Photon) | [spec](superpowers/specs/2026-10-16-fournisseur-libre-osm-design.md) | [plan](superpowers/plans/2026-10-16-fournisseur-libre-osm.md) |
| 1.2.0 | Plusieurs lieux par offre, régions françaises | [spec](superpowers/specs/2026-10-23-lieux-multiples-regions-design.md) | [plan](superpowers/plans/2026-10-24-lieux-multiples-regions.md) |
| 1.2.1 | Carte moins regroupée, liste sous la carte, sommaire de la console | [spec](superpowers/specs/2026-10-24-carte-liste-console-design.md) | [plan](superpowers/plans/2026-10-24-carte-liste-console.md) |
| 1.2.2 | Correctif : toutes les villes placées dès l'enregistrement (remplace le « budget d'appels » de la spec 1.2.0) | — | — |

Documents ailleurs dans le dépôt :

- [`README.md`](../README.md) : vue d'ensemble, installation, configuration, commandes, CI/CD.
- [`g2rd-wpjm-geo/readme.txt`](../g2rd-wpjm-geo/readme.txt) : fiche livrée avec le plugin (options, hooks, FAQ, limites connues, changelog).

## Demander de l'aide efficacement

Qu'il s'agisse d'un humain ou d'un assistant IA, fournir :

1. l'écran ou la page concernée, et le message d'erreur complet (console du
   navigateur pour la carte et l'autocomplétion, `debug.log` pour PHP) ;
2. le fournisseur actif (Google ou OpenStreetMap) ;
3. la réponse de `/wp-json/g2rd-wpjm-geo/v1/jobs` si la carte est en cause ;
4. la ligne du journal (section Journal des réglages) si une candidature
   Go Fetch Jobs est en cause ;
5. la version du plugin, de WordPress, de PHP, de WP Job Manager et de
   Go Fetch Jobs, et les extensions de cache ou d'optimisation actives ;
6. la sortie de `composer ci` et de `composer test:js` si le problème est
   dans le code.

Ne jamais coller la clé navigateur Google ni la clé serveur de WP Job Manager :
dire seulement si elles sont renseignées et restreintes.
