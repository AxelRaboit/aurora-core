# Catalogue des composables et utilitaires partagés

> À lire quand vous écrivez de la logique réutilisable côté client (form, HTTP,
> liste paginée, tree, slug, …) et que vous voulez savoir s'il existe déjà un
> composable Aurora à brancher au lieu de réécrire. Tout ce qui est documenté
> ici vit dans `vendor/axelraboit/aurora/src/Core/assets/shared/composables/` (alias Vite
> `@shared/composables`) ou `src/Core/assets/shared/utils/` (alias `@shared/utils`).
> Pour les `App*` composants, voir
> [shared_components_catalog.md](shared_components_catalog.md).

---

## 1. Composables - Form (`@shared/composables/form/`)

### `useForm()`

Validation client minimale + erreurs réactives.

```js
const { errors, validate, setErrors, clearErrors } = useForm();
```

| Membre | Type | Rôle |
|---|---|---|
| `errors` | `Ref<Record<string,string>>` | À binder sur `:error` des champs. |
| `validate(checks)` | `(Record<string, () => string|null>) => boolean` | `true` si aucune erreur. |
| `setErrors(map)` | | Remplace toutes les erreurs. |
| `clearErrors()` | | Vide. |

```js
const ok = validate({ name: () => required(t('required'))(form.name) });
if (!ok) return;
```

Préférer **`useFormAction`** ou **`useFormModal`** dans 90 % des cas - `useForm`
ne fait pas le HTTP.

### `useServerErrors()`

`useForm` + traduction automatique des erreurs serveur (clés i18n → strings)
+ toast `_global`.

```js
const { errors, validate, handleErrors, handleResponse, clearErrors } = useServerErrors();
```

`handleErrors(data.errors)` ou `handleResponse(data, () => toast.success(t(...)))`.

Utilisez-le directement seulement si vous ne pouvez pas utiliser `useFormAction`.

### `useFormAction({ rules?, url, body?, onSuccess? })`

**Le composable principal pour un submit de form.** Orchestration complète :
validation client → request → handle errors/success.

```js
const { errors, loading, submit, validate, clearErrors } = useFormAction({
    rules: () => ({ name: () => required(t('required'))(form.name) }),
    url:    () => createPath,
    body:   () => form,
    onSuccess: () => {
        toast.success(t('created'));
        showModal.value = false;
        await reload();
    },
});
```

Pitfall : `url` et `body` sont des **getters lazy** - ils sont appelés à
chaque `submit()`. Pratique pour les URLs avec ID dynamique.

### `useFormModal({ empty, fromEntity?, createUrl, editUrl, buildBody?, rules?, onSuccess? })`

Pattern unifié **create + edit** dans une seule modale. Gère l'état
`modal.open` + `modal.entity` + reset du form selon le mode.

```js
const { modal, form, errors, loading, openCreate, openEdit, submit, close } = useFormModal({
    empty:      () => ({ name: '' }),
    fromEntity: (item) => ({ name: item.name }),
    createUrl:  () => createPath,
    editUrl:    (item) => buildPath(updatePath, { id: item.id }),
    rules:      () => ({ name: () => required(t('required'))(form.name) }),
    onSuccess:  ({ isCreate }) => {
        toast.success(t(isCreate ? 'created' : 'updated'));
        reload();
    },
});
```

Template : `<AppModal :show="modal.open" v-on:close="close">…</AppModal>`.

### `useDelete(deletePath, onSuccess, successMessageKey)`

Flow confirmer → supprimer pour les CRUD admin. `deletePath` et
`successMessageKey` peuvent être des strings ou des getters
(utile pour soft/force delete selon onglet actif).

```js
const { pendingDelete, loading, confirm, submit } = useDelete(
    deletePath,             // string avec __id__
    reload,                 // callback success
    'agencies.deleted',     // i18n key
);

// Vue :
confirm(item);              // ouvre la modale (pendingDelete = item)
submit();                   // POST + toast + onSuccess(id)
```

