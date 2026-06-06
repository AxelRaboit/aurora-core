# Étendre Agency de bout en bout (pilote)

Ce guide déroule, sur un **exemple générique**, le **câblage complet** à mettre
en place côté aurora-client pour ajouter un champ `code` à l'entité `Agency`
d'Aurora Core, **avec persistance, validation, sérialisation, affichage dans le
tableau backoffice et saisie dans le formulaire de création/édition** — sans
toucher à `vendor/aurora/`. L'extension `Agency` n'est qu'un support pédagogique :
elle **n'est pas forcément présente** dans ton projet ; les chemins
`src/Module/Platform/Agency/…` indiquent *où* écrire chaque couche.

C'est le pilote du pattern d'extensibilité (Sylius-style), transposable à
n'importe quelle entité Aurora.

---

## Structure : chemin miroir du namespace Aurora

Toute extension d'une entité Aurora vit dans `src/Module/` en **miroir** du
namespace Aurora source :

```
Aurora\Module\Platform\Agency\…  →  src/Module/Platform/Agency/…
```

---

## Vue d'ensemble — 5 couches à câbler

| Couche | Fichier(s) côté client | Mécanisme |
|---|---|---|
| Entité Doctrine | `src/Module/Platform/Agency/Entity/Agency.php` | `extends AbstractAgency`, table dédiée |
| DTO d'entrée | `src/Module/Platform/Agency/Dto/AgencyInput.php` + `AgencyInputFactory.php` | `extends`, `#[AsAlias]` |
| Manager | `src/Module/Platform/Agency/Manager/AgencyManager.php` | `extends`, `#[AsAlias]` |
| Serializer | `src/Module/Platform/Agency/Serializer/AgencySerializer.php` | `extends`, `#[AsAlias]` |
| Vue | `src/Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue` (co-localisé avec l'extension PHP — shadow auto via clientModules glob) | slots scoped, pas de Twig override |

---

## 1. Entité — `App\Module\Platform\Agency\Entity\Agency`

**Important** : on étend `AbstractAgency` (le `MappedSuperclass`), **pas** la
classe concrète `Aurora\Module\Platform\Agency\Entity\Agency`. Étendre la classe concrète
exigerait de déclarer un `#[ORM\InheritanceType]` et un discriminator column,
ce qui impose Single ou Joined Table Inheritance — pas ce qu'on veut. Le
pattern Sylius : chaque app a sa propre table.

```php
// aurora-client : src/Module/Platform/Agency/Entity/Agency.php
namespace App\Module\Platform\Agency\Entity;

use Aurora\Module\Platform\Agency\Entity\AbstractAgency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Repository\AgencyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: 'app_agencies')]
class Agency extends AbstractAgency implements AgencyInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_agency_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $code = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }
}
```

### 1.1 Doctrine mapping + ResolveTargetEntity

`config/packages/doctrine.yaml` — un seul mapping couvre tout `src/Module/` :

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
    orm:
        resolve_target_entities:
            Aurora\Module\Platform\Agency\Entity\AgencyInterface: App\Module\Platform\Agency\Entity\Agency
        mappings:
            AuroraClient:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Module'
                prefix: 'App\Module'
                alias: AuroraClient
```

> **Pourquoi `repositoryClass: AgencyRepository::class` côté client marche
> transparent** : Aurora's repositories étendent
> `Aurora\Core\Repository\ResolveTargetEntityRepository`, qui résout l'entité
> via `getClassMetadata(<Interface>::class)` à la construction. Donc une
> seule instance de repo, mais elle querie automatiquement votre table
> `app_agencies` dès que `resolve_target_entities` route l'interface vers
> votre classe. Pas besoin de redéclarer un repository côté client (sauf si
> vous voulez ajouter vos propres méthodes — auquel cas étendez
> `Aurora\Module\Platform\Agency\Repository\AgencyRepository` et déclarez-le dans
> `config/packages/doctrine.yaml`).

À partir de cette config, **toutes** les associations Aurora qui type-hint
`AgencyInterface` (ex: `User::$agency`) résolvent automatiquement vers votre
`App\Module\Platform\Agency\Entity\Agency`.

### 1.2 Migration — copie des données + bascule des FK

`doctrine:migrations:diff --namespace=ClientMigrations` génère une migration
brute. Elle contient des lignes parasites (ex: `DROP SEQUENCE seq_log` qui
est gérée runtime par `SequenceGenerator`, ou `DROP TABLE messenger_messages`)
qu'il faut **enlever** à la main. Voici la migration nettoyée typique :

```php
// migrations/Version20260508123924.php (nom auto-généré)
namespace ClientMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508123924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_agencies table extending Aurora Core Agency with code field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_app_agency_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE app_agencies (id INT NOT NULL, name VARCHAR(150) NOT NULL, code VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');

        // Copy existing rows from core_agencies so user FKs stay valid after the switch.
        $this->addSql('INSERT INTO app_agencies (id, name, created_at, updated_at) SELECT id, name, created_at, updated_at FROM core_agencies');
        $this->addSql("SELECT setval('seq_app_agency_id', GREATEST((SELECT COALESCE(MAX(id), 0) FROM app_agencies), 1))");

        // Repoint the User → Agency FK to app_agencies.
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT fk_42028409cdeadb2a');
        $this->addSql('ALTER TABLE core_users ADD CONSTRAINT FK_42028409CDEADB2A FOREIGN KEY (agency_id) REFERENCES app_agencies (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT FK_42028409CDEADB2A');
        $this->addSql('ALTER TABLE core_users ADD CONSTRAINT fk_42028409cdeadb2a FOREIGN KEY (agency_id) REFERENCES core_agencies (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP TABLE app_agencies');
        $this->addSql('DROP SEQUENCE seq_app_agency_id CASCADE');
    }
}
```

Nuance importante : **chaque entité Aurora qui pointait `AgencyInterface`**
(ici juste `User::$agency`, mais une autre fois ce serait Photo's
`Gallery::$clientContact`, etc.) génère une `ALTER TABLE … FK` à inclure dans
la migration. Le diff Doctrine les trouve toutes.

```bash
php bin/console doctrine:migrations:diff --namespace=ClientMigrations
# Nettoyer le fichier généré (lignes seq_log, seq_prj, messenger_messages, etc.)
# Ajouter le INSERT INTO app_agencies … SELECT … FROM core_agencies
php bin/console doctrine:migrations:migrate
```

---

## 2. DTO d'entrée + Factory

### 2.1 DTO — `App\Module\Platform\Agency\Dto\AgencyInput`

```php
// aurora-client : src/Module/Platform/Agency/Dto/AgencyInput.php
namespace App\Module\Platform\Agency\Dto;

