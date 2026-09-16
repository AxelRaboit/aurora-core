# Étendre DocumentCategory de bout en bout (pilote)

Ce guide déroule, sur un **exemple générique**, le **câblage complet** à mettre
en place côté aurora-client pour ajouter un champ `code` à l'entité `DocumentCategory`
d'Aurora Core, **avec persistance, validation, sérialisation, affichage dans le
tableau backoffice et saisie dans le formulaire de création/édition** - sans
toucher à `vendor/aurora/`. L'extension `DocumentCategory` n'est qu'un support pédagogique :
elle **n'est pas forcément présente** dans ton projet ; les chemins
`src/Module/Ged/DocumentCategory/…` indiquent *où* écrire chaque couche.

C'est le pilote du pattern d'extensibilité (Sylius-style), transposable à
n'importe quelle entité Aurora.

---

## Structure : chemin miroir du namespace Aurora

Toute extension d'une entité Aurora vit dans `src/Module/` en **miroir** du
namespace Aurora source :

```
Aurora\Module\Ged\DocumentCategory\…  →  src/Module/Ged/DocumentCategory/…
```

---

## Vue d'ensemble - 5 couches à câbler

| Couche | Fichier(s) côté client | Mécanisme |
|---|---|---|
| Entité Doctrine | `src/Module/Ged/DocumentCategory/Entity/DocumentCategory.php` | `extends AbstractDocumentCategory`, table dédiée |
| DTO d'entrée | `src/Module/Ged/DocumentCategory/Dto/DocumentCategoryInput.php` + `DocumentCategoryInputFactory.php` | `extends`, `#[AsAlias]` |
| Manager | `src/Module/Ged/DocumentCategory/Manager/DocumentCategoryManager.php` | `extends`, `#[AsAlias]` |
| Serializer | `src/Module/Ged/DocumentCategory/Serializer/DocumentCategorySerializer.php` | `extends`, `#[AsAlias]` |
| Vue | `src/Module/Ged/DocumentCategory/assets/backend/document-categories/DocumentCategoriesApp.vue` (co-localisé avec l'extension PHP - shadow auto via clientModules glob) | slots scoped, pas de Twig override |

---

## 1. Entité - `App\Module\Platform\DocumentCategory\Entity\DocumentCategory`

**Important** : on étend `AbstractDocumentCategory` (le `MappedSuperclass`), **pas** la
classe concrète `Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory`. Étendre la classe concrète
exigerait de déclarer un `#[ORM\InheritanceType]` et un discriminator column,
ce qui impose Single ou Joined Table Inheritance - pas ce qu'on veut. Le
pattern Sylius : chaque app a sa propre table.

```php
// aurora-client : src/Module/Ged/DocumentCategory/Entity/DocumentCategory.php
namespace App\Module\Platform\DocumentCategory\Entity;

use Aurora\Module\Ged\DocumentCategory\Entity\AbstractDocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentCategoryRepository::class)]
#[ORM\Table(name: 'app_document-categories')]
class DocumentCategory extends AbstractDocumentCategory implements DocumentCategoryInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_category_id', allocationSize: 1)]
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

`config/packages/doctrine.yaml` - un seul mapping couvre tout `src/Module/` :

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
    orm:
        resolve_target_entities:
            Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface: App\Module\Platform\DocumentCategory\Entity\DocumentCategory
        mappings:
            AuroraClient:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Module'
                prefix: 'App\Module'
                alias: AuroraClient
```

> **Pourquoi `repositoryClass: DocumentCategoryRepository::class` côté client marche
> transparent** : Aurora's repositories étendent
> `Aurora\Core\Repository\ResolveTargetEntityRepository`, qui résout l'entité
> via `getClassMetadata(<Interface>::class)` à la construction. Donc une
> seule instance de repo, mais elle querie automatiquement votre table
> `app_document-categories` dès que `resolve_target_entities` route l'interface vers
> votre classe. Pas besoin de redéclarer un repository côté client (sauf si
> vous voulez ajouter vos propres méthodes - auquel cas étendez
> `Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository` et déclarez-le dans
> `config/packages/doctrine.yaml`).

À partir de cette config, **toutes** les associations Aurora qui type-hint
`DocumentCategoryInterface` (ex: `User::$category`) résolvent automatiquement vers votre
`App\Module\Platform\DocumentCategory\Entity\DocumentCategory`.

### 1.2 Migration - copie des données + bascule des FK

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
        return 'Add app_document-categories table extending Aurora Core DocumentCategory with code field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_app_category_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE app_document-categories (id INT NOT NULL, name VARCHAR(150) NOT NULL, code VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');

        // Copy existing rows from core_ged_document_categories so user FKs stay valid after the switch.
        $this->addSql('INSERT INTO app_document-categories (id, name, created_at, updated_at) SELECT id, name, created_at, updated_at FROM core_ged_document_categories');
        $this->addSql("SELECT setval('seq_app_category_id', GREATEST((SELECT COALESCE(MAX(id), 0) FROM app_document-categories), 1))");

        // Repoint the User → DocumentCategory FK to app_document-categories.
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT fk_42028409cdeadb2a');
        $this->addSql('ALTER TABLE core_users ADD CONSTRAINT FK_42028409CDEADB2A FOREIGN KEY (category_id) REFERENCES app_document-categories (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_users DROP CONSTRAINT FK_42028409CDEADB2A');
        $this->addSql('ALTER TABLE core_users ADD CONSTRAINT fk_42028409cdeadb2a FOREIGN KEY (category_id) REFERENCES core_ged_document_categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP TABLE app_document-categories');
        $this->addSql('DROP SEQUENCE seq_app_category_id CASCADE');
    }
}
```

Nuance importante : **chaque entité Aurora qui pointait `DocumentCategoryInterface`**
(ici juste `User::$category`, mais une autre fois ce serait Photo's
`Gallery::$clientContact`, etc.) génère une `ALTER TABLE … FK` à inclure dans
la migration. Le diff Doctrine les trouve toutes.

```bash
php bin/console doctrine:migrations:diff --namespace=ClientMigrations
# Nettoyer le fichier généré (lignes seq_log, seq_prj, messenger_messages, etc.)
# Ajouter le INSERT INTO app_document-categories … SELECT … FROM core_ged_document_categories
php bin/console doctrine:migrations:migrate
```

---

## 2. DTO d'entrée + Factory

### 2.1 DTO - `App\Module\Platform\DocumentCategory\Dto\DocumentCategoryInput`

```php
// aurora-client : src/Module/Ged/DocumentCategory/Dto/DocumentCategoryInput.php
namespace App\Module\Platform\DocumentCategory\Dto;

