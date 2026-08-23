# Pattern : étendre un Manager

## Règle

Override **uniquement** les hooks `protected` du Manager. Ne pas toucher
aux méthodes publiques `create()`, `update()`, `delete()` sauf nécessité
absolue.

## Pourquoi

Les hooks ont été conçus pour être les seuls points d'extension. Override
une méthode publique :
- Recopie tout le flow `persist + flush + audit` (fragile aux évolutions
  d'aurora-core).
- Risque de désynchroniser de la version Aurora si la méthode publique est
  améliorée upstream.

Override les hooks :
- Override **un seul** comportement précis (instanciation, hydratation,
  audit payload).
- Le flow Aurora reste centralisé et bénéficie des corrections de bugs
  upstream.

## Comment l'appliquer

### Squelette d'extension

```php
namespace App\Module\Platform\Agency\Manager;

use Aurora\Module\Platform\Agency\Manager\AgencyManager as BaseAgencyManager;
use Aurora\Module\Platform\Agency\Manager\AgencyManagerInterface;
use Aurora\Module\Platform\Agency\Dto\AgencyInputInterface;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use App\Module\Platform\Agency\Dto\AgencyInput as AppAgencyInput;
use App\Module\Platform\Agency\Entity\Agency as AppAgency;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencyManagerInterface::class)]
class AgencyManager extends BaseAgencyManager
{
    // Hook 1 : instanciation - retourne la classe client
    protected function createAgency(): AgencyInterface
    {
        return new AppAgency();
    }

    // Hook 2 : hydratation - toujours parent::applyInput() AVANT
    protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
    {
        parent::applyInput($agency, $input);

        if ($input instanceof AppAgencyInput && $agency instanceof AppAgency) {
            $agency->setCode($input->getCode());
        }
    }

    // Hook 3 : audit payload - splat-merge parent puis ajouter
    protected function auditPayload(AgencyInterface $agency): array
    {
        $payload = parent::auditPayload($agency);

        if ($agency instanceof AppAgency) {
            $payload['code'] = $agency->getCode();
        }

        return $payload;
    }
}
```

## Pièges classiques

### 1. Oublier de override `createX()`

Si `createAgency()` n'est pas override, le Manager Aurora fait `new
\Aurora\…\Agency()` - la classe Aurora, pas `App\Module\Platform\Agency\Entity\Agency`. Doctrine
persiste la classe Aurora et **les champs custom du client sont perdus
silencieusement**.

Cf [pitfall_create_hook_required.md](pitfall_create_hook_required.md).

### 2. Oublier `parent::applyInput()`

```php
// ❌ MAUVAIS
protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
{
    $agency->setCode($input->getCode());  // Manque setName(), setDescription(), etc.
}

// ✅ BON
protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
{
    parent::applyInput($agency, $input);  // Hydrate les champs Aurora
    $agency->setCode($input->getCode());  // Ajoute les champs custom
}
```

Cf [pitfall_call_parent_apply_input.md](pitfall_call_parent_apply_input.md).

### 3. `instanceof` checks pour les types étendus

`applyInput()` reçoit `AgencyInterface` + `AgencyInputInterface`. Pour
accéder aux méthodes du DTO étendu (`$input->getCode()`), faire
`instanceof AppAgencyInput` (l'alias du `use` dans le même module). Sinon PHPStan / l'analyseur statique
râle (pas de `getCode()` sur l'interface Aurora).

## Variante User-style

Pour les Managers à hooks multiples (User, Order, Invoice, Tiers, OcrJob,
Comment), il **n'y a pas** de `applyInput()`. À la place : override les
méthodes publiques métier qu'on veut customiser, en appelant
`parent::xxx()` :

```php
class UserManager extends BaseUserManager
{
    protected function createUser(): UserInterface { return new AppUser(); }

    public function changePassword(UserInterface $user, string $newPassword): void
    {
        parent::changePassword($user, $newPassword);
        // … logique custom client (ex: notifier équipe sécurité)
    }
}
```