Template type :

```vue
<AppIconButton color="rose" v-on:click="confirm(agency)"><Trash2 /></AppIconButton>

<AppModal :show="!!pendingDelete" max-width="sm" v-on:close="pendingDelete = null">
    <p>{{ $t('confirm', { name: pendingDelete?.name }) }}</p>
    <template #footer>
        <AppModalFooter>
            <AppButton variant="ghost" v-on:click="pendingDelete = null">Annuler</AppButton>
            <AppButton variant="danger" :loading="loading" v-on:click="submit">Supprimer</AppButton>
        </AppModalFooter>
    </template>
</AppModal>
```

### `useDetailDelete(deletePath, redirectPath)`

Variante pour les **pages détail** : POST sur `deletePath`, sur succès redirige
vers `redirectPath` (full reload).

```js
const { showDelete, loading, submit } = useDetailDelete(deletePath, listPath);
```

### `useInlineEdit()`

Inline-edit `{field, value}` + autres mutations one-shot (validate, approve, …).

```js
const { submit, saveField, request } = useInlineEdit();

await saveField(url, 'status', 'paid');                          // toast "Saved" auto
await submit(url, { field: 'status', value: 'paid' }, { silent: true });
```

Différence avec `useFormAction` : pas de validation client, pas d'erreurs
réactives - c'est pour les mutations "one button click → toast".

### `useSlugLock({ getTitle, setSlug })`

Slug auto-suit le titre tant que verrouillé ; débloquable manuellement.
Utilisé par `aurora-editorial`, qui expose le cadenas dans son éditeur de post.

