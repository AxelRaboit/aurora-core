---
name: Piège - `_instanceof` ne traverse pas les fichiers de config
description: Le `_instanceof` d'aurora-core ne tague pas les services du client. Le client doit déclarer son propre bloc dans `config/services.yaml`.
type: feedback
---

## Symptôme

Tu crées un module client (`App\Module\Tracking\TrackingModule` ou un
`App\Module\Tracking\TrackingFrontendDescriptor`). En théorie il devrait être
auto-découvert grâce à `_instanceof` côté aurora-core qui tague tout
`ModuleInterface` et tout `FrontendInterface`. Mais :

- `php bin/console debug:container --tag=aurora.module` ne montre **pas**
  ton `App\Module\Tracking\TrackingModule`
- Le module n'apparaît pas dans la sidemenu
- Le `Registry` des fronts ne route pas vers ton front custom

## Cause

`_instanceof` dans Symfony est **scopé au fichier YAML** où il est déclaré.
Il ne s'applique **qu'aux services déclarés dans le même fichier**. Il ne
traverse **pas** les frontières entre `config/services.yaml` du client et
celui d'aurora-core (qui vit dans `vendor/axelraboit/aurora/config/services.yaml`).

Conséquence : aurora-core auto-tague ses propres modules (`Aurora\Module\…`)
grâce à son `_instanceof`. Mais les services du client (`App\Module\…`)
échappent à cette autoconfig.

## Règle

Tout `config/services.yaml` côté client **doit** déclarer son propre bloc
`_instanceof` mirroring les marker interfaces qu'on utilise :

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    # `_instanceof` est scopé à ce fichier - sans ce bloc, les services
    # client implémentant ces interfaces ne sont jamais tagués.
    _instanceof:
        Aurora\Core\Module\Contract\ModuleInterface:
            tags: [aurora.module]
        Aurora\Core\Frontend\Contract\FrontendInterface:
            tags: [aurora.front]

    App\Module\:
        resource: '../src/Module/'
```

Avec ça, tout nouveau module client est auto-tagué dès qu'il implémente
l'interface - pas besoin de déclaration explicite par service.

## Quand ajouter une autre marker interface

À chaque fois que tu utilises une interface aurora-core taguée via
`_instanceof` côté core, dupliquer la ligne ici.

**Ne pas recopier une liste de mémoire : la mesurer.** Celle qui figurait ici
en comptait 5 dont une supprimée (`MediaUsageProviderInterface`), alors que le
core en expose 19 - un client qui s'y fiait perdait quinze points d'extension
sans rien voir. Pour l'état du jour :

```bash
sed -n '/_instanceof:/,/^    [A-Za-z]/p' vendor/axelraboit/aurora/config/services.yaml
```

Au 2026-09-16, les plus souvent utiles côté client :

- `Aurora\Core\Module\Contract\ModuleInterface` → `aurora.module`
- `Aurora\Core\Frontend\Contract\FrontendInterface` → `aurora.front`
- `Aurora\Module\Ged\Document\Contract\DocumentUsageProviderInterface` → `aurora.document_usage_provider`
  (**obligatoire** dès qu'une entité client FK'e un `Document` : sans lui,
  l'écran de suppression de la GED annonce « aucun usage » et la suppression
  casse en silence)
- `Aurora\Core\Storage\Access\UploadAccessGuardInterface` → `aurora.upload_access_guard`
  (**obligatoire** pour toute area de stockage privée : un préfixe non
  revendiqué est servi anonymement)
- `Aurora\Core\Storage\Orphan\ReferencedKeysProviderInterface` → `aurora.referenced_keys_provider`
  (sans lui, `aurora:storage:prune-orphans --force` voit les fichiers de
  l'area comme orphelins)
- `Aurora\Core\Sequence\SequencePrefixProviderInterface` → `aurora.sequence_prefix`
- `Aurora\Core\Trash\TrashSourceInterface` → `aurora.trash_source`
- `Aurora\Core\Search\SearchProviderInterface` / `BackendSearchProviderInterface`
- `Aurora\Core\Dashboard\DashboardStatsProviderInterface` → `aurora.dashboard_stats_provider`
- `Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface`
  et ses voisins `ConfigurationTabProviderInterface` / `OwnedSettingProviderInterface`

Les autres (`BootstrapProviderInterface`, `BlockRendererInterface`,
`EntityReferenceProviderInterface`, `RecurringMessageProviderInterface`,
`StorageAdapterInterface`, `MenuLocationProviderInterface`) existent aussi et
se déclarent de la même façon.

Source de vérité : `vendor/axelraboit/aurora/config/services.yaml`,
section `_instanceof`.

## Comment l'attraper

Si un service tagué côté core ne tague pas côté client, vérifier en
premier : le `_instanceof` du client couvre-t-il cette interface ?

```bash
php bin/console debug:container --tag=aurora.module | grep "App\\\\"
```

Si la grep est vide alors que le service existe → bloc `_instanceof`
incomplet.
