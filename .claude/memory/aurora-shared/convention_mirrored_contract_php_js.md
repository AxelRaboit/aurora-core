---
name: convention_mirrored_contract_php_js
description: Quand une liste de valeurs ou une règle est écrite à la fois côté PHP (normaliseur) et côté JS (éditeur), elle doit être tenue par un test statique qui lit le fichier JS. Un commentaire « Mirrors X » n'est pas une contrainte.
metadata:
  type: project
---

## Règle

Dès qu'une valeur fait autorité côté serveur **et** doit être connue de
l'éditeur Vue sans aller la demander, la duplication est acceptée — mais
**elle doit être tenue par un test**, pas par un commentaire.

- Côté PHP : la constante vit sur le service qui valide (`GridNormalizer::RATIOS`,
  `::SCALES`, `::ZONE_TYPES`…) et elle est `public`, parce qu'elle est un
  contrat et non un détail interne.
- Côté JS : la même liste est exportée du composable (`ZONE_RATIOS`,
  `ZONE_SCALES`…), avec un commentaire `Mirrors GridNormalizer::X`.
- **Un test PHP lit le fichier JS** et compare les deux, dans l'ordre.

Référence : `tests/Unit/Module/Editorial/Post/Grid/GridContractMirrorTest.php`.
Précédent de la même famille : `tests/Unit/AuroraGridGutterTest.php`, qui lit du
Vue et du CSS pour interdire un `gap-x` sur `.aurora-grid`.

## Pourquoi

**Why:** l'éditeur ne peut pas interroger le serveur à chaque frappe, donc il
porte le vocabulaire en double. Le jour où quelqu'un ajoute un rapport d'image
d'un seul côté, **rien ne casse bruyamment** : soit l'éditeur cesse de le
proposer, soit il propose une valeur que le normaliseur jette silencieusement à
l'enregistrement. Aucun test ne tombe, aucune erreur n'apparaît, et personne ne
le découvre avant qu'un auteur ne s'en plaigne.

Ce n'est pas hypothétique : la première exécution de `GridContractMirrorTest` a
trouvé `LEAF_ZONE_TYPES` dans deux ordres différents entre PHP et JS.

Le test est **statique** — il lit le fichier, il ne l'exécute pas — pour la même
raison que `AuroraGridGutterTest` : la suite Playwright ne tourne ni dans
`make ft` ni en CI, donc un contrôle end-to-end n'empêcherait pas le push.

## Comment l'appliquer

### 1. À l'écriture

Quand tu ajoutes une liste de valeurs autorisées côté PHP qui doit apparaître
dans l'éditeur :

```php
/** Mirrored in usePostGrid.js, held by GridContractMirrorTest. */
public const array SCALES = [25, 33, 50, 66, 75, 100];
```

```js
/** Mirrors GridNormalizer::SCALES — … */
export const ZONE_SCALES = [25, 33, 50, 66, 75, 100];
```

Puis ajoute une ligne au `mirrors()` du test :

```php
yield 'scales' => [GridNormalizer::SCALES, 'ZONE_SCALES'];
```

### 2. Ce qui se compare, et ce qui ne se compare pas

| Type | Tenu par un test statique ? |
|---|---|
| Liste de valeurs autorisées | ✅ oui, dans l'ordre |
| Plafond numérique (`MAX_…`, `COLUMNS`) | ✅ oui |
| **Comportement** (une fonction de clamp, un calcul de placement) | ❌ non |

Une fonction dupliquée ne se compare pas statiquement — il faudrait la parser.
Elle se tient autrement : **les deux côtés ont leurs propres tests, sur les
mêmes cas nommés pareil**. Voir `GridNormalizer::place()` /
`placeZones()`, dont les cas se répondent un à un dans `GridNormalizerTest` et
`usePostGrid.test.js`.

### 3. L'ordre compte

Compare dans l'ordre, pas comme des ensembles. Pour presque toutes ces listes le
premier élément est la valeur par défaut (`SNAPS[0]`, `ALIGNMENTS[0]`,
`RATIOS[0]`), et pour les autres l'ordre est celui des boutons que l'auteur lit.
Deux listes qui prétendent être la même doivent être la même.

### 4. Vérifier

```bash
php vendor/bin/phpunit --filter GridContractMirrorTest
```

Voir [[convention_sfc_thin_presentation]] (le composable est l'endroit où vit
la liste côté JS) et [[process_make_ft_before_commit]].
