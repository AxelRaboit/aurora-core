---
name: extend-aurora-entity
description: From an aurora-client project (Symfony app consuming axelraboit/aurora via composer), scaffold the 5-layer extension of an aurora-core entity - add a custom field to an Aurora entity end-to-end without forking. Use when the user asks to "extend", "override", "étendre", "ajouter un champ à" an Aurora entity (Agency, Post, Order, etc.), or to "add `<field>` to <AuroraEntity>". Generates the client-side files: concrete entity extending AbstractX, DTO + Factory extensions, Manager extension with parent::applyInput, Serializer extension with spread merge, and Vue wrapper consuming the Aurora component via extraFields.
scope: shared
---

# extend-aurora-entity

Scaffold the **client-side extension** of an Aurora entity, following the
5-layer Sylius pattern. This skill assumes the cwd is an aurora-client
project where `axelraboit/aurora` is installed as a composer dependency
under `vendor/axelraboit/aurora/`.

Canonical references the skill MUST read before generating:
- `vendor/axelraboit/aurora/.claude/memory/aurora-client/checklist_extend_full_entity.md`
- `vendor/axelraboit/aurora/.claude/memory/aurora-client/pattern_extend_*.md`
  (one per layer)
- `vendor/axelraboit/aurora/.claude/memory/aurora-client/pitfall_*.md`
  (the parent-call traps)

Do NOT invent - read these patterns and apply them literally.

## Required inputs

1. **Aurora entity name** (PascalCase) - `Agency`, `Post`, `Order`,
   `Invoice`, etc. The Aurora-side files must exist under
   `vendor/axelraboit/aurora/src/...` - verify by globbing
   `vendor/axelraboit/aurora/src/**/<Name>/Entity/<Name>.php`. If not
   found, stop and report.
2. **Custom field(s)** - name, PHP type, Doctrine column type, nullable,
   default, validation constraints (`#[Assert\*]`). Ask explicitly; do not
   invent fields.
3. **Module path mirror** - derive from the Aurora namespace. Aurora
   `Aurora\Module\Platform\Agency` → client `App\Module\Platform\Agency\`. Aurora
   `Aurora\Module\Editorial\Post` → client `App\Module\Editorial\Post\`.
   Confirm with the user if the project uses a different convention (some
   clients put extensions directly under `App\Entity\` - ask once).
4. **Variant detection** - read the Aurora Manager to detect:
   - User-style variant (no `applyInput`, multiple specialized hooks):
     User, Order, Invoice, Tiers, OcrJob, Comment. The skill must NOT
     generate an `applyInput` override; instead, list the public methods
     to override and ask which the user wants.
   - Editor full-page variant (Post): the Vue scaffold differs - wrap
     `PostEditor.vue` instead of a modal.

## What gets generated

For `<Name>` (e.g., `Agency`) with field `code`, namespace mirror
`App\Module\Platform\Agency`:

### Layer 1 - Concrete entity

```
src/Module/Platform/Agency/Entity/Agency.php
```

```php
namespace App\Module\Platform\Agency\Entity;

use Aurora\Module\Platform\Agency\Entity\AbstractAgency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use App\Module\Platform\Agency\Repository\AgencyRepository; // only if a custom repo
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: 'app_agencies')] // client prefix
class Agency extends AbstractAgency implements AgencyInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_agency_id', allocationSize: 1)] // client prefix
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $code = null;

    public function getId(): ?int { return $this->id; }
    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): static { $this->code = $code; return $this; }
}
```

Rules:
- Table prefix: `app_<plural>` (client namespace), NOT `core_*`.
- Sequence prefix: `seq_app_<entity>_id`, NOT `seq_core_*`.
- `repositoryClass` only if Layer 1bis applies (custom finders).

### Layer 1bis - resolve_target_entities

Edit `config/packages/doctrine.yaml`:

```yaml
doctrine:
    orm:
        resolve_target_entities:
            Aurora\Module\Platform\Agency\Entity\AgencyInterface: App\Module\Platform\Agency\Entity\Agency
```

Read the file first to find the existing block; append, don't replace.

### Layer 1ter - Repository (optional, only if custom finders requested)

**Default behaviour** : you do **NOT** need to create a client repository.
Aurora's own `AgencyRepository` extends `ResolveTargetEntityRepository`, so once
`resolve_target_entities` routes the interface to your client class, all queries
go to your `app_agencies` table automatically.

**Only create a custom client repo when the client needs custom finder methods.**
In that case :

```
src/Module/Platform/Agency/Repository/AgencyRepository.php
```

```php
namespace App\Module\Platform\Agency\Repository;

