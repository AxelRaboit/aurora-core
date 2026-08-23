# Convention : composants de formulaire + placeholders + UI primitives

## Règle 1 - Toujours utiliser les composants `App*`, jamais les éléments HTML bruts

**❌ Interdit** dans tout fichier `.vue` métier (sous `src/Core/assets/` ou
`src/Module/`) :

- `<button>` brut → utiliser `AppButton`, `AppIconButton`, `AppTab`,
  `AppNavButton` ou `AppListItemButton` selon le contexte.
- `<input>` brut → utiliser `AppInput`, `AppCheckbox`, `AppToggle`,
  `AppSearchInput`, `AppFileInput`, `AppDatePicker` selon le type.
- `<select>` brut → utiliser `AppSelect` ou `AppMultiselect`.
- `<a>` brut pour un lien texte/nav → `AppLink` (variant `admin`, `front`, `front-accent`, `front-nav`)
  - `front-nav` : hérite la couleur du parent - pour les liens dans un header qui fixe déjà la couleur via CSS variable
  - `extraClass` prop disponible pour les classes layout (`flex items-center gap-2 text-lg`)
  - **Exception** : `<a class="block">` ou `<a class="block ...">` wrappant une carte/image - `AppLink` n'est pas conçu pour les liens blocs

Le seul cas où le HTML brut est OK : les éléments de présentation pure
(`<div>`, `<span>`, `<p>`, `<h1>` à `<h6>`, `<table>`, etc.) ou les
contrôles spéciaux comme l'input du DatePicker que l'on n'expose pas.

### Quel composant pour quel besoin

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
| Upload fichier (bouton trigger) | `AppFilePickerButton` (wrap hidden input + AppButton) |
| Couleur (swatch nu) | `AppColorSwatch` (size="sm|md") |
| Couleur (champ form avec label/error/hex) | `AppColorField` |
| Slider / range (min-max) | `AppRange` (v-model Number, props min/max/step/disabled) |
| Pagination (prev/next + numéros) | `AppPagination` (émet `@change` avec le numéro de page) |

### Pourquoi
Cohérence visuelle, dark mode, accessibilité (focus, aria-label),
gestion uniforme des erreurs, support i18n. Un `<button>` brut casse le
design system et bloque les évolutions globales (ex: changer la radius
ou la couleur primaire ne propage pas).

### Audit
```bash
# Trouver les éléments HTML bruts là où il faudrait du App*
grep -rEn "<button\\b|<input\\b|<select\\b" src/Core/assets/ src/Module/ \
    --include="*.vue" \
  | grep -v ".test." | grep -v "node_modules"
```

Cas légitimes :
- input de type `checkbox` uniquement utilisé par un composant App* lui-même
- un `<button>` dans un composant App* primitive
- `<input type="hidden">` pour CSRF tokens (`name="_token"` ou `name="_csrf_token"`)
- `<input type="range">` → utiliser `AppRange` (`shared/components/form/AppRange.vue`)
- `<label>` wrapper avec `<input type="file" class="sr-only">` formant une drop-zone cliquable
  (différent du pattern bouton+hidden input qui doit utiliser `AppFilePickerButton`)
- `<label>` wrapper avec `<input type="checkbox" class="sr-only">` formant un toggle pill/badge
  personnalisé (quand `AppCheckbox` ne peut pas reproduire le style voulu)

Sinon refacto.

## Règle 2 - Toujours fournir un `placeholder` sur les inputs

Tout `AppInput`, `AppTextarea`, `AppDatePicker`, `AppSearchInput` doit
recevoir un `:placeholder` traduit. C'est :

- Une indication visuelle du format attendu (ex: `Europe/Paris`,
  `prenom@domaine.com`)
- Un exemple concret (ex: `ex. Réunion équipe, RDV client…`)

```vue
<AppInput
    v-model="form.title"
    :label="t('backend.events.fields.title')"
    :placeholder="t('backend.events.fields.titlePlaceholder')"
    :required="true"
/>
```

### Naming des clés i18n

```yaml
fields:
  title: Titre                         # label
  titlePlaceholder: ex. Réunion équipe # placeholder
```

Convention : `<field>Placeholder` à côté de `<field>`.

### Exception
Si le label est explicite et qu'aucun exemple n'a de sens
(ex: champ couleur `<input type="color">`), le placeholder peut être
omis - mais c'est rare.

## Règle 3 - Champs date/heure : `AppDatePicker` uniquement

**❌ Jamais** `<AppInput type="date">` ou `<AppInput type="datetime-local">`
ou `<input type="date">` natif. Le natif :

- A un look natif différent par OS / navigateur
- Pas thématisable (dark mode KO)
- Locale fr/en aléatoire selon le navigateur de l'utilisateur
- Pas de support i18n des labels (mois/jours)
- Pas de placeholder visible

**✅ Toujours** `AppDatePicker` (`@vuepic/vue-datepicker` wrappé).

```vue
<!-- date seule -->
<AppDatePicker
    v-model="form.startDate"
    :label="t('backend.events.fields.start')"
    :placeholder="t('backend.events.fields.startPlaceholder')"
    :error="errors.startDate ?? ''"
/>

<!-- date + heure -->
<AppDatePicker
    v-model="form.startAt"
    :label="t('backend.events.fields.startAt')"
    :placeholder="t('backend.events.fields.startAtPlaceholder')"
    :enable-time="true"
/>
```

`AppDatePicker` retourne :
- Sans `enable-time` : `"YYYY-MM-DD"`
- Avec `enable-time="true"` : `"YYYY-MM-DDTHH:MM"`

→ Compatible avec `new DateTimeImmutable($input)` côté backend, et
avec les format string ISO. Pas de transformation nécessaire.

## Règle 4 - Listes énumérées : un select avec choix

Pour des champs comme `timezone`, `country`, `language`, `currency`,
`role`, **toujours** un `AppSelect` peuplé via une liste curée - pas
un `AppInput` libre.

Pourquoi : éviter les fautes de frappe, garantir la validité
(un fuseau horaire mal écrit fait planter `DateTimeImmutable`), et
permettre à l'utilisateur de découvrir les valeurs disponibles.

Localisation des options : dans le composable `use<Plural>FormOptions.js`
du module concerné, sous forme d'un `computed` (pour que le label suive
la locale i18n).

```js
// usePlanningFormOptions.js
const TIMEZONE_VALUES = ["Europe/Paris", "Europe/London", /* … */];

export function usePlanningFormOptions() {
    const timezoneOptions = computed(() =>
        TIMEZONE_VALUES.map((value) => ({ value, label: value })),
    );
    return { timezoneOptions };
}
```

## Source

Conventions ajoutées le 2026-05-08 sur le module Planning :
- Refacto Planning timezone : `AppInput` → `AppSelect` avec liste curée
- Remplacement `<button>` legend → `AppTab` pill
- Ajout placeholders sur tous les inputs Planning
- `AppInput type="datetime-local"` → `AppDatePicker :enable-time`

À appliquer à tout nouveau formulaire.
