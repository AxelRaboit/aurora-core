---
name: Structure en module - tout le code client va dans src/Module/
description: Pas de dossiers plats src/Entity/, src/Dto/, etc. - même les extensions Aurora vont dans un module miroir
type: feedback
---

## Règle

Tout le code client vit sous `src/Module/`, y compris les extensions
d'entités Aurora. Il n'y a **pas** de dossiers plats `src/Entity/`,
`src/Dto/`, `src/Manager/`, `src/Serializer/`.

Le chemin du module **miroir** le namespace Aurora de l'entité étendue :

| Namespace Aurora (depuis 0.4.0) | Chemin client |
|---|---|
| `Aurora\Module\Platform\Agency\…` | `src/Module/Platform/Agency/…` |
| `Aurora\Module\Platform\User\…` | `src/Module/Core/Platform/User/…` |
| `Aurora\Module\Configuration\Setting\…` | `src/Module/Core/Configuration/Setting/…` |
| `Aurora\Module\Media\Library\…` | `src/Module/Core/Media/Library/…` |
| `Aurora\Module\Crm\Deal\…` | `src/Module/Crm/Deal/…` |

> **Note 0.4.0** : les entités Core ont été nichées sous leur module
> parent (Platform, Configuration, Media, General, Dev) - cf.
> [`docs/aurora-client/MIGRATION_0.4.md`](../../docs/aurora-client/MIGRATION_0.4.md)
> et [[decision-core-submodule-nesting]] côté aurora-core.

Pour un module entièrement nouveau (sans entité Aurora à étendre) :
`src/Module/<NomModule>/` avec la même arborescence qu'aurora-core
(Entity, Dto, Manager, Serializer, Controller, View, translations…).

## Pourquoi

Éviter les fourre-tout sans appartenance (`src/Entity/AgencyManager.php`
côte à côte avec `src/Entity/DealManager.php`). La structure miroir
documente l'appartenance et reste cohérente avec aurora-core.

## Comment l'appliquer

`config/packages/doctrine.yaml` : un seul mapping couvre tout :

```yaml
doctrine:
    orm:
        mappings:
            AuroraClient:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Module'
                prefix: 'App\Module'
                alias: AuroraClient
```

`config/services.yaml` : une seule entrée PSR-4 :

```yaml
App\Module\:
    resource: '../src/Module/'
```

`resolve_target_entities` dans `doctrine.yaml` (pas dans `AuroraBundle.php`) :

```yaml
doctrine:
    orm:
        resolve_target_entities:
            # Depuis 0.4.0 : Agency vit sous Aurora\Module\Platform\Agency
            Aurora\Module\Platform\Agency\Entity\AgencyInterface: App\Module\Platform\Agency\Entity\Agency
```
