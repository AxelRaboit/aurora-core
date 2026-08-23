# Convention : privilèges granulaires - préférer view/create/edit/delete

## Règle

Toujours décomposer les permissions d'un module en actions atomiques plutôt
qu'en un `manage` fourre-tout :

```php
// ✅ Correct
return [
    new NavPermission('crm.contacts.view'),
    new NavPermission('crm.contacts.create'),
    new NavPermission('crm.contacts.edit'),
    new NavPermission('crm.contacts.delete'),
];

// ❌ À éviter
return [
    new NavPermission('crm.contacts.view'),
    new NavPermission('crm.contacts.manage'), // trop vague
];
```

## Pourquoi

Un utilisateur `ROLE_USER` reçoit des privilèges individuels depuis le dashboard
`/dev/dashboard/permissions`. Avec `manage`, impossible de donner accès à la
création sans donner la suppression. Avec `create`/`edit`/`delete` séparés, on
peut construire n'importe quel profil de droits granulaire.

## Correspondance Controller ↔ Permission

| Action HTTP | Méthode | Permission |
|---|---|---|
| `GET /list` | `index()` | `*.view` (via `#[IsGranted]` sur la classe) |
| `POST /` | `create()` | `*.create` |
| `POST /{id}/edit` | `update()` | `*.edit` |
| `POST /{id}/delete` | `delete()` | `*.delete` |

## Dans la Vue

Utiliser `can()` par action, jamais un `can('*.manage')` global :

```js
// ✅
const canCreate = computed(() => can('crm.contacts.create'));
const canEdit   = computed(() => can('crm.contacts.edit'));
const canDelete = computed(() => can('crm.contacts.delete'));

// Pour un bloc "peut faire quelque chose" (afficher le panneau d'édition)
const canManage = computed(() => canCreate.value || canEdit.value || canDelete.value);
```

## Traductions

Chaque permission doit avoir sa clé sous `backend.permissions.names.{module}.{entity}.{action}` :

```yaml
backend:
  permissions:
    names:
      crm:
        contacts:
          view: Voir les contacts
          create: Créer des contacts
          edit: Modifier les contacts
          delete: Supprimer les contacts
```

## Exception acceptable

Un `manage` peut rester pour des actions qui n'ont pas de distinction
create/edit/delete naturelle (ex : `planning.events.manage` pour un calendrier
où créer, modifier et supprimer un événement sont liés à la même UX). Dans ce
cas, documenter pourquoi dans un commentaire.