use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInput as AuroraDocumentCategoryInput;
use Symfony\Component\Validator\Constraints as Assert;

class DocumentCategoryInput extends AuroraDocumentCategoryInput
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

Symfony Validator inspecte les attributs du DTO étendu via réflexion - le
`Assert\Length` est appliqué automatiquement, pas besoin de re-déclarer le
`Assert\NotBlank` du parent.

### 2.2 Factory - `App\Module\Platform\DocumentCategory\Dto\DocumentCategoryInputFactory`

Le controller `DocumentCategoriesController` n'instancie plus directement
`DocumentCategoryInput::fromArray()` - il injecte un `DocumentCategoryInputFactoryInterface`.
On remplace l'alias d'Aurora par le nôtre :

```php
// aurora-client : src/Module/Ged/DocumentCategory/Dto/DocumentCategoryInputFactory.php
namespace App\Module\Platform\DocumentCategory\Dto;

use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputFactoryInterface;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputInterface;
use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentCategoryInputFactoryInterface::class)]
class DocumentCategoryInputFactory implements DocumentCategoryInputFactoryInterface
{
    public function fromArray(array $data): DocumentCategoryInputInterface
    {
        return new DocumentCategoryInput(
            name: Str::trimFromArray($data, 'name'),
            code: Str::trimFromArray($data, 'code') ?: null,
        );
    }
}
```

`#[AsAlias(DocumentCategoryInputFactoryInterface::class)]` écrase l'alias d'Aurora-core
sur ce même service-id : à la compilation du conteneur, `App\Module\Platform\DocumentCategory\Dto\DocumentCategoryInputFactory`
gagne, le controller reçoit votre factory.

### 2.3 Enregistrement - `config/services.yaml`

Le mapping `App\Module\:` couvre automatiquement tous les fichiers sous
`src/Module/`, y compris la factory. Rien à ajouter manuellement.

---

## 3. Manager - `App\Module\Platform\DocumentCategory\Manager\DocumentCategoryManager`

`resolve_target_entities` n'agit que sur la résolution Doctrine (associations,
queries) - un `new DocumentCategory()` PHP littéral instancie toujours la classe importée.
Aurora's `DocumentCategoryManager` expose donc deux hooks `protected` :

- `createDocumentCategory(): DocumentCategoryInterface` - instancie la nouvelle entité
- `applyInput(DocumentCategoryInterface $category, DocumentCategoryInputInterface $input): void` - la peuple

