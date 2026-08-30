# Catalogue des composants partagés (`@shared/components`)

> À lire quand vous écrivez un composant Vue côté client et que vous cherchez
> **le composant Aurora à utiliser** au lieu d'un élément HTML brut. Tous les
> `App*` recensés ici vivent dans
> `vendor/axelraboit/aurora/src/Core/assets/shared/components/` (alias Vite `@shared`).
> Ce doc est une **référence** - pour les conventions générales (imports,
> overrides, directives), voir [assets_vue.md](assets_vue.md).
>
> Règle de base (rappel) : **toujours préférer un `App*` à un élément HTML
> brut**, admin comme frontend public.

---

## Index

- [Action](#action) - `AppButton`, `AppIconButton`, `AppFilePickerButton`, `AppListItemButton`, `AppOverlayIconButton`, `AppTextLinkButton`, `AppThemeToggle`
- [Form](#form) - `AppInput`, `AppTextarea`, `AppSelect`, `AppMultiselect`, `AppCheckbox`, `AppToggle`, `AppRange`, `AppFieldLabel`, `AppSearchInput`, `AppDatePicker`, `AppColorField`, `AppColorPicker`, `AppColorSwatch`, `AppImagePickerField`, `AppFileInput`, `AppDropZone`, `AppTagsInput`, `AppPasswordStrength`
- [Feedback](#feedback) - `AppBadge`, `AppMessage`, `AppNoData`, `AppProgressBar`, `AppSelectionCheck`
- [Overlay](#overlay) - `AppModal`, `AppModalFooter`, `AppTooltip`
- [Nav](#nav) - `AppLink`, `AppNavLink`, `AppNavButton`, `AppPagination`, `AppLoadMore`, `AppTab`, `AppStagePicker`
- [Display](#display) - `AppImage`, `AppImagePreview`, `AppThumbnail`, `AppAvatar`, `AppLogo`, `AppChart`

---

## Action

### `AppButton`

Bouton standard. Couvre les variantes admin (primary/secondary/danger/ghost/…)
et frontend public (`front-*`). Rendu : `<button>` par défaut, ou `<a>` si
`href` fourni.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `type` | `String` | `"button"` | `button` / `submit` / `reset`. |
| `variant` | `String` | `"primary"` | `primary` `secondary` `danger` `danger-outline` `accent` `ghost` `dashed` `link` `link-accent` `icon` `front-ghost` `front-primary` `front-accent`. |
| `size` | `String` | `"md"` | `sm` `md` `lg` `none`. |
| `disabled` | `Boolean` | `false` | |
| `loading` | `Boolean` | `false` | Affiche un spinner et désactive le bouton. |
| `href` | `String` | `null` | Si fourni, rendu en `<a>` (pas de `submit` possible). |

**Slot** : default (label/icone/contenu).

```vue
<AppButton variant="primary" :loading="submitting" v-on:click="save">
    <Save class="w-3.5 h-3.5" /> {{ $t('shared.common.save') }}
</AppButton>
```

### `AppIconButton`

Bouton icône-only sur fond clair (table rows, panneaux). Pour les overlays
sombres, voir `AppOverlayIconButton`.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `color` | `String` | `"default"` | `default` `sky` `accent` `rose` `emerald` `amber` `on-light` (à utiliser sur fonds clairs custom - post-it, cards colorées : injecte `text-black/50 hover:text-black/80 hover:bg-black/10`). |
| `size` | `String` | `"md"` | `md` (p-1.5) ou `compact` (24×24). |
| `title` | `String` | `null` | Tooltip natif (`title` attribut). |
| `ariaLabel` | `String` | `null` | Étiquette a11y (fallback sur `title`). |
| `href` | `String` | `null` | Rendu `<a>` si fourni. |

```vue
<AppIconButton color="rose" :aria-label="$t('shared.common.delete')" v-on:click="del">
    <Trash2 class="w-4 h-4" />
</AppIconButton>
```

Gotcha : sans `title` ni `ariaLabel`, le bouton est rendu sans étiquette a11y
(lint:a11y CI le détectera).

### `AppFilePickerButton`

`<AppButton>` + `<input type="file" hidden>` encapsulé. Émet `change` et `files`.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `accept` | `String` | `""` | Filtre MIME (ex `image/*`). |
| `multiple` | `Boolean` | `false` | |
| `loading` | `Boolean` | `false` | |
| `disabled` | `Boolean` | `false` | |
| `variant` | `String` | `"primary"` | Passe à `AppButton`. |
| `size` | `String` | `"md"` | Passe à `AppButton`. |

**Emits** : `change(Event)`, `files(FileList)`.
**Expose** : `open()`, `reset()`.

```vue
<AppFilePickerButton accept="image/*" v-on:files="onFiles">
    Choisir une image
</AppFilePickerButton>
```

### `AppListItemButton`

Ligne pleine largeur pour menus déroulants / listes de résultats. Slot
`default` = label, `meta` = ligne trailing mutée, `icon` = glyphe.

| Prop | Type | Défaut |
|---|---|---|
| `active` | `Boolean` | `false` |

```vue
<AppListItemButton :active="isSelected" v-on:click="pick(item)">
    <template #icon><Folder class="w-4 h-4" /></template>
    {{ item.name }}
    <template #meta>{{ item.path }}</template>
</AppListItemButton>
```

### `AppOverlayIconButton`

Icône-only sur overlay sombre (lightbox, hover de carte photo).

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `size` | `String` | `"md"` | `xs` `sm` `md` `lg`. |
| `variant` | `String` | `"default"` | `default` (overlay photo) `light` (fond très sombre) `danger` (hover rouge). |
| `active` | `Boolean` | `false` | Switch couleur icône vers accent. |
| `title` | `String` | `null` | |
| `ariaLabel` | `String` | `null` | |

```vue
<AppOverlayIconButton variant="danger" :aria-label="$t('photo.remove')" v-on:click="remove">
    <X class="w-4 h-4" />
</AppOverlayIconButton>
```

### `AppTextLinkButton`

Mini-bouton style hyperlien inline (« Effacer », « Voir plus », « Annuler ce
changement »). Pas pour de la navigation (utiliser `AppLink`).

| Prop | Type | Défaut |
|---|---|---|
| `color` | `String` | `"default"` (`default` / `danger` / `muted`) |
| `size` | `String` | `"sm"` (`xs` / `sm` / `md`) |

### `AppThemeToggle`

Toggle dark/light auto-câblé via `useTheme()`. Pas de props.

```vue
<AppThemeToggle />
```

---

## Form

> Toutes les inputs émettent `update:modelValue` et acceptent `label`/`error`/`required` quand pertinent. Pour les détails par champ, voir la table.

### `AppInput`

Champ texte. Gère password toggle, focus/select expose, error message sous le champ.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `modelValue` | `String` | `""` | |
| `type` | `String` | `"text"` | `text` / `email` / `number` / `password` / etc. |
| `name` | `String` | `""` | |
| `placeholder` | `String` | `""` | |
| `label` | `String` | `""` | Rendu via `AppFieldLabel`. |
| `error` | `String` | `""` | Message d'erreur affiché sous le champ. |
| `required` | `Boolean` | `false` | Étoile rouge sur le label. |
| `toggleable` | `Boolean` | `false` | Bouton œil pour révéler un password. |
| `variant` | `String` | `"default"` | `default` (chrome form complet) ou `ghost` (rendu direct sans wrapper/label/bordure, `bg-transparent` + `text-inherit` - pour l'édition inline dans des cards / post-it / tableaux). Le sizing / decoration est fourni par le consumer via `class`. |

**Emits** : `update:modelValue`.
**Expose** : `focus()`, `select()`, `blur()`.

```vue
<AppInput
    v-model="form.name"
    :label="$t('agencies.name')"
    :error="errors.name"
    required
/>
```

### `AppTextarea`

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `String` | `""` |
| `placeholder` | `String` | `""` |
| `label` | `String` | `""` |
| `error` | `String` | `""` |
| `required` | `Boolean` | `false` |
| `rows` | `Number` | `3` |
| `mono` | `Boolean` | `false` (font monospace) |
| `maxlength` | `Number` | `null` |
| `variant` | `String` | `"default"` | Même sémantique que `AppInput` - `ghost` retire le chrome de formulaire et hérite de la couleur du parent. |

```vue
<AppTextarea v-model="form.description" :rows="5" :error="errors.description" />
```

### `AppSelect`

`<select>` natif stylé. Pour de la recherche/multi, utiliser `AppMultiselect`.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `modelValue` | `String` / `Number` | `""` | |
| `label` | `String` | `""` | |
| `error` | `String` | `""` | |
| `required` | `Boolean` | `false` | |
| `placeholder` | `String` | `""` | Option vide en tête. |
| `options` | `Array` / `Object` | `null` | `[{value, label}]` OU `{value: label}`. Laisser vide pour passer ses propres `<option>` via slot. |

```vue
<AppSelect v-model="form.role" :options="[{value:'admin',label:'Admin'}]" />
```

### `AppMultiselect`

Wrapper sur `vue-multiselect`. **Le composant le plus subtil** côté props.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `modelValue` | `String` / `Number` / `Array` / `Object` / `null` | `null` | Mono ou multi selon `multiple`. En mono : la valeur du `trackBy`. En multi : `Array` de valeurs `trackBy`. |
| `options` | `Array` | `[]` | Liste d'objets. |
| `label` | `String` | `""` | |
| `placeholder` | `String` | `""` | |
| `error` | `String` | `""` | |
| `required` | `Boolean` | `false` | |
| `multiple` | `Boolean` | `false` | |
| `searchable` | `Boolean` | `true` | |
| `allowEmpty` | `Boolean` | `false` | Autorise la déselection en mono. |
| `trackBy` | `String` | `"value"` | Nom de la propriété clé sur les options. |
| `optionLabel` | `String` | `"label"` | Nom de la propriété label sur les options. |
| `openDirection` | `String` | `"bottom"` | `bottom` / `top`. |

Gotcha : `modelValue` n'est pas l'option entière - c'est **la valeur de
`trackBy`** (le composant fait la résolution interne). En multi, c'est un
`Array` de ces valeurs.

```vue
<AppMultiselect
    v-model="form.parentId"
    :options="[{id:1,label:'Foo'},{id:2,label:'Bar'}]"
    track-by="id"
    option-label="label"
    :allow-empty="true"
/>
```

### `AppCheckbox`

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `Boolean` | `false` |
| `label` | `String` | `""` |
| `name` | `String` | `""` |
| `disabled` | `Boolean` | `false` |

### `AppToggle`

Switch on/off (gros bouton coulissant).

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `Boolean` | `false` |
| `label` | `String` | `""` |
| `disabled` | `Boolean` | `false` |

```vue
<AppToggle v-model="form.isVisible" />
```

### `AppRange`

Slider numérique.

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `Number` | **requis** |
| `min` | `Number` | `0` |
| `max` | `Number` | `100` |
| `step` | `Number` | `1` |
| `disabled` | `Boolean` | `false` |

### `AppFieldLabel`

Composant primitif utilisé par tous les autres champs. Rarement utilisé
seul - sortez-le quand vous montez un champ custom (DateRange, éditeur
Markdown, etc.) qui ne peut pas réutiliser un `App*` existant.

| Prop | Type | Défaut |
|---|---|---|
| `label` | `String` | `""` |
| `required` | `Boolean` | `false` |

### `AppSearchInput`

`AppInput` + icône loupe + bouton clear + **debounce intégré**.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `modelValue` | `String` | `""` | |
| `placeholder` | `String` | `""` | |
| `debounce` | `Number` | `300` | Délai (ms) avant émission de `search`. |
| `clearable` | `Boolean` | `true` | Affiche un bouton X. |

**Emits** : `update:modelValue` (immédiat), `search` (debounced).
**Expose** : `focus()`, `select()`, `blur()`.

```vue
<AppSearchInput v-model="search" v-on:search="onSearch" :debounce="400" />
```

### `AppDatePicker`

Wrapper `@vuepic/vue-datepicker`. **Ne jamais utiliser `<input type="date">`** -
mauvais rendu cross-browser et incohérent avec le thème.

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `String` | `""` (format ISO `YYYY-MM-DD` ou `YYYY-MM-DDTHH:mm` si `enableTime`) |
| `label` | `String` | `""` |
| `placeholder` | `String` | `""` |
| `required` | `Boolean` | `false` |
| `error` | `String` | `""` |
| `enableTime` | `Boolean` | `false` |

```vue
<AppDatePicker v-model="form.startDate" :label="$t('start')" />
```

### `AppColorField` / `AppColorPicker` / `AppColorSwatch`

Trois rôles distincts, à ne pas confondre (cf. mémoire `convention_color_picker`).

- **`AppColorSwatch`** - pastille couleur nue (cliquable, display only). Props : `modelValue`, `size` (`sm`/`md`), `disabled`.
- **`AppColorField`** - champ de formulaire avec swatch + hex input + label + error. Le plus courant.
- **`AppColorPicker`** - grille de 16 presets + hex input + clear. Pour les sélections sans contrainte hex saisie.

`AppColorField` props :

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `String` | `""` |
| `label` | `String` | `""` |
| `required` | `Boolean` | `false` |
| `error` | `String` | `""` |
| `showHex` | `Boolean` | `true` |
| `size` | `String` | `"md"` |

### `AppImagePickerField`

Champ "image" basé sur le MediaPicker Aurora. Stocke `{id, url}`.

| Prop | Type | Défaut |
|---|---|---|
| `label` | `String` | `""` |
| `hint` | `String` | `""` |
| `modelValue` | `Object` | `{id:null,url:null}` |
| `chooseLabel` | `String` | `""` |
| `changeLabel` | `String` | `""` |
| `removeLabel` | `String` | `""` |
| `size` | `Number` | `128` (px) |

```vue
<AppImagePickerField v-model="formImage" :label="$t('image')" />
```

> Gotcha : `modelValue` est **un objet** `{id, url}`. Si votre `editForm` a
> deux champs séparés (`imageId`, `imageUrl`), utilisez un `computed`
> get/set comme dans `ListingCategoriesApp.vue` (`formImage`).

### `AppFileInput`

Input fichier nu (rare en utilisation directe - préférez `AppFilePickerButton` ou `AppDropZone`).

| Prop | Type | Défaut |
|---|---|---|
| `accept` | `String` | `""` |
| `multiple` | `Boolean` | `false` |

**Emits** : `change(File | File[])`. **Expose** : `trigger()`.

### `AppDropZone`

Zone drag&drop avec affichage de l'état (idle / dragging / uploading).

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `accept` | `String` | `""` | |
| `multiple` | `Boolean` | `false` | |
| `uploading` | `Boolean` | `false` | Bascule le label vers `uploadingLabel`. |
| `hint` | `String` | `null` | Texte secondaire. |
| `label` | `String` | `null` | Label par défaut (sinon `shared.dropZone.cta`). |
| `dropLabel` | `String` | `null` | Label pendant `dragover`. |
| `uploadingLabel` | `String` | `null` | |

**Emits** : `change(File | File[])`.

### `AppTagsInput`

Saisie d'array de strings (`Enter` ou `,` pour valider, `Backspace` pour supprimer).

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `Array<String>` | `[]` |
| `label` | `String` | `""` |
| `placeholder` | `String` | `""` |
| `error` | `String` | `""` |

### `AppPasswordStrength`

Liste de critères password (longueur, majuscule, chiffre…) avec check vert si rempli.

| Prop | Type | Défaut |
|---|---|---|
| `password` | `String` | `""` |

```vue
<AppInput v-model="form.password" type="password" toggleable />
<AppPasswordStrength :password="form.password" />
```

---

## Feedback

### `AppBadge`

Pill coloré statut. `<span>` ou `<a>` si `href`.

| Prop | Type | Défaut |
|---|---|---|
| `color` | `String` | `"gray"` |
| `href` | `String` | `null` |
| `spinning` | `Boolean` | `false` (icône qui tourne, ex « En cours ») |

Couleurs supportées : `accent`, `rose`, `sky`, `amber`, `emerald`, `violet`,
`slate`, `gray`. **Pas de hex personnalisé** - c'est un set fermé.

```vue
<AppBadge color="emerald">{{ $t('status.active') }}</AppBadge>
```

### `AppMessage`

Bannière info/success/warning/danger avec icône.

| Prop | Type | Défaut |
|---|---|---|
| `variant` | `String` | `"info"` (`info` `success` `warning` `danger` `trash`) |
| `icon` | `Boolean` / Lucide component | `true` (icône par défaut), `false` (aucune), ou un composant Lucide custom |

```vue
<AppMessage variant="warning">{{ $t('soft_delete_warning') }}</AppMessage>
```

### `AppNoData`

État vide avec icône `Inbox`.

| Prop | Type | Défaut |
|---|---|---|
| `message` | `String` | `"Aucune donnée à afficher."` |
| `hint` | `String` | `""` (seconde ligne, ce qu'il faut faire) |

| Slot | Rôle |
|---|---|
| `action` | Le bouton qui sort du vide. À utiliser quand l'état vide **remplace**
la page : sur une page maître-détail vide, la barre latérale qui portait le
bouton « Créer » n'est pas rendue, donc sans ça le vide est un cul-de-sac. |

```vue
<AppNoData v-if="!items.length" :message="t('backend.forms.empty')">
    <template v-if="can('editorial.forms.create')" #action>
        <AppButton variant="primary" size="md" v-on:click="openCreate">…</AppButton>
    </template>
</AppNoData>
```

### `AppProgressBar`

| Prop | Type | Défaut |
|---|---|---|
| `value` | `Number` | **requis** (0-100) |
| `showLabel` | `Boolean` | `false` |
| `label` | `String` | `null` (par défaut `"{value}%"`) |
| `color` | `String` | `"accent"` (`accent` `emerald` `rose` `amber`) |
| `size` | `String` | `"md"` (`sm` ou `md`) |

### `AppSelectionCheck`

Pastille ronde « sélectionné » à overlay sur une carte. **Affichage uniquement** -
le toggle vient de l'élément cliquable parent.

| Prop | Type | Défaut |
|---|---|---|
| `active` | `Boolean` | `false` |
| `size` | `String` | `"sm"` (`xs` `sm` `md`) |

---

## Overlay

### `AppModal`

Modale principale du backend. API : `:show` + `v-on:close` - **pas
`v-model:open`**. Empêche le scroll body, gère Escape, Back button et
focus-trap.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `show` | `Boolean` | `false` | |
| `maxWidth` | `String` | `"md"` | `sm` `md` `lg` `xl` `2xl`…`7xl` `full`. |
| `closeable` | `Boolean` | `true` | Affiche le X et autorise Escape. |
| `title` | `String` | `null` | Si fourni, header auto avec titre + icône + X. |
| `icon` | `Component` | `null` | Composant Lucide à gauche du titre. |
| `noPadding` | `Boolean` | `false` | Désactive le padding intérieur (ex : pour un viewer full). |
| `scrollable` | `Boolean` | `true` | `max-h-[90vh]` + overflow auto sur le contenu. |

**Emits** : `close`.
**Slots** : `default` (corps), `footer` (généralement un `<AppModalFooter>`).

```vue
<AppModal
    :show="modal.open"
    max-width="lg"
    :title="$t('agencies.create')"
    :icon="Building"
    v-on:close="close"
>
    <form v-on:submit.prevent="submit">…</form>
    <template #footer>
        <AppModalFooter>
            <AppButton variant="ghost" v-on:click="close">{{ $t('cancel') }}</AppButton>
            <AppButton variant="primary" :loading="loading" v-on:click="submit">{{ $t('save') }}</AppButton>
        </AppModalFooter>
    </template>
</AppModal>
```

Gotcha : **pas de `confirm()` natif** dans le code - pour une confirmation,
utiliser une seconde `AppModal` avec `max-width="sm"` (cf. mémoire
`convention_modal_and_confirmation`).

### `AppModalFooter`

Conteneur flex pour les boutons de footer de modale.

| Prop | Type | Défaut |
|---|---|---|
| `bordered` | `Boolean` | `false` (ajoute une `border-t`) |

### `AppTooltip`

Tooltip flottant `title` + `description` téléporté au body (immunisé contre
`overflow: hidden`). Auto-flip si débordement viewport.

| Prop | Type | Défaut |
|---|---|---|
| `title` | `String` | `""` |
| `description` | `String` | `""` |
| `placement` | `String` | `"right"` (`right` `left` `top` `bottom`) |
| `delay` | `Number` | `200` (ms) |
| `disabled` | `Boolean` | `false` |

**Slots** : `default` (trigger), `content` (override complet du contenu).

```vue
<AppTooltip title="Projects" description="Manage projects and tasks">
    <button>…</button>
</AppTooltip>
```

---

## Nav

### `AppLink`

Lien standard. Variantes admin et front. Préférer à `<a>` brut.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `href` | `String` | **requis** | |
| `variant` | `String` | `"admin"` | `admin` / `front-nav` / etc. (cf. composant pour la liste à jour). |
| `size` | `String` | `"md"` | |
| `target` | `String` | `"_self"` | |
| `extraClass` | `String` | `""` | Classes Tailwind à ajouter (ex `text-primary`). |

### `AppNavLink`

Lien de sidemenu (item de navigation). Gère état actif, hover color, tooltip
collapsed.

| Prop | Type | Défaut |
|---|---|---|
| `href` | `String` | **requis** |
| `active` | `Boolean` | `false` |
| `activeColor` | `String` | `"accent"` (`accent` `rose`) |
| `hoverColor` | `String` | `"primary"` (`primary` `emerald` `amber` `rose` `accent`) |
| `target` | `String` | `null` |
| `sidemenuActive` | `Boolean` | `false` |
| `tooltipTitle` | `String` | `""` |
| `tooltipDescription` | `String` | `""` |
| `tooltipPlacement` | `String` | `"right"` |

### `AppNavButton`

Frère de `AppNavLink` mais en `<button>` (action sans navigation : theme
toggle, palette de recherche, …). Mêmes props sauf `href`/`active`.

| Prop | Type | Défaut |
|---|---|---|
| `hoverColor` | `String` | `"primary"` |
| `type` | `String` | `"button"` |
| `tooltipTitle` | `String` | `""` |
| `tooltipDescription` | `String` | `""` |
| `tooltipPlacement` | `String` | `"right"` |

### `AppPagination`

Pagination admin/AJAX (XHR, pas de reload).

| Prop | Type | Défaut |
|---|---|---|
| `page` | `Number` | **requis** |
| `totalPages` | `Number` | **requis** |

**Emits** : `change(newPage)`.

```vue
<AppPagination :page="page" :total-pages="totalPages" v-on:change="goToPage" />
```

### `AppLoadMore`

Bouton "Charger plus" pour endpoints paginés.

| Prop | Type | Défaut |
|---|---|---|
| `hasMore` | `Boolean` | `false` |
| `loading` | `Boolean` | `false` |
| `label` | `String` | `""` |

**Emits** : `load`.

### `AppTab`

Onglet unitaire (utilisé en boucle avec `v-for`). **Pas de composant
`AppTabs` parent** - vous gérez l'état actif dans le composant
consommateur.

| Prop | Type | Défaut | Description |
|---|---|---|---|
| `active` | `Boolean` | `false` | |
| `variant` | `String` | `"pill"` | `pill` (filtres/sidemenu) ou `underline` (switcher de panel). |
| `color` | `String` | `"accent"` | `accent` ou `rose`. |
| `size` | `String` | `"md"` | `md` `sm` `xs`. |
| `align` | `String` | `"left"` | `left` ou `center`. |
| `activeClass` | `String` | `null` | Override des classes actives (ex coloration par stage). |
| `inactiveClass` | `String` | `null` | Override des classes inactives. |
| `shapeClass` | `String` | `null` | Override du radius/shape (ex `rounded-full`). |

```vue
<AppTab
    v-for="locale in locales"
    :key="locale.code"
    size="xs"
    :active="activeTab === locale.code"
    v-on:click="activeTab = locale.code"
>{{ locale.label }}</AppTab>
```

### `AppStagePicker`

Sélecteur de stage (deal pipeline, etc.) avec badge couleur custom par stage.

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `String` | **requis** |
| `stages` | `Array` | **requis** |
| `labelFn` | `Function` | **requis** (`(stage) => string`) |
| `badgeFn` | `Function` | **requis** (`(stage) => badgeProps`) |
| `disabled` | `Boolean` | `false` |

---

## Display

### `AppImage`

Image avec fallback icône, gestion d'erreur, focal-point.

| Prop | Type | Défaut |
|---|---|---|
| `src` | `String` | `null` |
| `alt` | `String` | `""` |
| `objectFit` | `String` | `"cover"` (`cover` `contain` `fill` `none`) |
| `focalPoint` | `String` | `"50% 50%"` |
| `loading` | `String` | `"lazy"` (`lazy` ou `eager`) |
| `rounded` | `String` | `""` (n'importe quelle classe Tailwind `rounded-*`) |
| `fallbackIcon` | `Boolean` | `true` |

### `AppImagePreview`

Preview locale (ex après upload, avant submit).

| Prop | Type | Défaut |
|---|---|---|
| `src` | `String` | **requis** |
| `alt` | `String` | `""` |
| `size` | `String` | `"md"` (`sm` = max-h-48, `md` = max-h-64, `lg` = max-h-80) |
| `full` | `Boolean` | `false` (w-full) |

### `AppThumbnail`

Vignette miniature (lignes de tableau).

| Prop | Type | Défaut |
|---|---|---|
| `src` | `String` | `null` |
| `alt` | `String` | `""` |
| `size` | `String` | `"sm"` (`sm`=10×10, `md`=12×12, `landscape`=16×10) |

### `AppAvatar`

Avatar utilisateur. Affiche la photo si fournie, sinon les initiales.

| Prop | Type | Défaut |
|---|---|---|
| `name` | `String` | `""` |
| `firstName` | `String` | `""` |
| `lastName` | `String` | `""` |
| `email` | `String` | `""` |
| `photoUrl` | `String` | `""` |
| `size` | `String` / `Number` | `"md"` (`sm` `md` `lg` `xl`) - ou nombre de px |
| `variant` | `String` | `"soft"` (`soft` ou `solid`) |

```vue
<AppAvatar :name="user.name" :photo-url="user.photoUrl" size="lg" />
```

### `AppLogo`

Logo Aurora SVG.

| Prop | Type | Défaut |
|---|---|---|
| `size` | `Number` | `40` |

### `AppShareBar` (`@shared/components/chart/`)

Comment un total se répartit, en **une** barre horizontale empilée. La forme
juste pour une part-d'un-tout : cinq jauges partant chacune de zéro demandent au
lecteur de comparer cinq longueurs puis de les additionner, et un camembert rend
la comparaison plus difficile qu'une longueur.

| Prop | Type | Défaut |
|---|---|---|
| `segments` | `Array` | **requis** - `[{ key, label, value }]`, dans l'ordre de lecture |
| `firstSlot` | `Number` | `1` - premier créneau de couleur utilisé |

Les teintes viennent de `--chart-cat-1..8` (`css/base/chart.css`), dans un ordre
**fixe** et attribuées **par position**, jamais recyclées : au-delà du huitième
créneau, replier la queue dans « autre » plutôt que d'obtenir une couleur
indistinguable sous déficience de vision des couleurs. Les créneaux sont
attribués **avant** de retirer les valeurs nulles, pour qu'une catégorie garde sa
couleur quand une voisine se vide.

La légende n'est pas décorative : trois teintes du mode clair passent sous 3:1
contre une surface blanche, ce qui n'est autorisé que si la valeur est aussi
lisible en texte. Ne pas déplacer les comptes dans l'infobulle.

### `AppChart`

Wrapper Chart.js (vue-chartjs).

| Prop | Type | Défaut |
|---|---|---|
| `type` | `String` | **requis** (`doughnut` / `bar` / `line`) |
| `data` | `Object` | **requis** |
| `options` | `Object` | `{}` (mergé avec les défauts thème dark) |

**Lequel des deux ?** `AppShareBar` pour une composition unique dont les valeurs
doivent rester lisibles en texte : il rend du DOM, donc il hérite des tokens du
thème, des infobulles `AppTooltip` et du mode sombre sans code. `AppChart` pour
ce que Chart.js fait bien et que le DOM ne fait pas : séries temporelles,
plusieurs séries, axes. Il rend dans un canvas, donc ni tokens ni `AppTooltip`,
et sa palette par défaut n'est pas la palette validée.

---

## Composants complémentaires

### `AppBlockEditor` (`@shared/components/editor/`)

Wrapper Vue 3 autour d'**EditorJS** (éditeur block-based : paragraph, header,
list, image, code…). Utilisé par le sous-module Notes/Block.

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `Object` (data EditorJS) | `null` |
| `placeholder` | `String` | `""` |
| `readonly` | `Boolean` | `false` |
| `autofocus` | `Boolean` | `false` |

**Emits** : `update:modelValue(blocks)`, `ready`, `change`.

### `AppCardLink` (`@shared/components/nav/`)

Card cliquable façon "tuile de navigation" (dashboard, picker de module).

| Prop | Type | Défaut |
|---|---|---|
| `href` | `String` | **requis** |
| `title` | `String` | **requis** |
| `description` | `String` | `""` |
| `icon` | `String` | `null` |

### `AppNavListItem` (`@shared/components/nav/`)

Item de liste de navigation (mobile drawer, dropdown user). Distinct
d'`AppNavLink` (qui est sidemenu desktop).

### `AppListToolbar` (`@shared/components/list/`)

Toolbar standard au-dessus d'une liste admin : slot pour `AppSearchInput`,
filtres, bouton "Nouveau", bulk actions. Réutilisé par `useListPage`.

```vue
<AppListToolbar>
    <template #search><AppSearchInput v-model="search" v-on:search="onSearch" /></template>
    <template #actions><AppButton v-on:click="openCreate">{{ $t('common.new') }}</AppButton></template>
</AppListToolbar>
```

### `AppFloatingMenu` (`@shared/components/overlay/`)

Menu contextuel positionné via floating-ui (popper-like). Remplace les
anciens dropdowns ad-hoc.

| Prop | Type | Défaut |
|---|---|---|
| `placement` | `String` | `"bottom-start"` |
| `offset` | `Number` | `4` |

```vue
<AppFloatingMenu>
    <template #trigger="{ toggle }">
        <AppIconButton icon="more-vertical" v-on:click="toggle" />
    </template>
    <AppListItemButton v-on:click="edit">{{ $t('common.edit') }}</AppListItemButton>
    <AppListItemButton v-on:click="del">{{ $t('common.delete') }}</AppListItemButton>
</AppFloatingMenu>
```

### `AppLoader` (`@shared/components/feedback/`)

Spinner / loader inline. Préférer un état `loading` sur `AppButton` quand
applicable ; `AppLoader` est pour les zones de chargement page-level.

| Prop | Type | Défaut |
|---|---|---|
| `size` | `String` | `"md"` (`sm` `md` `lg`) |

### `AppColorPicker` (détail complet)

`AppColorPicker` est mentionné §Form (`AppColorField` / `AppColorPicker` /
`AppColorSwatch`) - précision sur le picker lui-même :

| Prop | Type | Défaut |
|---|---|---|
| `modelValue` | `String` (hex) | `null` |
| `palette` | `Array<string>` | palette par défaut (10 couleurs) |
| `allowCustom` | `Boolean` | `true` (permet la saisie hex libre) |

`AppColorField` = wrapper form avec label/error. `AppColorSwatch` = pastille
read-only utilisée en cell de tableau ou badge.

---

## Composants non documentés ici

Volontairement omis car internes ou trop nichés pour un usage client courant :

- Tous les composants spécifiques à un module (`PostEditor.vue`,
  `MediaPickerModal.vue`, etc.) - voir l'arborescence
  `src/Module/<Module>/assets/` et les docs correspondantes.

Pour la liste complète à un instant T :

```bash
ls vendor/axelraboit/aurora/src/Core/assets/shared/components/*/App*.vue
```
