---
name: decision-locale-added-in-core
description: Décision d'ajouter toute nouvelle locale dans aurora-core (LocaleEnum), jamais côté client - pour capitaliser et garantir la cohérence cross-écosystème
metadata:
  type: project
---

## Décision

Toute nouvelle locale (es, de, it…) s'ajoute **dans aurora-core**, jamais
côté aurora-client. Concrètement : nouveau case dans
`Aurora\Core\Locale\Enum\LocaleEnum`, clé `shared.locales.<code>` dans
`src/Core/translations/messages.{fr,en}.yaml`, et fichiers
`messages.<code>.yaml` stubés dans les modules.

## Pourquoi

`LocaleEnum` est la source de vérité unique pour **tout l'écosystème
multi-langue** : routes frontend (`/{locale}/...`), switcher public,
sitemap, RSS, settings select (Default Locale / Email Locale via
`LocaleEnum::cases()`), `LocaleSubscriber`,
`SingleLocaleRedirectSubscriber`, `DumpJsTranslationsCommand`,
`AuroraBundle::prependExtension()` (default_locale / enabled_locales /
fallbacks). Ajouter un case propage automatiquement la langue partout.

Trois raisons concrètes :
- **Cohérence** : une locale bricolée côté client ferait diverger les
  patterns - certains Managers la connaîtraient, d'autres non.
- **Capitalisation** : si un projet a besoin d'espagnol, il y a de
  bonnes chances qu'un autre projet en ait besoin aussi. Ajouter au
  core (+ stubs de traductions) bénéficie à tous.
- **Coût marginal nul** : `LocaleContext::getAllLocales()` et
  `getActiveLocales()` itèrent sur l'enum, le reste de l'app suit. Pas
  de refactor à faire dans l'app.

## Comment l'appliquer

Workflow pour ajouter une locale (ex: espagnol) :

1. **PR aurora-core** :
   - `case Spanish = 'es';` dans `LocaleEnum`
   - `shared.locales.es: Español` dans `src/Core/translations/messages.{fr,en}.yaml`
   - Pour chaque module avec `messages.fr.yaml` : créer un
     `messages.es.yaml` (peut démarrer comme copie de `messages.en.yaml`
     à traduire ensuite).
2. **Build assets** : `make translation` regénère
   `src/Core/assets/locales/generated/es.json`.
3. **Tests** : `LocaleEnum::values()` est déjà consommé dans les tests,
   rien à modifier en cascade.

Tout le reste suit automatiquement : nouvelle option dans le select
`Default Locale` de `/backend/configuration/settings`, ajout dans `/{locale}/...`,
émission dans le sitemap, etc.

Lié : [[pattern_single_locale_mode]], [[convention_locale_context]].
Côté client : `vendor/axelraboit/aurora/.claude/memory/aurora-client/pattern_locale_aware_extension.md`.
