# Pattern : ajouter des permissions custom côté client

## Règle

Pour ajouter des permissions aurora-client (ex: `crm.contacts.export`) au-delà
de celles déclarées dans aurora-core, créer un `ModuleInterface` dédié dans
le projet client. Grâce à `_instanceof`, il est auto-taggé `aurora.module` sans
ligne à ajouter dans `services.yaml`.

```php
// src/Module/ClientCrm/ClientCrmPermissionsModule.php
use Aurora\Core\Module\ModuleInterface;
use Aurora\Core\Module\NavPermission;
use Aurora\Core\Module\NavSection;

class ClientCrmPermissionsModule implements ModuleInterface
{
    public function getId(): string
    {
        // Même id que le module core → permissions groupées sous la même
        // section "CRM" dans le dashboard dev/permissions
        return 'crm';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('crm.contacts.export'),
            new NavPermission('crm.deals.archive'),
        ];
    }

    public function getNavSections(): array
    {
        // Pas de nav supplémentaire - uniquement des permissions
        return [];
    }
}
```

Utilisation dans un controller :
```php
#[IsGranted('crm.contacts.export')]
public function export(): Response { ... }
```

## Pourquoi getId() doit matcher le module core

`PermissionRegistry` regroupe les permissions par module id. Si `getId()` retourne
`'crm'`, les permissions apparaissent dans la section **CRM** du dashboard
`/dev/dashboard/permissions`. Si tu retournes un id différent (`'crm_client'`),
une section séparée est créée - acceptable, mais moins propre visuellement.

## Traduction obligatoire (seul pas manuel)

Ajouter la clé dans `translations/messages.fr.yaml` du projet client :

```yaml
backend:
  permissions:
    names:
      crm:
        contacts:
          export: Exporter les contacts
        deals:
          archive: Archiver les opportunités
```

Sans cette clé, le dashboard affiche la clé brute `crm.contacts.export`.

## Pourquoi ça marche sans services.yaml

`ModuleInterface` est dans `_instanceof` dans `config/services.yaml` d'aurora-core
(hérité par le client) :
```yaml
_instanceof:
    Aurora\Core\Module\ModuleInterface:
        tags: [aurora.module]
```

Le `ModulePermissionVoter` utilise `PermissionRegistry.has()` pour décider s'il
vote sur un attribut. Si la permission n'est pas déclarée dans un module, le
voter ignore l'attribut → accès refusé par défaut. D'où l'obligation de déclarer
toute permission custom dans un `ModuleInterface`.
