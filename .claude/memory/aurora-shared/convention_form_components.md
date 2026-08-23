---
name: convention_form_components
description: Toujours utiliser les composants App* (AppButton, AppInput, AppSelect…) - jamais les éléments HTML bruts dans les vues métier
metadata:
  type: feedback
---

## Règle

**Jamais d'éléments HTML bruts** dans un fichier `.vue` métier. Toujours les composants `App*` correspondants.

### Tableau de correspondance

| Besoin | Composant |
|--------|-----------|
| Action principale / submit | `AppButton variant="primary"` |
| Action secondaire | `AppButton variant="ghost"` |
| Action destructive (final confirm) | `AppButton variant="danger"` |
| Action destructive (ouvre confirm) | `AppButton variant="danger-subtle"` |
| Icône cliquable (poubelle, crayon) | `AppIconButton color="rose|accent|default"` |
| Filtre/onglet pill (multi ou mono select) | `AppTab variant="pill"` |
| Onglet sous-section (panel switcher) | `AppTab variant="underline"` |
| Champ texte | `AppInput` |
| Texte multiligne | `AppTextarea` |
| Sélection unique | `AppSelect` |
| Sélection multiple | `AppMultiselect` |
| Booléen (toggle compact) | `AppToggle` |
| Checkbox classique | `AppCheckbox` |
| Date / datetime | `AppDatePicker` (jamais `type="date"` natif !) |
| Recherche (avec icône loupe) | `AppSearchInput` |
| Upload fichier (drag&drop) | `AppFileInput` ou `AppDropZone` |
| Upload fichier (bouton trigger) | `AppFilePickerButton` |
| Couleur (swatch nu) | `AppColorSwatch` (size="sm|md") |
| Couleur (champ form avec label/error/hex) | `AppColorField` |
| Slider / range (min-max) | `AppRange` |
| Pagination (prev/next + numéros) | `AppPagination` (émet `@change` avec le numéro de page) |
| Lien texte/nav | `AppLink` (variant `admin`, `front`, `front-accent`, `front-nav`) |

### Cas légitimes pour le HTML brut
- `<div>`, `<span>`, `<p>`, `<h1>`-`<h6>`, `<table>` (présentation pure)
- `<input type="hidden">` pour CSRF tokens
- `<label>` wrapper avec `<input type="checkbox" class="sr-only">` pour toggle pill custom
- `<a class="block">` wrappant une carte/image (lien bloc)

## Règle complémentaire - Required-ness : astérisque, jamais « (optionnel) »

**Règle dure** : la présence/absence de l'astérisque rouge code la required-ness, **jamais** le texte du label.

| Cas | ❌ Interdit | ✅ Bon |
|---|---|---|
| Champ requis | `Nom (requis)` | `Nom` + `:required="true"` |
| Champ optionnel | `Description (optionnel)` | `Description` (sans `required`) |
| EN | `Title (optional)` | `Title` (sans `required`) |
| FR | `Description (optionnelle)` | `Description` (sans `required`) |