use App\Module\Platform\Agency\Entity\Agency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Repository\AgencyRepository as AuroraAgencyRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgencyRepository extends AuroraAgencyRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class, AgencyInterface::class);
    }

    public function findByCode(string $code): ?AgencyInterface { /* ... */ }
}
```

Then point the entity's `repositoryClass` to **your** repo :
`#[ORM\Entity(repositoryClass: \App\Module\Platform\Agency\Repository\AgencyRepository::class)]`.

No interface to create (Aurora doesn't expose `AgencyRepositoryInterface` -
limite assumée).

### Layer 2 - DTO + Factory extension

```
src/Module/Platform/Agency/Dto/AgencyInput.php
src/Module/Platform/Agency/Dto/AgencyInputFactory.php
```

```php
// AgencyInput.php
namespace App\Module\Platform\Agency\Dto;

use Aurora\Module\Platform\Agency\Dto\AgencyInput as AuroraAgencyInput;
use Symfony\Component\Validator\Constraints as Assert;

class AgencyInput extends AuroraAgencyInput
{
    public function __construct(
        string $name,
        #[Assert\Length(max: 50)]
        public readonly ?string $code = null,
    ) {
        parent::__construct(name: $name);
    }

    public function getCode(): ?string { return $this->code; }
}
```

```php
// AgencyInputFactory.php
namespace App\Module\Platform\Agency\Dto;

use Aurora\Module\Platform\Agency\Dto\AgencyInputFactory as AuroraAgencyInputFactory;
use Aurora\Module\Platform\Agency\Dto\AgencyInputFactoryInterface;
use Aurora\Module\Platform\Agency\Dto\AgencyInputInterface;
use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencyInputFactoryInterface::class)] // override the Aurora factory
class AgencyInputFactory extends AuroraAgencyInputFactory
{
    public function fromArray(array $data): AgencyInputInterface
    {
        return new AgencyInput(
            name: Str::trimFromArray($data, 'name'),
            code: Str::trimFromArray($data, 'code') ?: null,
        );
    }
}
```

### Layer 3 - Manager extension

```
src/Module/Platform/Agency/Manager/AgencyManager.php
```

```php
namespace App\Module\Platform\Agency\Manager;

use App\Module\Platform\Agency\Entity\Agency;
use Aurora\Module\Platform\Agency\Dto\AgencyInputInterface;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Manager\AgencyManager as AuroraAgencyManager;
use Aurora\Module\Platform\Agency\Manager\AgencyManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencyManagerInterface::class)]
class AgencyManager extends AuroraAgencyManager
{
    // CRITICAL - without this, AbstractAgency is instantiated and the `code` column never gets saved.
    protected function createAgency(): AgencyInterface
    {
        return new Agency();
    }

    protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
    {
        parent::applyInput($agency, $input); // CRITICAL - call parent FIRST so Aurora fields are hydrated.

        if ($agency instanceof Agency && $input instanceof \App\Module\Platform\Agency\Dto\AgencyInput) {
            $agency->setCode($input->getCode());
        }
    }

    protected function auditPayload(AgencyInterface $agency): array
    {
        $payload = parent::auditPayload($agency);
        if ($agency instanceof Agency) {
            $payload['code'] = $agency->getCode();
        }
        return $payload;
    }
}
```

**User-style variant** (User, Order, Invoice, Tiers, OcrJob, Comment): do
NOT generate `applyInput`. Instead, list the Aurora Manager's public methods
to the user and ask which ones to override. Each override starts with
`parent::xxx()`.

> ⚠️ **Even for the User-style variant, the other hard rules still apply** :
> - `protected function createX(): XInterface` is **mandatory** for every
>   class the Manager instantiates (else Doctrine persists the parent class
>   and the client column is silently lost - cf. `pitfall_create_hook_required.md`)
> - `auditCreated`, `auditUpdated`, `auditDeleted` and `auditPayload` are
>   **mandatory** if the Manager uses `AuditLogger`
>
> Only `applyInput()` is omitted in the User-style variant. Cf. hard rules
> in `.claude/memory/aurora-core/architecture/decision_4_hard_rules.md`.

### Layer 4 - Serializer extension

```
src/Module/Platform/Agency/Serializer/AgencySerializer.php
```

```php
namespace App\Module\Platform\Agency\Serializer;

use App\Module\Platform\Agency\Entity\Agency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializer as AuroraAgencySerializer;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencySerializerInterface::class)]
class AgencySerializer extends AuroraAgencySerializer
{
    public function serialize(AgencyInterface $agency): array
    {
        return [
            ...parent::serialize($agency),
            'code' => $agency instanceof Agency ? $agency->getCode() : null,
        ];
    }
}
```