Vous override l'un ou l'autre (ou les deux) ; `parent::create()` et
`parent::update()` continuent de gérer persist + audit log.

```php
// aurora-client : src/Module/Ged/DocumentCategory/Manager/DocumentCategoryManager.php
namespace App\Module\Platform\DocumentCategory\Manager;

use App\Module\Platform\DocumentCategory\Dto\DocumentCategoryInput;
use App\Module\Platform\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManager as AuroraDocumentCategoryManager;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManagerInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentCategoryManagerInterface::class)]
class DocumentCategoryManager extends AuroraDocumentCategoryManager
{
    #[Override]
    protected function createDocumentCategory(): DocumentCategoryInterface
    {
        return new DocumentCategory();
    }

    #[Override]
    protected function applyInput(DocumentCategoryInterface $category, DocumentCategoryInputInterface $input): void
    {
        parent::applyInput($category, $input);

        if ($input instanceof DocumentCategoryInput && $category instanceof DocumentCategory) {
            $category->setCode($input->code);
        }
    }
}
```

`#[AsAlias(DocumentCategoryManagerInterface::class)]` remplace l'alias d'Aurora ;
le controller injecte votre Manager, qui instancie `App\Module\Platform\DocumentCategory\Entity\DocumentCategory`
(via `createDocumentCategory`) et persiste dans `app_document-categories` avec le bon `code`.

---

## 4. Serializer - `App\Module\Platform\DocumentCategory\Serializer\DocumentCategorySerializer`

```php
// aurora-client : src/Module/Ged/DocumentCategory/Serializer/DocumentCategorySerializer.php
namespace App\Module\Platform\DocumentCategory\Serializer;

use App\Module\Platform\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializer as AuroraDocumentCategorySerializer;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializerInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentCategorySerializerInterface::class)]
class DocumentCategorySerializer extends AuroraDocumentCategorySerializer
{
    #[Override]
    public function serialize(DocumentCategoryInterface $category): array
    {
        $data = parent::serialize($category);

        if ($category instanceof DocumentCategory) {
            $data['code'] = $category->getCode();
        }

        return $data;
    }
}
```

À ce stade, le payload JSON renvoyé par `/backend/platform/document-categories` contient `code`.

---

## 5. Vue - wrapper avec slots scoped

### 5.1 Composant client - chemin et alias

Aurora expose **deux** globs côté Vue (cf. `vendor/aurora/src/Core/assets/app.js`) :

- `@client/src/Module/**/assets/**/*.vue` - composants des modules client
  (vraies features comme Tracking, OU overrides co-localisés avec une
  extension PHP comme Platform/DocumentCategory). Les feature folders entre
  `Module/<Name>/` et `assets/` sont flatten dans la clé exposée. Exposés
  comme `<name>/<rest>` (ex: `tracking/backend/dashboard/...` ou
  `platform/backend/document-categories/DocumentCategoriesApp` quand on shadow Aurora)