**Pourquoi** : `AppFieldLabel` rend automatiquement `*` rouge quand `required="true"` est passé. Ajouter « (optionnel) » dans la traduction crée deux sources de vérité (le label *dit* optionnel, l'asterisk *dit* required) → désynchronisation garantie + verbosité visuelle inutile.

**Comment l'appliquer** :

```vue
<!-- Champ requis : asterisk rendu automatiquement -->
<AppInput
    v-model="form.name"
    :label="t('personal_finance.wallets.fields.name')"
    :placeholder="t('personal_finance.wallets.placeholders.name')"
    :error="formErrors.name"
    required
/>

<!-- Champ optionnel : pas de required, pas de mention dans le label -->
<AppMultiselect
    v-model="form.categoryId"
    :label="t('personal_finance.transactions.fields.category')"
    :placeholder="t('personal_finance.transactions.placeholders.category')"
    :options="categoryOptions"
    :allow-empty="true"
/>
```

**Composants supportant `required` Boolean** (rendent l'asterisk via `AppFieldLabel`) : `AppInput`, `AppTextarea`, `AppAmountInput`, `AppDatePicker`, `AppSelect`, `AppMultiselect`, `AppColorPicker`, `AppColorField`. Si un nouveau composant form est créé, il **doit** propager `required` à `AppFieldLabel`.

**Détection rapide des violations** :

```bash
grep -rnE "optionnel|optional\)|\\(opt\\)" src/Module/*/translations/ \
  | grep -vE "Placeholder:|placeholder:"
```

(Les placeholders peuvent à la rigueur évoquer l'optionalité par leur contenu - « Ex. courses du samedi… » est mieux mais « (optionnel) » dans un placeholder reste toléré. Dans un **label**, jamais.)

## Règle complémentaire - DTO ↔ UI required mirror

L'attribut `required` côté Vue doit être miroir de la contrainte côté DTO :

| Côté Vue | Côté DTO PHP |
|---|---|
| `required` sur `AppInput` (string) | `Assert\NotBlank` |
| `required` sur `AppMultiselect/AppSelect` (id/enum) | `Assert\NotNull` (+ `Assert\Positive` pour les ids) |
| `required` sur `AppDatePicker` | `Assert\NotNull` (param `DateTimeImmutable`) |
| `required` sur `AppAmountInput` | `Assert\NotBlank` + `Assert\Regex` + `Assert\GreaterThan('0')` |

**Pourquoi** : un champ requis côté UI mais non-validé côté serveur passe en silence (utilisateur soumet vide → 200, données pourries). L'inverse (validé serveur mais pas marqué côté UI) frustre l'utilisateur qui se prend une erreur après submit sans signal préalable.

## Règle complémentaire - Erreurs : `:error` toujours bindé

Tout champ d'un formulaire qui peut échouer côté serveur doit binder `:error="formErrors.fieldName"` où `formErrors` est un `ref({})` peuplé par la réponse `{ success: false, errors: {…} }` du backend.

```vue
<AppAmountInput
    v-model="form.amount"
    :label="t('personal_finance.transactions.fields.amount')"
    :placeholder="t('personal_finance.transactions.placeholders.amount')"
    :error="formErrors.amount"
    required
/>
```

Le composable form gère la mécanique (`errors.value = payload.errors ?? {}` en cas de `success === false`). Voir [[convention_vue_form_validation]] pour `useForm` + `required()` côté validators.

## Règle complémentaire - Placeholder obligatoire

Tout `AppInput`, `AppTextarea`, `AppDatePicker`, `AppSearchInput`, `AppAmountInput` doit recevoir un `:placeholder` traduit.

```vue
<AppInput
    v-model="form.title"
    :label="t('backend.events.fields.title')"
    :placeholder="t('backend.events.fields.titlePlaceholder')"
    required
/>
```

**Contenu du placeholder** : exemple concret (« Ex. courses du samedi, abonnement Netflix… »), format attendu (« AAAA-MM-JJ »), ou imperatif court (« Rechercher… »). **Jamais** « (optionnel) » - l'absence d'asterisk encode déjà l'optionalité.

**Convention de clé i18n** (deux patterns coexistent, choisir selon le module) :
- `<field>Placeholder` à côté de `<field>` (Editorial, Crm, Billing…)
- `fields.<field>` + `placeholders.<field>` (PersonalFinance, Photo…)

Choisir un pattern par module et s'y tenir.

## Règle complémentaire - Date/heure : AppDatePicker uniquement

**Jamais** `<AppInput type="date">` ou `<input type="date">` natif. Toujours `AppDatePicker`.

- Sans `enable-time` → retourne `"YYYY-MM-DD"`
- Avec `:enable-time="true"` → retourne `"YYYY-MM-DDTHH:MM"`

Compatible avec `new DateTimeImmutable($input)` côté Symfony sans transformation.

## Règle complémentaire - Listes énumérées : AppSelect avec liste curée

Pour `timezone`, `country`, `language`, `currency`, `role` - toujours un `AppSelect` peuplé via une liste curée dans un composable `use<Plural>FormOptions.js`.

## Pourquoi

Cohérence visuelle, dark mode, accessibilité (focus, aria-label), gestion uniforme des erreurs, support i18n. Un `<button>` brut casse le design system et bloque les évolutions globales.

## Comment l'appliquer

```bash
# Audit : trouver les éléments HTML bruts à remplacer
# Côté core : src/ ; côté client : assets/
grep -rEn "<button\b|<input\b|<select\b" src/ assets/ \
    --include="*.vue" \
  | grep -v ".test." | grep -v "node_modules"
```