### Layer 5 - Vue wrapper

```
src/Module/Platform/Agency/assets/backend/AppAgenciesApp.vue
```

> Convention 0.5+ : assets are **co-located** under
> `src/Module/<Module>/assets/backend/` next to the PHP classes of the
> module. The old root `assets/client/Module/<...>` layout was dropped
> in aurora-client commit `9d77f67`. Vite picks the new path up via
> the alias chain configured in `aliases.js` + `jsconfig.json`.

```vue
<script setup>
import AgenciesApp from "@platform/backend/agencies/AgenciesApp.vue";

const extraFields = {
    code: {
        default: "",
        fromEntity: (agency) => agency.code ?? "",
    },
};
</script>

<template>
    <AgenciesApp :extra-fields="extraFields">
        <template #extra-headers>
            <th>{{ $t("backend.agencies.code") }}</th>
        </template>
        <template #extra-cells="{ agency }">
            <td>{{ agency.code }}</td>
        </template>
        <template #extra-form-fields="{ editForm, errors }">
            <label>{{ $t("backend.agencies.code") }}</label>
            <input v-model="editForm.code" type="text" />
            <span v-if="errors.code" class="error">{{ errors.code }}</span>
        </template>
    </AgenciesApp>
</template>
```

Note the alias chain - clients import via the per-module shorthand
(`@<module>/...`) configured in `aliases.js` and resolved by Vite. The
`@<module>/...` entries point at
`vendor/axelraboit/aurora/src/Module/<Module>/assets/` since 0.5 (assets
moved under each module's PHP folder; the legacy root `assets/` is gone).

For the Post editor full-page variant: wrap `PostEditor.vue` and place the
`extra-form-fields` slot near a semantically related panel (cf. convention
§4.bis.2).

### Twig override (optional)

If the controller mounts the Aurora Vue component by name and the client
needs to swap in its wrapper, mirror the Aurora template path under the
client's source tree. Aurora's per-module Twig namespaces
(`@Platform`, `@Crm`, `@Editorial`, …) prepend the client's path first,
so the override is picked up automatically.

Example - Agency lives under the Platform module in aurora-core
(`src/Module/Platform/templates/backend/agencies/index.html.twig`).
The client mirror:

```
src/Module/Platform/templates/backend/agencies/index.html.twig
```

Point the Vue mount to `AppAgenciesApp` instead of `AgenciesApp`.

## Procedure

1. **Verify cwd is a client project**: `[ -d vendor/axelraboit/aurora ]`.
   If not, stop and explain - this skill is for aurora-client projects.
2. **Verify the Aurora entity exists**: glob
   `vendor/axelraboit/aurora/src/**/<Name>/Entity/<Name>.php`. If not
   found, stop and list available entities.
3. **Read the Aurora Manager** to detect the variant (User-style? Editor
   full-page?). Adjust scaffold accordingly.
4. **Read the convention pattern files** in
   `vendor/axelraboit/aurora/.claude/memory/aurora-client/`.
5. **Ask for inputs** (field name, type, validation) via `AskUserQuestion`.
6. **Generate all files in parallel** with Write.
7. **Edit `config/packages/doctrine.yaml`** to add the
   `resolve_target_entities` line.
8. **Final report**: list every file, the doctrine.yaml edit, and the
   manual follow-ups:
   - `php bin/console doctrine:migrations:diff` then review the SQL.
   - `php bin/console cache:clear`.
   - `php bin/console debug:container Aurora.<Name>ManagerInterface` -
     verify the alias resolves to the client class.
   - Add translation keys (`backend.<plural>.<field>`).
   - Test create + edit in the admin and confirm the new field persists.

## Boundaries

- **Client project only** - stop if `vendor/axelraboit/aurora/` is missing.
- **Never edit files under `vendor/axelraboit/aurora/`** - that's the
  bundle, read-only from a client's perspective.
- **Never invent fields** - ask explicitly. The whole point of the skill
  is to apply the user's chosen field across the 5 layers; the field set
  must come from the user.
- **One Aurora entity per invocation** - sequential, to keep doctrine.yaml
  edits clean.
- **Never run migrations** - only generate code and report.
- **Respect the parent-call pitfalls** - `parent::__construct` in the DTO,
  `parent::applyInput` FIRST in the Manager, `[...parent::serialize(...)]`
  in the Serializer. These are documented pitfalls - propagate them
  faithfully.
- **Sub-entity / cascade child** (e.g., `OrderLine` managed by
  `OrderManager`): skip Layers 2/3/4/5 - just generate the concrete entity
  + override `create<Child>()` in the user's existing extended parent
  Manager. Ask the user which parent Manager to edit.
