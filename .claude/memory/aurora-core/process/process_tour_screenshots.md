---
name: process_tour_screenshots
description: Les captures du tour public d'Aurora - quatre scripts dans tools/screenshots/, jamais un geste à la main, jamais la production.
metadata:
  type: project
---

## Règle

Les vingt-quatre pages `/fr/aurora/{slug}` d'app.axelraboit.fr sont la vitrine
du produit. Leurs images vivent dans la médiathèque de production, et tout ce
qui les touche passe par `tools/screenshots/`.

### Jamais la production, toujours la démonstration

Une capture prise sur la production met sur une page publique le nom et le
SIRET d'un vrai client, une adresse personnelle dans le menu, et des entrées
d'audit nominatives. Les captures se prennent en local avec les fixtures du
groupe `demo`, en 1600x1000, thème sombre.

Si un module n'a pas de jeu d'essai présentable, **on écrit les fixtures**
avant de photographier.

### Les outils

| Script | Rôle |
|---|---|
| `capture-tour.mjs` | prend les captures |
| `push-tour.mjs` | remplace une image déjà posée |
| `add-tour-cards.mjs` | ajoute des images à une page, de bout en bout |
| `set-tour-banner.mjs` | pose le bandeau du sommaire sur une page |

`tour-cards.json` est la seule table qui dise quelle prise va sur quel
document GED : `aurora:ged:replace` change le nom stocké à chaque
remplacement, donc le nom d'origine ne se retrouve plus. Une prise absente de
cette table sera réimportée et fera un doublon.

### Deux pièges de script, silencieux tous les deux

- **`execFile` ne parle pas à l'entrée standard** du processus qu'il lance.
  Un `input` passé en option est ignoré sans un mot : la commande tourne sans
  rien recevoir et le script se félicite. Passer par un fichier.
- **psql lit la valeur d'un `\set` comme sa propre syntaxe.** Le premier
  guillemet d'un objet JSON y devient une commande inconnue. Écrire le JSON
  dans un fichier et faire `\set x `cat fichier``.

### Après une écriture

Les scripts vident `cache.app` eux-mêmes. Vérifier ensuite les trois langues :
les slugs sont traduits (`/en/aurora/markdown-notes`,
`/es/aurora/notas-markdown`).

## Pourquoi

Avant le 19/09/2026, dix-sept des vingt-quatre images avaient été prises à la
main et personne ne savait laquelle venait d'où. Poser une image de plus
demandait cinq gestes, dont relever un identifiant à l'œil : un identifiant
faux donne une zone qui pointe vers le mauvais document, et ça reste invisible
jusqu'à ce que quelqu'un regarde la page.
