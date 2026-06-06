# Démarrer un projet aurora-client

Ce guide couvre la **première installation** d'un projet client basé sur
aurora-core. À lire quand on vient de cloner le template `aurora-client` (ou
qu'on en hérite) et qu'on doit l'avoir tournant en local pour la première
fois. Pour le workflow quotidien (cache, tests, migrations…), voir
[`dev_workflow.md`](dev_workflow.md). Pour étendre une entité Aurora, voir
[`../extending/extend_module.md`](../extending/extend_module.md).

---

## 1. Prérequis

| Outil | Version | Vérification |
|---|---|---|
| PHP | `>=8.4` (cf. `composer.json`) | `php -v` |
| Composer | 2.x | `composer --version` |
| Node | 20+ (via Vite/pnpm) | `node -v` |
| pnpm | géré via corepack (cf. `make pnpm-setup VERSION=10.11.0`) | `pnpm -v` |
| PostgreSQL | 18 (cf. `DATABASE_URL` par défaut, `serverVersion=18`) | `psql --version` |
| Symfony CLI | recommandé (`make start` l'utilise) | `symfony -v` |
| Docker | optionnel — utilisé par `make docker-up` pour PostgreSQL | `docker -v` |

> Le binaire `php` doit pointer sur PHP 8.4 dans le `PATH`. Le Makefile
> n'autorise pas de variante (`PHP_BIN = php`).

---

## 2. Premier setup

```bash
# 1. Cloner le template
git clone <repo> mon-projet
cd mon-projet

# 2. Variables d'environnement
make setup-env          # copie .env.local.example → .env.local
$EDITOR .env.local      # renseigner DATABASE_URL, APP_SECRET, AURORA_MOUNT_POINT_KEY

# 3. Installation complète (composer + pnpm + DB + migrations + fixtures + dev server)
make install            # alias vers make install-dev
```

`make install` (cf. `Makefile`) enchaîne :

1. `composer install` pour le projet client puis pour `vendor/axelraboit/aurora/`
2. `composer install` des outils (`php-cs-fixer`, `twig-cs-fixer`, `rector`, `phpstan`)
3. `pnpm install` dans le vendor aurora **et** dans le projet client
4. `make setup-dirs` (crée `var/cache`, `var/log`)
5. `make db-create` + `make migrate` (joue les migrations Aurora **et** client)
6. `doctrine:fixtures:load`
7. `aurora:application-parameter` (synchronise les ApplicationParameters)
8. `aurora:menus:sync` (crée les menus par défaut)
9. `make dev` (Vite dev server)

Compte admin par défaut après `make install` : `admin@aurora.app` / `password`.

---

## 3. Variables d'environnement requises

Toutes définies dans `.env.local.example` (à recopier vers `.env.local`) :

| Variable | Description |
|---|---|
| `APP_SECRET` | Clé Symfony. Générer : `php -r "echo bin2hex(random_bytes(16));"` |
| `DATABASE_URL` | DSN PostgreSQL. Format par défaut : `postgresql://app:password@127.0.0.1:5432/aurora-client?serverVersion=18&charset=utf8` |
| `AURORA_MOUNT_POINT_KEY` | Clé base64 32 bytes (chiffrement des MountPoints). Générer : `php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"` |

`.env` par défaut (versionné) définit aussi :
- `APP_ENV=dev`
- `MAILER_DSN=smtp://localhost:1025` (Mailcatcher local)
- `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`
- `APP_SHARE_DIR=var/share`

Ne jamais committer `.env.local` (déjà dans `.gitignore`).

---

## 4. Structure du repo client

```
mon-projet/
├── vendor/axelraboit/aurora/   # aurora-core (read-only)
├── src/
│   ├── Module/                 # App\Module\* — TOUT le code client
│   │   ├── Core/               #   Extensions d'entités Aurora\Core\*
│   │   │   └── Agency/         #     Entity/ Dto/ Manager/ Serializer/
│   │   ├── Tracking/           #   ex. module client autonome (illustratif — non fourni)
│   │   └── …
│   ├── Service/                # App\Service\* — helpers transverses (rare)
│   ├── EventListener/          # App\EventListener\* — listeners globaux (rare)
│   └── Kernel.php              # Wrapper minimal du Kernel Aurora
├── templates/
│   ├── Core/                   # Overrides @Core/... (résolus AVANT le vendor)
│   └── Module/<Name>/          # Templates des modules client
├── assets/client/
│   ├── Module/<Name>/          # Composants Vue des modules client
│   ├── Overrides/              # Wrappers autour des composants Aurora
│   └── locales/{fr,en}.js      # Traductions Vue-only
├── migrations/                 # Migrations Doctrine client (ClientMigrations)
├── config/
│   ├── bundles.php             # Aurora\AuroraBundle déjà déclaré
│   ├── packages/
│   │   ├── doctrine.yaml       # resolve_target_entities + mapping AuroraClient
│   │   ├── security.yaml       # ECRASÉ par make sync-security à chaque update
│   │   └── …
│   ├── services.yaml           # App\Module\: PSR-4 + tags aurora.module
│   ├── routes.yaml
│   └── routes/
├── tests/
│   ├── Unit/                   # vide au départ — à peupler
│   ├── Integration/
│   └── bootstrap.php
├── public/                     # index.php + build/ (assets compilés)
├── translations/               # YAML Symfony côté client (optionnel)
├── Makefile                    # SYMLINK / écrasé par sync-makefile — ne pas éditer
├── Makefile.local              # targets custom du projet (jamais touché par sync)
├── CLAUDE.md                   # SYMLINK depuis vendor — ne pas éditer
├── CLAUDE.local.md             # instructions Claude locales (jamais touché)
└── .env.local                  # secrets + DSN (jamais committé)
```

> **Pas de `src/AuroraBundle.php` côté client.** L'`AuroraBundle` vit dans le
> vendor (`vendor/axelraboit/aurora/src/AuroraBundle.php`). Côté client, la
> configuration `resolve_target_entities` se fait dans
> `config/packages/doctrine.yaml` (cf. exemple plus bas), **pas** dans un
> bundle PHP.

---

## 5. Enregistrer un override d'entité Aurora

Exemple minimal — ajouter un champ `code` à `Agency` :

### a) Entité — `src/Module/Platform/Agency/Entity/Agency.php`

```php
namespace App\Module\Platform\Agency\Entity;

use Aurora\Module\Platform\Agency\Entity\{AbstractAgency, AgencyInterface};
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

    public function getId(): ?int { return $this->id; }
    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): static { $this->code = $code; return $this; }
}
```

### b) Doctrine — `config/packages/doctrine.yaml`

```yaml
doctrine:
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

> **Préfixes obligatoires côté client** : tables en `app_*`, séquences en
> `seq_app_*`. Le préfixe `core_` est réservé à aurora-core (collision sinon).
> Voir [`../extending/extend_module.md`](../extending/extend_module.md) pour le détail.

### c) Migration

```bash
make migration          # génère la migration depuis les changements d'entité
# Relire le fichier généré sous migrations/VersionYYYYMMDDHHMMSS.php
make migrate
make schema-validate    # doit dire "schema in sync"
```

Cf. [`database.md`](database.md) pour les détails (sequences, fixtures,
migrations Aurora vs client).

### d) DTO + Manager + Serializer + Vue

L'entité seule ne suffit pas pour exposer le champ `code` dans le formulaire
admin et le persister. Il faut **les 5 couches complètes** :

1. **DTO** : étendre `AgencyInput` + décorer `AgencyInputFactory` avec `#[AsAlias]`
2. **Manager** : étendre `AgencyManager` + override `createAgency()` (instancier la classe cliente) + `applyInput()` (mapper `$input->code` → `$agency->setCode()`)
3. **Serializer** : étendre `AgencySerializer` + spread `parent::serialize()` avec `'code'`
4. **Vue** : wrapper `AgenciesApp.vue` **co-localisé** avec l'extension PHP sous `src/Module/Platform/Agency/assets/backend/agencies/` avec `extraFields` + slots `extra-headers` / `extra-cells` / `extra-form-fields`

Snippets complets et patterns d'extension dans
[`../extending/extend_module.md`](../extending/extend_module.md) (sections §1-5).

---

## 6. Créer un nouveau module client autonome

Exemple : un module `Tracking` non-existant dans aurora-core.

1. Créer le dossier `src/Module/Tracking/` (autodécouverte Doctrine/Twig)
2. Créer `src/Module/Tracking/TrackingModule.php` qui implémente
   `Aurora\Core\Module\Contract\ModuleInterface`
3. L'enregistrer dans `config/services.yaml` :
   ```yaml
   App\Module\Tracking\TrackingModule:
       tags: [aurora.module]
   ```
4. Pour un exemple complet (entité custom, NavSection, NavPermission,
   `ModuleToggle` pour le panel "Accès modules"), voir
   [`../extending/add_module.md`](../extending/add_module.md) — il déroule un
   module d'exemple `Tracking` de bout en bout. C'est un exemple générique,
   pas un module fourni par défaut.

Référence canonique côté core : [`../../aurora-core/dev/add_module.md`](../../aurora-core/dev/add_module.md).

---

## 7. Premier "hello world" admin

Pour ajouter une page admin minimale (sans entité) :

1. Controller `src/Module/Hello/Controller/Backend/HelloController.php` avec
   `#[Route('/backend/hello')]` + `#[IsGranted('hello.use')]`.
2. Template `src/Module/Hello/templates/backend/index.html.twig` qui étend
   `@Core/backend/layout.html.twig`.
3. Permission `hello.use` exposée par un `HelloModule` (cf. add_module.md).

`make sf CMD="aurora:privileges:sync"` puis aller dans Paramètres → Rôles
pour assigner la permission.

---

## 8. Point de départ propre

Le template `aurora-client` démarre **propre** : `src/Module/` ne contient
aucun module métier, juste de quoi accrocher les tiens. Les exemples utilisés
dans cette doc (module `Tracking`, extension `Agency` + champ `code`) sont des
supports pédagogiques génériques — ils **ne sont pas livrés** dans le template ;
suis les guides pour les reconstruire si tu veux les voir en action :

- module client from scratch → [`../extending/add_module.md`](../extending/add_module.md)
- extension d'entité Aurora → [`../extending/extend_module.md`](../extending/extend_module.md)

Pour amorcer la base : `make migrate` puis `make fixtures` (ou les seeders du
projet).

---

## 9. Étapes suivantes

| Sujet | Doc |
|---|---|
| Workflow quotidien (start, tests, fix, migrations…) | [`dev_workflow.md`](dev_workflow.md) |
| Étendre une entité Aurora (5 couches) | [`../extending/extend_module.md`](../extending/extend_module.md) |
| Assets Vue, aliases, overrides | [`assets_vue.md`](assets_vue.md) |
| Migrations, fixtures, séquences | [`database.md`](database.md) |
| Mettre à jour aurora-core | [`update_aurora.md`](update_aurora.md) |
| Tests côté client | [`testing_client.md`](testing_client.md) |
| Déploiement production | [`deployment.md`](deployment.md) |
| Cheatsheet « où je mets mon code ? » | [`../extending/add_module.md`](../extending/add_module.md) (nouveau module) ou [`../extending/extend_module.md`](../extending/extend_module.md) (étendre Aurora) |
| Mémoire IA / Claude Code dans le projet client | [`memory_for_ai.md`](memory_for_ai.md) |
