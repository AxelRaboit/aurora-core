---
name: convention-color-picker
description: 3 composants couleur - AppColorSwatch (swatch nu), AppColorField (form), AppColorPicker (preset grid). Choisir selon le contexte.
metadata:
  type: feedback
---

# Convention : 3 composants pour les couleurs

## Règle

Selon le contexte UI :

| Besoin | Composant |
|--------|-----------|
| Swatch isolé dans un layout custom (avec label/reset autour) | `AppColorSwatch` |
| Champ form classique (label + swatch + hex affiché + error) | `AppColorField` |
| Picker complet avec presets + roue native + input hex | `AppColorPicker` |

`AppColorPicker` est la **valeur par défaut** pour un form admin (presets
pour discoverability, hex input pour précision, swatch natif intégré pour la
roue OS). Préférer à `AppColorField` sauf si l'écran a besoin d'un picker
minimal sans presets.

**❌ Jamais** `<input type="color">` brut ni `<AppInput type="color">` (hack qui
contournait l'absence de composant dédié).

## AppColorSwatch

Wrap minimal du swatch HTML natif avec styling cohérent.

```vue
<AppColorSwatch v-model="color" size="sm" />  <!-- w-8 h-8 -->
<AppColorSwatch v-model="color" />            <!-- w-10 h-10 (default) -->
```

Pour un layout très custom (ex: ThemesApp avec container `bg-surface-2` +
label en colonne + reset button), composer manuellement autour de `AppColorSwatch`.

## AppColorField

Champ form complet (équivalent `AppInput` mais pour couleur).

```vue
<AppColorField
    v-model="form.color"
    :label="t('fields.color')"
    :error="errors.color"
    :show-hex="true"     <!-- default true -->
    size="md"            <!-- sm | md -->
/>
```

## AppColorPicker

Composant existant : preset grid 4×4 + input hex éditable + bouton clear.
Utilisé pour les pickers riches (vraiment "choisir une couleur").

**Presets configurables depuis l'admin** : les 16 couleurs par défaut
peuvent être éditées via `/backend/configuration/settings` → tab "Apparence" → "Palette
du picker de couleurs". L'`ApplicationParameter` `color_picker_presets`
(group `appearance`, type `json`) stocke la liste. Le composant lit
`window.__auroraConfig.colorPickerPresets` (injecté par
`AppearanceExtension` dans `src/Core/templates/Core/backend/layout.html.twig`) avec
fallback hardcodé sur les 16 couleurs par défaut. Cf
[[pattern_app_config_bootstrap]] pour le pattern d'injection.

## Why

3 fichiers avaient `<input type="color">` raw avec styling quasi-identique
(VaultApp, PlanningsApp, ThemesApp×2). PlanningsApp utilisait même
`<AppInput type="color">` comme workaround. Aucun composant ne couvrait le
swatch compact, seul `AppColorPicker` (preset grid full) existait.

## How to apply

- Pour un form simple : `AppColorField` (suit la convention des autres champs)
- Pour un swatch dans un layout custom : `AppColorSwatch` + composer le label/reset
- Pour une vraie expérience picker avec presets : `AppColorPicker`

## Source

Créé le 2026-05-13.
