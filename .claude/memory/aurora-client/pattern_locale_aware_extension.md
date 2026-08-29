---
name: pattern-locale-aware-extension
description: Comment étendre/respecter la gestion multi-langue (single_locale_mode) côté aurora-client
metadata:
  type: reference
---

## Quand ça te concerne

Trois scénarios distincts. Identifie le tien avant de coder :

### 1. Tu étends une entité multilingue existante d'aurora-core

(Post, Taxonomy, TaxonomyTerm, ListingTag, ListingCategory, Form,
FormField, MenuItem…)

**Tu n'as rien à faire de spécial.** Le Manager parent
(`ListingTagManager` etc.) gère déjà :
- l'itération sur `$input->getTranslations()` reçues du Vue layer
- la suppression sélective via `TranslationLocaleSyncer::stale()` (préserve
  les locales dormantes quand `single_locale_mode` est ON)

Suis le pattern `pattern_extend_entity` + `pattern_extend_manager` + ...
comme d'habitude. La logique locale est transparente.

**Piège** : si tu surcharges `applyInput()` côté client, **appelle
toujours `parent::applyInput($entity, $input)` en premier** (cf.
[[pitfall_call_parent_apply_input]]). Sinon tu court-circuites le syncer
de translations et tu casses la réversibilité du single mode.

### 2. Tu crées une nouvelle entité multilingue côté client

Tu introduis ton propre `<Name>` + `<Name>Translation`. Pour respecter
le single-locale mode :

```php
// Manager client
use Aurora\Core\Locale\Service\TranslationLocaleSyncerInterface;

class CustomTagManager implements CustomTagManagerInterface
{
    public function __construct(
        // ...
        protected readonly TranslationLocaleSyncerInterface $translationSyncer,
    ) {}

    protected function applyInput(CustomTagInterface $tag, CustomTagInputInterface $input): void
    {
        // ... champs scalaires ...

        // Cleanup des translations obsolètes (préserve les locales inactives)
        foreach ($this->translationSyncer->stale($tag->getTranslations(), array_keys($input->getTranslations())) as $stale) {
            $tag->removeTranslation($stale);
        }

        // Upsert des translations actives
        foreach ($input->getTranslations() as $locale => $translationInput) {
            // ...
        }
    }
}
```

Côté ViewBuilder, passer `$this->localeContext->getActiveLocales()` (ou
`$this->localeOptionsProvider->getActiveOptions()` si tu veux le label DB)
à la prop Vue `locales`. **Ne jamais injecter `kernel.enabled_locales`**.

Côté Serializer : **READ = unfiltered**. Retourne toutes les
translations existantes en DB (sinon les locales dormantes deviennent
invisibles et tu casses la réversibilité).

Doc canonique : `docs/aurora-core/dev/single_locale_mode.md` dans
aurora-core.

### 3. Tu veux substituer le comportement de `LocaleContext` lui-même

Use case typique : déterminer la locale via subdomain (`en.example.com`)
au lieu de la session, ou ajouter un canal "locale du contrat client" qui
override la default locale pour certains visiteurs.

Pattern Sylius standard :

```php
use Aurora\Core\Locale\Service\LocaleContext;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: LocaleContextInterface::class)]
class CustomLocaleContext extends LocaleContext
{
    public function getDefaultLocale(): string
    {
        // ta logique custom (subdomain, contrat, etc.)
        // fallback raisonnable :
        return parent::getDefaultLocale();
    }
}
```

Tous les consumers d'aurora-core (Managers, ViewBuilders, Serializers,
`LocaleSubscriber`, `SingleLocaleRedirectSubscriber`) type-hint
`LocaleContextInterface` → ils reçoivent automatiquement ta version.

## Ajouter une 3e/4e locale → toujours dans aurora-core

`LocaleEnum` (`Aurora\Core\Locale\Enum\LocaleEnum`) est en dur dans
aurora-core (`French = 'fr'`, `English = 'en'`). Tu ne peux **pas**
ajouter une nouvelle locale (es, de…) depuis aurora-client.

**Et c'est volontaire - c'est la bonne approche.** Toujours ajouter une
locale **dans aurora-core** plutôt que de la bricoler côté client.
Pourquoi :

- L'enum drive **tout l'écosystème** : routes frontend, switcher public,
  sitemap, RSS, settings select, `LocaleSubscriber`,
  `SingleLocaleRedirectSubscriber`, `DumpJsTranslationsCommand`,
  `AuroraBundle::prependExtension()`. Ajouter `LocaleEnum::Spanish = 'es'`
  propage automatiquement la langue partout, sans rien d'autre.
- **Capitalisation cross-projet** : si ton projet a besoin d'espagnol,
  d'autres clients aurora en auront probablement besoin aussi. Ajouter
  au core (+ les fichiers `messages.es.yaml` de base) bénéficie à
  l'écosystème entier et évite que chaque projet recrée sa version.
- **Tests existants** : le suite couvre déjà l'enum
  (`LocaleEnum::values()` est utilisé dans les tests). Ajouter un case
  ne casse rien.
- Une "locale bricolée côté client" (qui contournerait l'enum) ferait
  diverger les patterns : tu te retrouverais avec des entités où certains
  Managers connaissent la 3e locale et d'autres non. C'est exactement ce
  que `LocaleContext` + `LocaleEnum` empêchent.

**Workflow recommandé pour ajouter une locale** :

1. Ouvre une PR sur aurora-core qui ajoute le case à `LocaleEnum`
   (`case Spanish = 'es';`) et la clé `shared.locales.es: Español` dans
   `src/Core/translations/messages.{fr,en}.yaml`.
2. Pour chaque module qui a des `messages.fr.yaml` / `messages.en.yaml`,
   ajouter le `messages.es.yaml` (peut être stubé avec les clés
   `en` au début, à traduire ensuite).
3. `make translation` pour regénérer `src/Core/assets/locales/generated/es.json`.
4. C'est tout. Routes, switcher, settings, sitemap, `LocaleSubscriber`
   prennent la nouvelle locale automatiquement.

## Tests

Si ton Manager client dépend de `TranslationLocaleSyncerInterface`,
instancie un vrai syncer avec un `LocaleContextInterface` mocké qui
retourne `LocaleEnum::values()` (= mode multi-langue) :

```php
$localeContext = $this->createMock(LocaleContextInterface::class);
$localeContext->method('getActiveLocales')->willReturn(LocaleEnum::values());
$syncer = new TranslationLocaleSyncer($localeContext);
```

Pour tester le comportement single mode : `willReturn([LocaleEnum::default()->value])`.
