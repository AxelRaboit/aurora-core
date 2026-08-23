---
name: FrontendDescriptor - convention de nommage et placement des descripteurs de front
description: Tout module avec un toggle *Frontend dans ModuleParameterEnum doit avoir un <Module>FrontendDescriptor.php à sa racine
type: feedback
---

## Règle

Tout module qui expose une partie publique (i.e. possède un toggle
`*Frontend` dans `ModuleParameterEnum`) doit avoir un fichier
`<Module>FrontendDescriptor.php` **à la racine du module**
(`src/Module/<Module>/`, à côté de `<Module>Module.php`), implémentant
`Aurora\Core\Frontend\Contract\FrontendInterface`.

Le descripteur déclare :

- `getSlug()` - identifiant lowercase du front
- `getLabel()` - libellé d'affichage
- `getHomeRoute()` - route nommée de la home
- `getPriority()` - ordre de fallback du `Registry`
- `getModuleSettingKey()` - clé `ModuleParameterEnum::<Module>Frontend->value`
- `getRoutePrefixes()` - liste de préfixes de noms de routes pour le
  `FrontendRouteGateSubscriber`

**Menu locations** : `MenuLocationProviderInterface` n'est implémenté QUE
par `EditorialFrontendDescriptor` (il possède les locations globales
`primary` / `footer` / `account` du site public principal). Les autres
modules N'IMPLÉMENTENT PAS cette interface.

Classes `final` - ce sont des descripteurs de configuration, pas des
points d'extension structurels.

## Pourquoi

1. **Symétrie cross-module** : avant la convention, seul Editorial avait
   un descripteur (`EditorialFrontend.php`). Ecommerce, Photo et Ged
   exposaient des routes publiques sans descripteur → le
   `FrontendRouteGateSubscriber` ne pouvait pas 404 leurs routes quand
   leur toggle était off. Désormais le comportement est cohérent partout.
2. **Suffixe `FrontendDescriptor`** clarifie le rôle : ce n'est pas un
   controller, ni un template, ni un dossier "Frontend/" - c'est un
   descripteur. Le mot "Frontend" était ambigu (cf. dossiers
   `Controller/Frontend/`, `Vue/Frontend/`, etc.).
3. **Auto-discovery** : l'autoconfigure `_instanceof` dans
   `config/services.yaml` tague tout `FrontendInterface` avec
   `aurora.front`, donc un descripteur à la racine du module est
   immédiatement enregistré sans config supplémentaire.

## Comment l'appliquer

### Pour un nouveau module avec partie publique

1. Ajouter une case `<Module>Frontend` dans `ModuleParameterEnum` (cascade
   parent vers `<Module>Backend` si pertinent).
2. Créer `src/Module/<Module>/<Module>FrontendDescriptor.php` (calquer sur
   `EditorialFrontendDescriptor.php`).
3. Pour `getRoutePrefixes()` : lancer
   `php bin/console debug:router | grep frontend_<module>` et lister
   tous les préfixes distincts (attention aux préfixes qui chevauchent
   plusieurs modules - ex. `frontend_account_orders` appartient au
   panel ecommerce).
4. NE PAS implémenter `MenuLocationProviderInterface` (réservé à
   Editorial).
5. `php bin/console debug:container --tag aurora.front` pour confirmer
   l'enregistrement.

### Préfixes actuels (réf.)

- Editorial : `editorial_`
- Ecommerce : `frontend_shop_`, `frontend_cart`, `frontend_checkout`,
  `frontend_order_`, `frontend_account_orders`
- Photo : `frontend_gallery`
- Ged : `frontend_ged_`

### Piège - préfixes qui se chevauchent

Le préfixe `frontend_account_` ne peut PAS appartenir entièrement à
Ecommerce parce que `frontend_account` (la page compte utilisateur)
appartient au cœur frontend (non gated). On utilise donc le préfixe
spécifique `frontend_account_orders`. Toujours vérifier que les
préfixes d'un module ne capturent pas les routes d'un autre.

## Voir aussi

- [`pattern_frontend_toggle.md`](pattern_frontend_toggle.md) - toggle
  global ↔ `Registry` ↔ `RootDispatchController` cascade.