use Aurora\Module\Platform\Agency\Dto\AgencyInput as AuroraAgencyInput;
use Symfony\Component\Validator\Constraints as Assert;

class AgencyInput extends AuroraAgencyInput
{
    public function __construct(
        string $name,
        #[Assert\Length(max: 50, maxMessage: 'Le code dépasse 50 caractères.')]
        public readonly ?string $code = null,
    ) {
        parent::__construct($name);
    }
}
```

Symfony Validator inspecte les attributs du DTO étendu via réflexion — le
`Assert\Length` est appliqué automatiquement, pas besoin de re-déclarer le
`Assert\NotBlank` du parent.

### 2.2 Factory — `App\Module\Platform\Agency\Dto\AgencyInputFactory`

Le controller `AgenciesController` n'instancie plus directement
`AgencyInput::fromArray()` — il injecte un `AgencyInputFactoryInterface`.
On remplace l'alias d'Aurora par le nôtre :

```php
// aurora-client : src/Module/Platform/Agency/Dto/AgencyInputFactory.php
namespace App\Module\Platform\Agency\Dto;

use Aurora\Module\Platform\Agency\Dto\AgencyInputFactoryInterface;
use Aurora\Module\Platform\Agency\Dto\AgencyInputInterface;
use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencyInputFactoryInterface::class)]
class AgencyInputFactory implements AgencyInputFactoryInterface
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

`#[AsAlias(AgencyInputFactoryInterface::class)]` écrase l'alias d'Aurora-core
sur ce même service-id : à la compilation du conteneur, `App\Module\Platform\Agency\Dto\AgencyInputFactory`
gagne, le controller reçoit votre factory.

### 2.3 Enregistrement — `config/services.yaml`

Le mapping `App\Module\:` couvre automatiquement tous les fichiers sous
`src/Module/`, y compris la factory. Rien à ajouter manuellement.

---

## 3. Manager — `App\Module\Platform\Agency\Manager\AgencyManager`

