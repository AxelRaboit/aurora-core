# Pattern : domain events pour la synchronisation inter-modules

## Règle

Quand le module B doit réagir à une opération du module A (ou du Core) :
- **Core/Module A dispatche un event** Symfony.
- **Module B écoute** via un `EventListener` ou `EventSubscriber`.
- **Jamais** d'import `Core → Module` ou `Module A → Module B`.

## Pourquoi

Les modules doivent être optionnels et indépendants. Si Core importait `Hr\Employee`, retirer
le module Hr casse le Core. Avec les events, Core ne sait même pas qu'Hr existe.

## Pattern concret : `UserAgencyServiceUpdatingEvent`

```
Core\User\Manager\UserManager::updateAgencyAndService()
  → dispatche UserAgencyServiceUpdatingEvent (event mutable)

Module\Hr\Employee\EventListener\HrEmployeeSyncListener
  → intercepte l'event
  → si l'utilisateur a un Employee lié, force agency/service sur l'event
  → UserManager applique les valeurs de l'event (pas les valeurs brutes du DTO)
```

**L'event est mutable** : il expose des setters que les listeners peuvent appeler pour
override les valeurs avant qu'elles soient appliquées par le Manager. C'est le pattern
"intercepting filter" adapté aux events Symfony.

## Comment l'appliquer

1. **Identifier l'opération Core** à intercepter (ex : changement d'agence/service d'un User).
2. **Créer un event mutable** dans Core (dossier `Core\<Domain>\Event\`) avec :
   - les valeurs actuelles en propriétés lisibles
   - des setters pour permettre l'override
3. **Dispatcher l'event** dans le Manager Core avant d'appliquer les valeurs.
4. **Créer un listener** dans le module concerné (`Module\<Name>\<Domain>\EventListener\`),
   tagué `#[AsEventListener]`.
5. Le Manager Core **lit les valeurs depuis l'event** (pas depuis le DTO directement) après dispatch.

## Règle complémentaire

Core ne doit jamais `use App\Module\...` - vérifier avec :
```bash
grep -r "use App\\\\Module" src/Core/
# doit retourner 0 ligne
```
