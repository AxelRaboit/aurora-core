---
name: convention-locale-context
description: Convention sur les 3 services locale (LocaleContext, LocaleOptionsProvider, TranslationLocaleSyncer) et la règle d'usage en single_locale_mode
metadata:
  type: feedback
---

## Règle

Pour toute logique qui dépend de la **liste des locales actives**, passer
par les services dans `src/Core/Locale/Service/` plutôt que d'injecter
`kernel.enabled_locales` ou d'utiliser `LocaleEnum::values()` :

- `LocaleContextInterface::getActiveLocales()` - pour itérer dans le code
  applicatif (Managers, ViewBuilders, Serializers, etc.).
- `LocaleContextInterface::getAllLocales()` - pour les **outils statiques**
  indépendants du runtime (ex: `DumpJsTranslationsCommand`, dump
  d'assets) qui doivent traiter toutes les locales du bundle quelque
  soit le mode.
- `LocaleOptionsProviderInterface::getActiveOptions()` - pour produire
  `[{code, label}, ...]` aux composants Vue (onglets/selects). Combine
  `LocaleContext` + `LocaleRepository`.
- `TranslationLocaleSyncerInterface::stale($existing, $inputLocales)` -
  pour calculer quelles `XxxTranslation` supprimer lors d'un `update()`.
  Préserve **toujours** les locales hors mode actif (réversibilité du
  single mode).

## Pourquoi

Le toggle `single_locale_mode` (setting backend, groupe Localization)
est **réversible à chaud** : on ne touche pas au schéma, on filtre
juste à l'écriture/affichage. Cela impose deux invariants :

1. **WRITE = filtered, READ = unfiltered** : les Serializers retournent
   toutes les translations existantes (sinon on perd la trace des
   contenus dormants). Seules les **écritures** (Managers,
   InputFactories) sont filtrées par `getActiveLocales()`.
2. **Cleanup préserve les inactives** : si en single FR mode un admin
   sauve un tag, le `TranslationLocaleSyncer` ne touche pas la row EN
   existante. Sinon rebasculer en multi-langue afficherait des champs
   vides.

Hardcoder `'fr'`/`'en'` ou injecter `kernel.enabled_locales` casse ces
deux invariants et empêche aussi le client de substituer la liste de
locales (`#[AsAlias]` sur les interfaces permet la décoration).

## Comment l'appliquer

- **Backend ViewBuilder** : injecter `LocaleContextInterface` (ou
  `LocaleOptionsProviderInterface` si label DB nécessaire) et passer
  `getActiveLocales()` / `getActiveOptions()` dans la prop `locales` à
  Vue.
- **Manager** qui supprime des translations dans `applyInput()` :
  remplacer le `foreach getTranslations() + removeTranslation` par
  `foreach $this->translationSyncer->stale(...) as $stale` (cf.
  ListingTagManager, ListingCategoryManager, FormManager).
- **Serializer / READ path** : ne **pas** filtrer. Retourner toutes les
  rows existantes.
- **Fallback "translation default"** (ex: `getTranslation('fr')` dans
  un serializer compact) : utiliser
  `$this->localeContext->getDefaultLocale()` au lieu de `'fr'` hardcodé.
  Voir `PostSerializer`, `TaxonomyTermSerializer`, `SearchController`,
  `SitemapBuilder`, `ListingSerializer`.
- **Default-param `string $locale = 'fr'`** : à remplacer par un
  paramètre **requis** quand le caller a déjà la locale en scope (cf.
  `PostRepository::findPaginated`, `BlocksRenderer::render`,
  `OrderManager::createFromCart`/`checkout`,
  `UserManager::sendVerificationEmail` côté frontend). PHP n'accepte
  pas `LocaleEnum::default()->value` comme default-param (expression
  non-constante), donc soit on force le caller, soit on passe
  `?string $locale = null` et on résout via `LocaleContext` dans le corps.
- **Bundle config** (`AuroraBundle.php`) : `default_locale` /
  `enabled_locales` / `fallbacks` dérivent de `LocaleEnum::default()` /
  `LocaleEnum::values()`. Si on ajoute un cas à `LocaleEnum`, l'ensemble
  du bundle suit automatiquement.
- **Settings select** : dans `SettingsViewBuilder::resolveSelectOptions()`,
  les paramètres `DefaultLocale` et `EmailLocale` ont un type `select`
  avec options issues de `LocaleEnum::cases()` et labels via
  `shared.locales.<code>`. `EmailLocale` a une option « auto » (`value=''`)
  pour le fallback à la langue par défaut. `Timezone` est aussi en
  select via `DateTimeZone::listIdentifiers()`. Côté Vue, le renderer
  bascule sur `AppMultiselect` quand `options.length > 10`.

**Exceptions tolérées** (ne pas chercher à les refactoriser) :
`LocaleEnum.php` lui-même, `Twig/LocaleExtension.php` (table de mapping
`'fr' => 'Français'`), DataFixtures (seed bilingue),
`AbstractOrder::$locale = 'fr'` et `UserInput::$locale = 'fr'` (pas de
DI possible sur une propriété d'entité ou un DTO `readonly`),
`CountryEnum::label/options` (méthodes statiques d'enum).

Lié : [[convention-extensibility]], [[convention-manager-hooks]].

Doc dev : `docs/aurora-core/dev/single_locale_mode.md`.
