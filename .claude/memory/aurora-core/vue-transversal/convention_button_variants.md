---
name: convention_button_variants
description: Conventions de variants + size + icônes pour les boutons d'action (AppButton/AppIconButton) - homogène à travers tous les modules
metadata:
  type: feedback
---

## Règle

**Tout bouton d'action sémantique** (créer, modifier, supprimer, enregistrer,
annuler) suit un pattern unique selon son contexte. Pas de déviation -
si un cas n'entre pas dans la grille, le faire entrer.

## Pattern de référence

Module de référence : `Module/Editorial/backend/taxonomies/TaxonomiesApp.vue`.

### Modal footer

| Action | Variant | Size | Icon (w-3.5 h-3.5) | Label |
|---|---|---|---|---|
| Annuler / Fermer | `ghost` | `md` | `X` | `shared.common.cancel` |
| Enregistrer / Submit | `primary` | `md` | `Save` | `shared.common.save` |
| Confirmer suppression | `danger` | `md` | `Trash2` | `shared.common.delete` |

### Page header / row actions (création + édition + suppression)

| Action | Variant | Size | Icon (w-3.5 h-3.5) | Label |
|---|---|---|---|---|
| Ajouter / Créer | `primary` | `md` | `Plus` | `shared.common.add` ou domain-specific |
| **Modifier** | `secondary` | `md` | `Pencil` | `shared.common.edit` |
| Supprimer | `danger` | `md` | `Trash2` | `shared.common.delete` |

**`variant="secondary"`** (et non `ghost`) pour Edit en page header. Ghost
est trop discret (transparent) à côté d'un Delete rouge - `secondary`
donne un fond gris neutre (`bg-surface-3`) avec une bordure (`border-line`)
qui matche visuellement le poids du Delete.

### Icônes - règles fixes

- **Taille** : `class="w-3.5 h-3.5"` (= 14px). Plus jamais `w-4 h-4` sur
  les boutons d'action.
- **Stroke** : `:stroke-width="2"`. Pas 2.5, pas 1.5.
- **Source** : `lucide-vue-next`. Glyphes canoniques :
  - `Plus` (créer), `Pencil` (modifier), `Trash2` (supprimer),
    `Save` (enregistrer), `X` (annuler/fermer), `Check` (valider).

### Label

Toujours **visible** sur les boutons d'action en header/footer. Le label
ne doit jamais être en `sr-only` - l'utilisateur doit lire l'action.

Exception : actions toolbar dense (ex: tree row hover) où l'on peut
utiliser `AppIconButton` (variant icon-only), mais alors il faut un
`:title="…"` pour l'accessibilité.

### Label court (bouton) vs label complet (titre de modale)

| Surface | Label | Clé i18n |
|---|---|---|
| **Bouton visible** sous un header de section ("Termes" + "+") | court | `shared.common.add` → "Ajouter" |
| **Titre de modale** de création | complet | `backend.<module>.addX` → "Ajouter un terme" |
| **Tooltip d'AppIconButton** (icon-only) | complet | même clé que le titre de modale |

Quand la modale ne montre que "Ajouter" comme titre, c'est trop sec -
l'utilisateur perd le contexte de ce qu'il crée. Réserver la clé
spécifique (`addTerm`, `addTaxonomy`, `addProject`, …) pour la modale
et utiliser `shared.common.add` sur le bouton si le contexte (header
de section juste au-dessus) suffit à comprendre.

Si la même clé i18n sert pour bouton ET modale :
1. Mettre la VALEUR au label complet ("Ajouter un terme")
2. Modifier le bouton pour utiliser `shared.common.add` à la place
3. Garder la clé spécifique pour le titre de modale (et le tooltip
   d'AppIconButton si applicable)

### `size="md"` explicite

**Toujours** spécifier `size="md"` même si c'est le défaut. Rend
l'intention claire et évite les régressions silencieuses si le défaut
change.

## Variants disponibles dans AppButton

Source : `src/Core/assets/shared/components/action/AppButton.vue`.

| Variant | Usage type | Style |
|---|---|---|
| `primary` | Action principale (Submit, Create) | `bg-accent-600 text-white` |
| `secondary` | Action secondaire visible (Edit) | `bg-surface-3 border-line text-primary` |
| `ghost` | Action discrète (Cancel modal, toolbar) | `bg-transparent hover:bg-surface-2` |
| `danger` | Action destructive confirmée | `bg-rose-600 text-white` |
| `danger-subtle` | Action destructive qui ouvre une confirm | tons rose subtils |
| `nav` | Sidemenu list entry (full width) | bg-surface + border, active accent |

## Pourquoi

- Réduit la charge cognitive : l'utilisateur reconnaît instantanément le
  type d'action peu importe le module.
- Évite les "tons clairs/transparents" pour Edit qui se perdent à côté
  d'un Delete rose.
- Cohérence visuelle = signe de qualité, un module qui dévie suggère un
  bug ou un oubli.

## Comment l'appliquer

1. **Nouveau bouton** d'action → choisir la ligne dans la grille
   ci-dessus, copier-coller (variant + size + icône + classe + stroke).
2. **Refacto d'un module** : audit avec
   ```bash
   grep -rnE 'AppButton variant="(ghost|primary|danger|secondary)"' \
       src/Module/<Name>/assets/ | grep -v 'size='
   ```
   → ajouter `size="md"` partout.
3. **Edit/Delete cote-à-cote** en header → vérifier le couple
   `secondary` + `danger` (pas `ghost` + `danger`).
4. **Icônes** → vérifier `w-3.5 h-3.5` partout.

### Modules de référence conformes

| Module | Fichier | Pattern |
|---|---|---|
| Editorial / Taxonomies | `TaxonomiesApp.vue` | Header secondary+danger, modal footer ghost+primary/danger |
| Editorial / PostTypes | `PostTypesApp.vue` | Idem |
| Editorial / Menus | `MenuEditorPanel.vue` | Header secondary+danger (depuis 2026-05-15) |
| Notes / Markdown | `MarkdownNotesApp.vue` | Header primary+danger (Save + Delete) + modal footer ghost+danger |
| Photo / Galleries | `GalleriesApp.vue`, `GalleryEditApp.vue` | Conforme |
| Project / Projects | `ProjectsApp.vue` | Conforme |
| Vault | `VaultEntryFormModal.vue` | Conforme |

## Source

Conventions consolidées le 2026-05-15 après audit complet du projet
(homogénéisation cross-module à partir du pattern Taxonomies).