- `@client/src/Overrides/**/*.vue` - escape hatch pour shadow des
  composants non-module (e.g. `src/Core/assets/...` d'aurora-core).
  Rare ; préférer la co-localisation sous `Module/<X>/<Feature>/assets/`
  quand on shadow un composant qui vit dans un module Aurora.

Le wrapper DocumentCategory vit avec l'extension PHP - co-localisation sous
`src/Module/Ged/DocumentCategory/assets/`.

```vue
<!-- aurora-client : src/Module/Ged/DocumentCategory/assets/backend/document-categories/DocumentCategoriesApp.vue -->
<script setup>
import AuroraDocumentCategoriesApp from "@core/backend/document-categories/DocumentCategoriesApp.vue";
import AppInput from "@/shared/components/form/AppInput.vue";

defineProps({
    document-categories: { type: Array, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

// Dit au composable Aurora useDocumentCategoriesForm comment hydrater editForm.code
// (reset à '' en création, lecture depuis category.code en édition).
const extraFields = {
    code: {
        default: "",
        fromEntity: (category) => category.code ?? "",
    },
};
</script>

<template>
    <AuroraDocumentCategoriesApp
        :document-categories="document-categories"
        :create-path="createPath"
        :update-path="updatePath"
        :delete-path="deletePath"
        :extra-fields="extraFields"
    >
        <template #extra-headers>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">Code</th>
        </template>
        <template #extra-cells="{ category }">
            <td class="px-4 py-3 text-muted">{{ category.code ?? '-' }}</td>
        </template>
        <template #extra-form-fields="{ editForm, errors }">
            <AppInput
                v-model="editForm.code"
                label="Code"
                placeholder="ex: PARIS-01"
                :error="errors.code ?? ''"
            />
        </template>
    </AuroraDocumentCategoriesApp>
</template>
```

Aliases utilisés (déclarés dans `vendor/aurora/vite.config.js`) :

| Alias | Pointe vers |
|---|---|
| `@core` | `vendor/aurora/src/Core/assets` |
| `@` | `vendor/aurora/assets` (composants `shared/`, etc.) |
| `@client` | `aurora-client/assets/client` (votre dossier) |

Le composable Aurora `useDocumentCategoriesForm(extraFields)` :
- au reset (création) : `editForm.code = ''`
- à l'ouverture en édition : `editForm.code = category.code`
- à la soumission : `request(url, { ...editForm })` envoie `name` ET `code`

### 5.2 Aucun override Twig nécessaire

Depuis la co-localisation, **pas besoin d'override Twig**. Le wrapper
client à `src/Module/Ged/DocumentCategory/assets/backend/document-categories/DocumentCategoriesApp.vue`
est exposé par le glob `clientModules` sous **la même clé** que le composant
Aurora (`platform/backend/document-categories/DocumentCategoriesApp`). Comme `clientModules` est
spread après `auroraModules` dans `vueContext`, ton fichier wins
automatiquement - Aurora rend `vue_component('platform/backend/document-categories/DocumentCategoriesApp', ...)`
et c'est ton wrapper qui prend.

Vérifier que l'override est bien pris :

```bash
# Build + recharger la page admin /backend/platform/document-categories. Inspecter le DOM :
# le composant Vue monté devrait avoir tes slots `extra-headers` /
# `extra-cells` / `extra-form-fields`.
npm run build
```

---

## 6. Tester

```bash
make demo                  # recharge fixtures + sync menus/privileges
make start                 # PHP server + Vite dev server
# → ouvrir /backend/ged/categories, créer une catégorie avec un code, recharger, éditer
```

---

## Récap des points d'extension exposés par Aurora pour DocumentCategory

| Couche | Interface / point d'extension côté Aurora | Pattern client |
|---|---|---|
| Entité | `DocumentCategoryInterface` + `AbstractDocumentCategory` | `extends AbstractDocumentCategory`, table dédiée |
| ResolveTargetEntity | mapping interface → concrete dans `doctrine.yaml` | `App\Module\Platform\DocumentCategory\Entity\DocumentCategory` |
| DTO d'entrée | `DocumentCategoryInputInterface` | `extends DocumentCategoryInput` |
| Factory de DTO | `DocumentCategoryInputFactoryInterface` | `#[AsAlias]` + nouvelle factory |
| Manager | `DocumentCategoryManagerInterface` + hooks `protected` (`createDocumentCategory()`, `applyInput()`) | `#[AsAlias]` + override des hooks |
| Serializer | `DocumentCategorySerializerInterface` | `#[AsAlias]` + `extends DocumentCategorySerializer` |
| Validation | Attributs `#[Assert\*]` sur le DTO étendu | Native Symfony Validator |
| Vue table | Slots `extra-headers`, `extra-cells` (scoped sur `category`) | `<template #extra-cells="{ category }">` |
| Vue formulaire | Slot `extra-form-fields` (scoped sur `editForm`, `errors`) | `<template #extra-form-fields="{ editForm, errors }">` |
| Vue submit | Prop `extraFields` du composable `useDocumentCategoriesForm` | `{ <field>: { default, fromEntity } }` |
| Template Twig | Auto-prepend des paths client devant les paths bundle pour chaque namespace `@Core` / `@Platform` / etc. | _(pas nécessaire pour l'override Vue de cas pilote - la co-localisation suffit. Utile uniquement si tu veux changer le breadcrumb, le layout, ou les props passées à Vue depuis Twig)_ |

---

## Limitations connues

1. **Seul DocumentCategory** est instrumenté en pilote. Les 46 autres entités Aurora
   sont substituables côté DB (`resolve_target_entities`) mais leurs DTO,
   Manager, Serializer, View et templates restent à ouvrir un par un.
2. **La table `core_ged_document_categories`** reste mappée à l'entité Aurora et créée par la
   migration baseline d'Aurora, même si plus rien ne pointe dessus. C'est du
   "dead weight" - pas grave fonctionnellement, on pourra plus tard supprimer
   automatiquement la déclaration `#[ORM\Entity]` sur Aurora's concrete quand
   un client la remplace.