À ne pas confondre avec [`slugifyIfEmpty`](#slugifytext--slugifyifemptycurrentslug-source),
qui ne remplit le slug que s'il est vide et ne le suit jamais ensuite. Les deux
existent parce qu'ils répondent à deux besoins différents : un cadenas visible
que l'utilisateur ouvre, ou un simple amorçage à la saisie. **Ne câblez
`useSlugLock` que si vous affichez son `toggle`** : verrouillé sans affordance,
il réécrit l'URL d'un contenu publié dès qu'on corrige son titre.

```js
const { locked, toggle } = useSlugLock({
    getTitle: () => form.translations[locale].name,
    setSlug:  (slug) => { form.translations[locale].slug = slug; },
});
```

Pitfall : `getTitle`/`setSlug` sont des fonctions, pas des `ref()` - c'est
volontaire pour cibler un champ nested dans `editForm`.

### `useAuthForm(initialErrors?)`

Variante front-public pour les flows d'auth (login/register/reset). Les
erreurs serveur arrivent en props Twig - `useAuthForm` les pré-traduit. Le
submit retombe sur le `<form>` natif (full reload).

```js
const { errors, submitOnValid } = useAuthForm(props.errors);
// <form v-on:submit.prevent="(e) => submitOnValid(e, { email: () => email(...)(form.email) })">
```

---

## 2. Composables - HTTP (`@shared/composables/http/`)

### Backend - `useRequest()`

**Le client HTTP standard du backoffice.** Toast d'erreur automatique sur
échec réseau / 5xx, garde anti-double-clic via `loading`.

```js
const { loading, request } = useRequest();
const data = await request(url, body, methodOrOpts);
```

| Arg | Type | Défaut |
|---|---|---|
| `url` | `String` | - |
| `body` | `*` | `null` (sérialisé JSON ; ou `rawBody` pour FormData) |
| `methodOrOpts` | `String` ou `{ method, signal, noGuard, rawBody }` | `"POST"` |

Options avancées :

- `method` - `GET` / `PUT` / `DELETE` / … (voir `HttpMethod`).
- `signal` - `AbortSignal` ; abort silencieux (retourne `null` sans toast).
- `noGuard` - désactive `loading` (utile pour séquences en boucle).
- `rawBody` - `FormData`/`Blob` à passer tel quel (skip `JSON.stringify`).

Retourne le payload JSON parsé, ou `null` en cas d'erreur (toast déjà émis).
Les status 400/409/422 sont **considérés comme métier** - vous récupérez le
JSON avec `data.success: false` et `data.errors`.

### Backend - `usePaginatedFetch(getPath, getExtraParams?, onData?, initialData?)`

Pagination XHR (sans reload). Émet `?page=N&...extraParams`.

```js
const { items, loading, page, totalPages, total, load, goToPage, reset } =
    usePaginatedFetch(listPath, () => ({ search: search.value }), null, initialPayload);
```

Pitfall : retourne `null` pour les paramètres `undefined/null/""` - ils ne
sont **pas** sérialisés dans l'URL. Pratique pour de la recherche optionnelle.

### Backend - `useLoadMore(path, initial?, getExtraParams?)`

Pagination "Load more" : append au lieu de remplacer.

```js
const { items, page, totalPages, hasMore, loading, loadMore } =
    useLoadMore('/api/posts', { items: ssrItems, page: 1, totalPages: 5 });
```

Template : `<AppLoadMore :has-more="hasMore" :loading="loading" v-on:load="loadMore" />`.

### Backend - `useImageUpload({ onSuccess, onError, endpoint? })`

Upload image multipart (par défaut `/backend/media/media/upload`).

```js
const { uploading, inputRef, uploadFromEvent } = useImageUpload({
    onSuccess: ({ media }) => { form.imageId = media.id; },
});
// <input type="file" ref="inputRef" hidden v-on:change="uploadFromEvent" />
```

### Frontend - `useRequest()` (`@shared/composables/http/frontend/`)

**Différent du backend :**

- Pas de toast automatique - vous gérez l'erreur inline (banner, field).
- Pas de garde `loading` - concurrent requests autorisés.
- Sur exception réseau : renvoie `{ success: false, errors: { _global: '…' } }`
  (pas `null`).

```js
const { loading, request } = useRequest();
const data = await request(url, payload);
if (!data?.success) bannerMessage.value = data.errors._global;
```

> **Règle dure** (mémoire `convention_no_raw_fetch`) : **jamais de `fetch()`
> brut** dans le code applicatif → `useRequest` (admin) ou
> `useRequest` (frontend) selon le contexte.

### Frontend - `usePaginatedSearch({ initialItems, initialPage, initialTotalPages, initialTotal, searchPath, itemsKey })`

Recherche + pagination pour pages publiques (frontend). Debounce 300 ms,
`itemsKey` pointe sur la clé du payload (`'posts'`, `'listings'`, …).

```js
const { items, query, page, totalPages, loading, onSearch, goToPage } = usePaginatedSearch({
    initialItems: props.posts,
    initialPage: props.page,
    initialTotalPages: props.totalPages,
    initialTotal: props.total,
    searchPath: props.searchPath,
    itemsKey: 'posts',
});
```

---

## 3. Composables - List (`@shared/composables/list/`)

### `useListPage(listPath, opts?)`

**Le composable de référence pour une liste admin CRUD complète.** Combine
pagination XHR + recherche debounced + sync URL.

```js
const { items, loading, page, totalPages, total, search, onSearch, goToPage, reload, load } =
    useListPage(props.listPath, {
        initialSearch: props.search,
        initialData:   props.initialData,       // payload SSR - skip le premier XHR
        extraParams:   () => ({ status: filter.value }),
        searchParam:   'search',                // nom du query param
        onData:        (data) => { extraTotals.value = data.totals; },
    });
```

Template :

```vue
<AppSearchInput v-model="search" v-on:search="onSearch" />
<AppPagination :page="page" :total-pages="totalPages" v-on:change="goToPage" />
```

### `useLocalPagination(source, perPage?)`

Pagination **côté client** sur un array déjà chargé (utile quand le backend
renvoie tout d'un coup).

```js
const { page, totalPages, paginatedItems, goToPage } = useLocalPagination(items, 10);
```

### `useMultiSelection()`

Set d'IDs sélectionnés + helpers toggle/selectAll/clear (galeries, médias…).

```js
const { selectedIds, isSelecting, toggle, selectAll, clear } = useMultiSelection();
toggle(media.id);
selectAll(items.value.map(i => i.id));
```

`selectedIds` est un `Set` réactif - `selectedIds.value.has(id)` côté template.

### `useUrlSearchSync(paramName?)`

Met à jour `?search=...` dans l'URL sans reload (replaceState).

```js
const syncUrl = useUrlSearchSync('q');
syncUrl(search.value);   // ?q=foo  ou retire ?q si vide
```

Utilisé en interne par `useListPage` - vous l'invoquez rarement directement.

### `useUrlSyncedState({ initial, serialize, deserialize, onSync? })`

État Vue ↔ URL bidirectionnel via `pushState`/`popstate`. Pour tabs/filtres
client-side où la Back button doit re-naviguer correctement.

```js
const { state, set } = useUrlSyncedState({
    initial: 'all',
    serialize: (next) => `?tab=${next}`,
    deserialize: () => new URL(location.href).searchParams.get('tab') ?? 'all',
    onSync: (next) => reload(next),
});
set('archived');     // pushState + onSync
```

Pitfall : **un seul `useUrlSyncedState` par page** - la clé `value` dans
`history.state` n'est pas namespacée.

---

## 4. Composables - Tree, Nav, Overlay, divers

### `useHierarchicalTree.js` (functions, pas un hook)

**Helpers de tree pour les admin trees (taxonomies, catégories, …).**
Pures fonctions, pas de réactivité Vue.

```js
import {
    buildTree,
    sortRecursive,
    flattenTreeForReorder,
    collectDescendantIds,
    findNodeInTree,
} from '@/shared/composables/tree/useHierarchicalTree.js';

const tree = buildTree(flatItems);                          // → roots[]
const reorderPayload = flattenTreeForReorder(tree);         // → [{id, parentId, position}]
const forbidden = collectDescendantIds(node);               // → Set<id> (pour éviter parent=self ou descendant)
const found = findNodeInTree(tree, id);                     // → node | null
```

Chaque item d'entrée doit avoir `id`, `parentId`, `position`. La fonction
ajoute un `children: []` aux items à partir de leur fusion.

### `useUrlPagination(param?)` (`@shared/composables/nav/`)

Pagination **full-reload** (frontend public, SEO-friendly).

```js
const { goToPage } = useUrlPagination('page');
goToPage(3);   // window.location.href = '...?page=3'
```

À utiliser quand le composant Vue ne wrappe qu'une partie de la page (le
serveur rend déjà l'archive).

### `useBackButtonClose({ isOpen, onClose })` (`@shared/composables/overlay/`)

Brancher la touche Back du navigateur sur la fermeture d'un overlay/modal.
**Utilisé en interne par `AppModal`** - vous l'invoquez rarement
directement, sauf si vous écrivez un overlay custom (drawer latéral, panel
plein écran).

```js
const { requestClose } = useBackButtonClose({
    isOpen: () => panel.open,
    onClose: () => panel.open = false,
});
```

### `usePrivileges()`

Check de permission côté Vue. Mirror exact du `ModulePermissionVoter` PHP.

```js
const { can, isDev, isAdmin } = usePrivileges();
if (can('agencies.delete')) { … }
```

Règles :
- Dev → toujours `true`.
- Admin → toujours `true`.
- User → seulement si la privilege est dans `window.__privileges__`.

### `useDebounce(callback, delay?)`

Debounce générique. Clear auto à l'unmount.

```js
const debouncedSave = useDebounce((value) => save(value), 500);
```

### `useTheme()`

Gestion thème dark/light (localStorage + `prefers-color-scheme`).

```js
const { theme, toggle } = useTheme();
// theme.value === 'dark' | 'light'
```

### `useKeyboardShortcut({ key, ctrl?, target? }, handler)`

Raccourci clavier global. `ctrl: true` matche Cmd sur macOS et Ctrl ailleurs.
`preventDefault()` appelé avant le handler.

```js
useKeyboardShortcut({ key: 'k', ctrl: true }, () => openPalette());
useKeyboardShortcut({ key: 'Escape' }, () => close());
```

### `usePersistedExpanded(storageKey)`

Map d'états expand/collapse persistée en localStorage. Default = expanded.

```js
const { isExpanded, toggle, getRaw } = usePersistedExpanded('aurora-tree-categories');
```

### `useResizable({ key, defaultValue, min?, max?, axis?, onChange?, getOrigin? })`

Drag-to-resize d'un panel (sidemenu, drawer). Persistance localStorage,
cursor lock pendant le drag.

```js
const { size, dragging, startResize, reset } = useResizable({
    key: 'aurora-sidemenu-width',
    defaultValue: 280,
    min: 200, max: 480,
});
// <div :style="{ width: size + 'px' }">
//   <div class="resize-handle" v-on:mousedown="startResize">
```

---

## 5. Utilitaires - Format (`@shared/utils/format/`)

### `slugify(text)` / `slugifyIfEmpty(currentSlug, source)`

```js
import { slugify, slugifyIfEmpty } from '@/shared/utils/format/slugify.js';
slugify('Café déjeuner');                          // → 'cafe-dejeuner'
slugifyIfEmpty(form.slug, form.name);              // ne touche pas si slug déjà rempli
```

### `formatCurrency(amount, currency?, opts?)` / `formatProductPrice(product)` / `formatCents(cents, currency?, placeholder?)` / `formatBpAsPercent(bp, placeholder?)`

```js
import { formatCurrency, formatCents, formatBpAsPercent } from '@/shared/utils/format/formatPrice.js';
formatCurrency(19.9, 'EUR');               // '19,90 €' (selon locale)
formatCents(1990, 'EUR');                  // '19,90 €'  (cents en entrée)
formatBpAsPercent(2000);                   // '20,00%'    (basis points)
```

Pitfall : `formatCurrency` prend des **unités** (19.9), `formatCents` prend
des **cents entiers** (1990). Les factures et invoice lines stockent en cents
(`amount_cents`) - utilisez `formatCents`.

### `parseMoney(raw)`

Parser tolérant pour saisies utilisateur / OCR : retourne des cents entiers.

```js
parseMoney('19,90 €');     // 1990
parseMoney('1.200,00');    // 120000
parseMoney('xyz');         // null
```

### `initials({ name?, firstName?, lastName?, email? })`

Initiales 1-2 lettres. Utilisé par `AppAvatar`.

### `truncate(text, length)`

```js
truncate('Lorem ipsum dolor', 8);   // 'Lorem ip…'
```

### `highlightMatch(text, query)`

HTML-highlight des tokens dans un texte (renvoie du HTML avec `<mark>`).
Tokens ≥ 2 char, escape regex appliquée.

```vue
<span v-html="highlightMatch(post.title, search)" />
```

---

## 6. Utilitaires - Validation (`@shared/utils/validation/`)

### `validators.js` - `required(msg)`, `email(msg)`, `url(msg)`, `compose(...validators)`

Factory pattern : chaque validator est une fonction `(value) => null | string`.

```js
import { required, email, compose } from '@/shared/utils/validation/validators.js';

const rules = () => ({
    name:  () => required(t('required'))(form.name),
    email: () => compose(required(t('required')), email(t('invalid_email')))(form.email),
});

useFormAction({ rules, url, body, onSuccess });
```

Pitfall : `required` considère `[]` et `"   "` comme vides. `email`/`url`
passent si vide (chainer avec `required` pour rendre obligatoire).

### `translateServerErrors(t, errors)`

Traduit les **clés i18n** retournées par le backend en strings finales
(les composants admin attendent du texte brut sur `:error`).

```js
const translated = translateServerErrors(t, data.errors);
// 'photo.galleries.errors.slug_taken' → 'Ce slug est déjà utilisé.'
```

Utilisé en interne par `useServerErrors`/`useFormAction` - invocation directe
seulement si vous gérez le HTTP vous-même.

---

## 7. Composables - Format & UI helpers

### `useDateFormat()` (`@shared/composables/format/`)

Format de date/heure suivant la locale courante (intl).

```js
const { formatDate, formatDateTime, formatRelative } = useDateFormat();
formatDate(post.createdAt);          // '15 mai 2026'
formatDateTime(post.updatedAt);      // '15 mai 2026 14:32'
```

### `useFileSize()` (`@shared/composables/format/`)

Format de taille fichier humain-readable.

```js
const { format } = useFileSize();
format(1024 * 1024 * 3.5);           // '3,5 Mo'
```

### `useRelativeTime()` (`@shared/composables/`)

Temps relatif réactif ("il y a 5 minutes") avec auto-refresh.

```js
const { relative } = useRelativeTime(() => message.sentAt);
// <span>{{ relative }}</span>  → s'auto-met à jour toutes les minutes
```

### `useMediaQuery(query)` (`@shared/composables/`)

Match d'une media query CSS, réactif.

```js
const isMobile = useMediaQuery('(max-width: 768px)');
// isMobile.value === true | false
```

### `useLayoutMount({ onMount?, onUnmount? })` (`@shared/composables/`)

Lifecycle helper pour des side-effects au mount/unmount d'un layout
(register/cleanup d'event listeners globaux, theme switch, etc.).

---

## 8. Composables - Auto-save (`@shared/composables/`)

### `useAutoSave({ value, save, debounce? })`

Debounced auto-save d'une valeur (form long, éditeur notes…). Gère
loading state, dirty flag, dernière sauvegarde, conflits.

```js
const { status, lastSavedAt, forceSave } = useAutoSave({
    value: () => editorContent.value,
    save: async (content) => await request('/save', { content }),
    debounce: 1500,
});
```

### `useAutoSaveStatusDisplay(status)`

Compagnon de `useAutoSave` : convertit le status en label/icône affichable.

```js
const { label, icon } = useAutoSaveStatusDisplay(status);
// <AppBadge :icon="icon">{{ label }}</AppBadge>
```

### `usePasswordGenerator()` (`@shared/composables/`)

Génération de mot de passe avec contrôles (longueur, classes de caractères).
Utilisé par le sous-module `PasswordGenerator` de Vault.

```js
const { length, options, password, generate, copy } = usePasswordGenerator();
```

### `useClientFilteredList(source, opts?)` (`@shared/composables/list/`)

Filtre/tri **côté client** d'une liste déjà chargée (search + custom filters
sans XHR). Utile quand le backend renvoie tout d'un coup et qu'on filtre dans
l'UI.

```js
const { items, search, filters, reset } = useClientFilteredList(props.allItems, {
    searchKeys: ['title', 'description'],
});
```

---

## 7. Utilitaires - HTTP (`@shared/utils/http/`)

### `buildPath(template, params)`

Remplace les placeholders `__name__` (URI-encoded).

```js
buildPath('/backend/platform/users/__id__/edit', { id: 42 });
// → '/backend/platform/users/42/edit'

buildPath('/backend/parameters/__key__', { key: 'site/name' });
// → '/backend/parameters/site%2Fname'
```

C'est le pattern standard d'Aurora pour les URLs avec ID dynamique (les
routes Symfony exportent `__id__` côté Twig).

### `HttpMethod`

Enum frozen des verbes HTTP.

```js
import { HttpMethod } from '@/shared/utils/http/httpMethod.js';
HttpMethod.Post;   // 'POST'
```

### `HttpStatus`

Enum frozen des status codes (`Ok`, `Created`, `BadRequest`, `Unauthorized`,
`Forbidden`, `NotFound`, `UnprocessableEntity`, `Conflict`,
`InternalServerError`, …).

```js
if (response.status === HttpStatus.UnprocessableEntity) { … }
```

### `submitForm(action, csrfToken, extraFields?)`

POST programmatique en construisant un `<form>` éphémère (logout, actions
CSRF nécessitant un full reload).

```js
submitForm('/logout', csrfToken);
submitForm('/admin/duplicate', csrfToken, { sourceId: 42 });
```

---

## 8. Utilitaires - i18n (`@shared/utils/i18n/`)

### `pickTranslation(entity, locale, fallbackLocale?)` / `translatedField(entity, field, locale, fallback?)`

Pour les entités exposant `translations: { [code]: { … } }`. Fallback chain :
locale demandée → fallbackLocale (`en`) → première dispo → `null`.

```js
import { pickTranslation, translatedField } from '@/shared/utils/i18n/pickTranslation.js';

const translation = pickTranslation(post, 'fr');           // → { name, slug, … } | null
const name = translatedField(post, 'name', 'fr', '#42');   // → string (jamais null)
```

Voir aussi la mémoire `utility_pick_translation` pour les cas tordus
(translations partielles, équivalent côté Twig via `LocaleExtension`).

---

## 9. Utilitaires - divers

### `lang.js` - `Locale`, `DEFAULT_LOCALES`, `LOCALE_LABELS`

Enum frozen des locales supportées (`fr`, `en`, `es`, `de`).

```js
import { Locale, LOCALE_LABELS } from '@/shared/utils/lang.js';
LOCALE_LABELS[Locale.Fr];   // 'Français'
```

### `documentPicker.js` - `openDocumentPicker({ imagesOnly?, mimeFilter?, multiple?, listPath? })`

Wrapper impératif autour du `DocumentPickerModal`. Retourne une Promise
résolue avec le document sélectionné (ou `null` si annulé). `multiple: true`
résout avec un tableau ; `imagesOnly` filtre côté client sur `image/*` ;
`mimeFilter` impose un MIME strict (ex : `application/pdf`).

```js
const doc = await openDocumentPicker({ imagesOnly: true });
if (doc) {
    form.imageId = doc.id;
    form.imageUrl = doc.fileUrl; // chemin servi via /uploads/{path}
}
```

> Utilisé par `AppImagePickerField` - invocation directe utile dans des
> contextes custom (blocks editor, attachments inline).
> Remplace l'ancien `openMediaPicker` retiré en Phase 4 du merge
> Media → GED (2026-05-30).

### `platform.js` - `isMac`, `modKeyLabel`

```js
import { isMac, modKeyLabel } from '@/shared/utils/platform.js';
// modKeyLabel = '⌘' (Mac) | 'Ctrl' (sinon)
```

---

## 10. Ce qui n'est pas listé ici

Volontairement omis (utilitaires de niche ou plombérie interne) :

- `@shared/utils/data/` - `deepMerge`, `mergeBlocks`, `parseJson`, `revisionDiff`, `blocksRenderer` : pertinents seulement pour l'éditeur de blocks Post / GED.
- `@shared/utils/seo/` - `jsonLd`, `seoCounter` : utilisés par les SEO panels frontend.
- `@shared/utils/tree/` - `folderTree` : helper module Media spécifique.
- `@shared/utils/enums/imageLoadStatus.js`, `@shared/utils/format/currencies.js`, `statusStyles.js` : enums/maps internes consommés par un seul composant Aurora.
- `@shared/utils/validation/passwordRules.js`, `passwordStrength.js`, `validation.js` (`EMAIL_REGEX`) : utilisés par `AppPasswordStrength`, rarement directement.

Pour découvrir ce qui existe à un instant T :

```bash
ls vendor/axelraboit/aurora/src/Core/assets/shared/composables/**/*.js
ls vendor/axelraboit/aurora/src/Core/assets/shared/utils/**/*.js
```
