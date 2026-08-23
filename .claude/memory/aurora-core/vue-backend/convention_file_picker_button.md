---
name: convention-file-picker-button
description: AppFilePickerButton encapsule le pattern <input type="file" hidden> + <AppButton> trigger. Expose open() et reset() via ref.
metadata:
  type: feedback
---

# Convention : AppFilePickerButton pour les triggers d'upload

## Règle

Pour un bouton qui ouvre un dialog de sélection de fichier (≠ drop-zone visuelle),
utiliser `AppFilePickerButton` qui wrappe `<input type="file" class="hidden">` +
`<AppButton>` en un seul composant.

```vue
<AppFilePickerButton
    ref="myInput"
    accept="image/*"
    multiple
    variant="primary"
    size="md"
    :loading="uploading"
    v-on:change="handler"
>
    <Upload /> {{ t('upload') }}
</AppFilePickerButton>
```

L'event `change` reçoit l'Event natif (compatible avec les handlers existants type
`function uploadX(event) { event.target.files... }`). Alternative : event `files`
qui reçoit directement la `FileList`.

Via `ref`, le composant expose :
- `open()` - déclenche le picker programmatiquement
- `reset()` - vide la valeur (permet de re-sélectionner le même fichier)

## Why

4 fichiers reproduisaient quasi-identiquement le pattern `<input hidden>` +
`AppButton v-on:click="ref?.click()"` (PostSeoPanel, PostCustomField,
DocumentsApp/MediaApp historique, + variante dans PostFeaturedImagePanel).
Le composant réduit la duplication et formalise le pattern dans le design
system.

## How to apply

- Pour les composables qui clearent l'input (`useImageUpload`, `useMediaUpload`),
  utiliser `inputRef.value?.reset?.()` plutôt que `inputRef.value.value = ""`.
  Les deux composables ont été mis à jour avec un fallback pour compatibilité
  ascendante (au cas où on passerait encore un raw DOM ref).
- Le pattern `<label class="drop-zone"><input type="file" class="sr-only"></label>`
  (zone cliquable visuelle) reste légitime - ce n'est pas le même pattern.
  Voir `PostFeaturedImagePanel.vue` pour l'exemple.

## Source

Créé le 2026-05-13 lors du nettoyage de structure projet.
