---
name: convention_no_em_dash
description: Écriture - jamais de cadratin (U+2014), ni dans le code, ni dans les commentaires, ni dans les textes affichés
metadata:
  type: feedback
---

## Règle

**Aucun cadratin (`U+2014`) nulle part dans le projet.** Ni dans les textes affichés
à l'utilisateur, ni dans les commentaires, ni dans les docblocks, ni dans les
fichiers de documentation ou de mémoire, ni dans les messages de commit.

Le demi-cadratin (`U+2013`) tombe sous la même règle : il ne sert à rien qu'un autre
signe ne fasse mieux.

## Pourquoi

Préférence utilisateur explicite (23/08/2026). Le cadratin est aussi la marque
la plus reconnaissable de la prose générée : il s'accumule sans qu'on le
remarque, et une base qui en contient des milliers ne se lit plus comme du
texte écrit par quelqu'un. Au moment où la règle a été posée, le dépôt en
comptait **3 824**, dont 2 288 dans les fichiers Markdown.

## Comment l'appliquer

Ce n'est pas une substitution unique : le cadratin remplit plusieurs fonctions,
et chacune a un signe qui lui convient mieux.

| Fonction | Remplacer par | Exemple |
|---|---|---|
| Ce qui suit explique ce qui précède | `:` | `Deux bandes : commandes en haut, fil d'Ariane en bas.` |
| Apposition, incise courte | virgule(s) | `Le lien, déjà à 40px, remplissait la bande.` |
| Aparté détachable | parenthèses | `Le tiroir (une copie manuscrite) avait dérivé.` |
| Deux phrases indépendantes | point | `Rien n'échouait. Le maillon s'affichait.` |
| Intervalle, plage | `-` sans espaces | `lignes 12-18` |
| Liste, énumération en tête de ligne | `-` | `- premier point` |

Dans du texte affiché à l'utilisateur, choisir le signe au cas par cas : c'est
là que la ponctuation se lit vraiment.

Dans les commentaires et docblocks, le remplacement mécanique ` - ` → ` - `
reste acceptable : il ne peut pas produire de phrase fausse. Mais préférer le
signe juste quand on relit le passage de toute façon.

## Se nommer sans se contredire

Ce fichier ne peut pas écrire le caractère qu'il interdit : la passe de nettoyage
l'a balayé comme les autres, et la règle s'est retrouvée à dire « aucun `-` ».
Il est donc désigné par son point de code partout, et la commande de
vérification le cherche par échappement.

## Vérifier

```bash
grep -rnP "\x{2014}|\x{2013}" src tests migrations docs .claude bin Makefile
```

Aucun résultat attendu. À lancer avant un commit qui touche de la prose.