`resolve_target_entities` n'agit que sur la résolution Doctrine (associations,
queries) — un `new Agency()` PHP littéral instancie toujours la classe importée.
Aurora's `AgencyManager` expose donc deux hooks `protected` :

- `createAgency(): AgencyInterface` — instancie la nouvelle entité
- `applyInput(AgencyInterface $agency, AgencyInputInterface $input): void` — la peuple

Vous override l'un ou l'autre (ou les deux) ; `parent::create()` et
`parent::update()` continuent de gérer persist + audit log.

```php
// aurora-client : src/Module/Platform/Agency/Manager/AgencyManager.php
namespace App\Module\Platform\Agency\Manager;

use App\Module\Platform\Agency\Dto\AgencyInput;
use App\Module\Platform\Agency\Entity\Agency;
use Aurora\Module\Platform\Agency\Dto\AgencyInputInterface;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Manager\AgencyManager as AuroraAgencyManager;
use Aurora\Module\Platform\Agency\Manager\AgencyManagerInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencyManagerInterface::class)]
class AgencyManager extends AuroraAgencyManager
{
    #[Override]
    protected function createAgency(): AgencyInterface
    {
        return new Agency();
    }

    #[Override]
    protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
    {
        parent::applyInput($agency, $input);

        if ($input instanceof AgencyInput && $agency instanceof Agency) {
            $agency->setCode($input->code);
        }
    }
}
```

`#[AsAlias(AgencyManagerInterface::class)]` remplace l'alias d'Aurora ;
le controller injecte votre Manager, qui instancie `App\Module\Platform\Agency\Entity\Agency`
(via `createAgency`) et persiste dans `app_agencies` avec le bon `code`.

---

## 4. Serializer — `App\Module\Platform\Agency\Serializer\AgencySerializer`

```php
// aurora-client : src/Module/Platform/Agency/Serializer/AgencySerializer.php
namespace App\Module\Platform\Agency\Serializer;

use App\Module\Platform\Agency\Entity\Agency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializer as AuroraAgencySerializer;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializerInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencySerializerInterface::class)]
class AgencySerializer extends AuroraAgencySerializer
{
    #[Override]
    public function serialize(AgencyInterface $agency): array
    {
        $data = parent::serialize($agency);

        if ($agency instanceof Agency) {
            $data['code'] = $agency->getCode();
        }

        return $data;
    }
}
```

À ce stade, le payload JSON renvoyé par `/backend/platform/agencies` contient `code`.

---

## 5. Vue — wrapper avec slots scoped

### 5.1 Composant client — chemin et alias

Aurora expose **deux** globs côté Vue (cf. `vendor/aurora/src/Core/assets/app.js`) :

- `@client/src/Module/**/assets/**/*.vue` — composants des modules client
  (vraies features comme Tracking, OU overrides co-localisés avec une
  extension PHP comme Platform/Agency). Les feature folders entre
  `Module/<Name>/` et `assets/` sont flatten dans la clé exposée. Exposés
  comme `<name>/<rest>` (ex: `tracking/backend/dashboard/...` ou
  `platform/backend/agencies/AgenciesApp` quand on shadow Aurora)
- `@client/src/Overrides/**/*.vue` — escape hatch pour shadow des
  composants non-module (e.g. `src/Core/assets/...` d'aurora-core).
  Rare ; préférer la co-localisation sous `Module/<X>/<Feature>/assets/`
  quand on shadow un composant qui vit dans un module Aurora.

Le wrapper Agency vit avec l'extension PHP — co-localisation sous
`src/Module/Platform/Agency/assets/`.

```vue
<!-- aurora-client : src/Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue -->
<script setup>
import AuroraAgenciesApp from "@core/backend/agencies/AgenciesApp.vue";
import AppInput from "@/shared/components/form/AppInput.vue";

defineProps({
    agencies: { type: Array, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

// Dit au composable Aurora useAgenciesForm comment hydrater editForm.code
// (reset à '' en création, lecture depuis agency.code en édition).
const extraFields = {
    code: {
        default: "",
        fromEntity: (agency) => agency.code ?? "",
    },
};
</script>

<template>
    <AuroraAgenciesApp
        :agencies="agencies"
        :create-path="createPath"
        :update-path="updatePath"
        :delete-path="deletePath"
        :extra-fields="extraFields"
    >
        <template #extra-headers>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">Code</th>
        </template>
        <template #extra-cells="{ agency }">
            <td class="px-4 py-3 text-muted">{{ agency.code ?? '—' }}</td>
        </template>
        <template #extra-form-fields="{ editForm, errors }">
            <AppInput
                v-model="editForm.code"
                label="Code"
                placeholder="ex: PARIS-01"
                :error="errors.code ?? ''"
            />
        </template>
    </AuroraAgenciesApp>
</template>
```

Aliases utilisés (déclarés dans `vendor/aurora/vite.config.js`) :

| Alias | Pointe vers |
|---|---|
| `@core` | `vendor/aurora/src/Core/assets` |
| `@` | `vendor/aurora/assets` (composants `shared/`, etc.) |
| `@client` | `aurora-client/assets/client` (votre dossier) |

Le composable Aurora `useAgenciesForm(extraFields)` :
- au reset (création) : `editForm.code = ''`
- à l'ouverture en édition : `editForm.code = agency.code`
- à la soumission : `request(url, { ...editForm })` envoie `name` ET `code`

### 5.2 Aucun override Twig nécessaire

Depuis la co-localisation, **pas besoin d'override Twig**. Le wrapper
client à `src/Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue`
est exposé par le glob `clientModules` sous **la même clé** que le composant
Aurora (`platform/backend/agencies/AgenciesApp`). Comme `clientModules` est
spread après `auroraModules` dans `vueContext`, ton fichier wins
automatiquement — Aurora rend `vue_component('platform/backend/agencies/AgenciesApp', ...)`
et c'est ton wrapper qui prend.

Vérifier que l'override est bien pris :

```bash
# Build + recharger la page admin /backend/platform/agencies. Inspecter le DOM :
# le composant Vue monté devrait avoir tes slots `extra-headers` /
# `extra-cells` / `extra-form-fields`.
npm run build
```

---

## 6. Tester

```bash
make demo                  # recharge fixtures + sync menus/privileges
make start                 # PHP server + Vite dev server
# → ouvrir /backend/platform/agencies, créer une agence avec un code, recharger, éditer
```

---

## Récap des points d'extension exposés par Aurora pour Agency

| Couche | Interface / point d'extension côté Aurora | Pattern client |
|---|---|---|
| Entité | `AgencyInterface` + `AbstractAgency` | `extends AbstractAgency`, table dédiée |
| ResolveTargetEntity | mapping interface → concrete dans `doctrine.yaml` | `App\Module\Platform\Agency\Entity\Agency` |
| DTO d'entrée | `AgencyInputInterface` | `extends AgencyInput` |
| Factory de DTO | `AgencyInputFactoryInterface` | `#[AsAlias]` + nouvelle factory |
| Manager | `AgencyManagerInterface` + hooks `protected` (`createAgency()`, `applyInput()`) | `#[AsAlias]` + override des hooks |
| Serializer | `AgencySerializerInterface` | `#[AsAlias]` + `extends AgencySerializer` |
| Validation | Attributs `#[Assert\*]` sur le DTO étendu | Native Symfony Validator |
| Vue table | Slots `extra-headers`, `extra-cells` (scoped sur `agency`) | `<template #extra-cells="{ agency }">` |
| Vue formulaire | Slot `extra-form-fields` (scoped sur `editForm`, `errors`) | `<template #extra-form-fields="{ editForm, errors }">` |
| Vue submit | Prop `extraFields` du composable `useAgenciesForm` | `{ <field>: { default, fromEntity } }` |
| Template Twig | Auto-prepend des paths client devant les paths bundle pour chaque namespace `@Core` / `@Platform` / etc. | _(pas nécessaire pour l'override Vue de cas pilote — la co-localisation suffit. Utile uniquement si tu veux changer le breadcrumb, le layout, ou les props passées à Vue depuis Twig)_ |

---

## Limitations connues

1. **Seul Agency** est instrumenté en pilote. Les 46 autres entités Aurora
   sont substituables côté DB (`resolve_target_entities`) mais leurs DTO,
   Manager, Serializer, View et templates restent à ouvrir un par un.
2. **La table `core_agencies`** reste mappée à l'entité Aurora et créée par la
   migration baseline d'Aurora, même si plus rien ne pointe dessus. C'est du
   "dead weight" — pas grave fonctionnellement, on pourra plus tard supprimer
   automatiquement la déclaration `#[ORM\Entity]` sur Aurora's concrete quand
   un client la remplace.
