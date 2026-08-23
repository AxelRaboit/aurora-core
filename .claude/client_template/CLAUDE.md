# Aurora-client - Guide pour Claude

> 🔗 **Fichier symlinké** vers
> `vendor/axelraboit/aurora/.claude/client_template/CLAUDE.md`.
> Toujours à jour avec la version installée d'aurora-core - aucun sync manuel requis.
> Pour modifier le contenu : éditer le template dans aurora-core puis commit + push,
> et lancer `make aurora-update` côté client (recrée le symlink).
>
> Pour ajouter du contenu **spécifique au projet client** : créer `CLAUDE.local.md`
> à côté de ce fichier - Claude Code charge les deux automatiquement.

App Symfony cliente qui consomme `axelraboit/aurora` (aurora-core) comme
bundle composer + assets npm.

**Rôle** : **template de départ** pour tout nouveau projet client Aurora.

`src/`, `assets/` et `templates/` arrivent vides - tout vient du vendor. Ce
fichier annonçait aussi un rôle de **démonstration** illustrant les patterns
d'extension (Agency, module Tracking, overrides Vue) : ce contenu n'existe
pas. Les patterns sont à lire dans les mémoires et la doc listées ci-dessous,
et les skills (`/extend-aurora-entity`, …) scaffoldent le code correspondant.

---

## 📚 Base de mémoire (symlinks vers vendor via `make aurora-update`)

Les mémoires sont des **symlinks** vers `vendor/axelraboit/aurora/.claude/memory/`
créés par `make aurora-update`. Elles restent toujours en phase avec la version
installée d'aurora-core. **Ne pas éditer ces fichiers** - ils vivent dans vendor.

### Conventions partagées aurora-shared (Vue, fetch, i18n, JS, process)
[`.claude/memory/aurora-shared/MEMORY.md`](.claude/memory/aurora-shared/MEMORY.md),
conventions qui s'appliquent à tout code Aurora, core ou client : composants
Vue, `useRequest`/fetch, i18n, JS, AppLoader, commits, préférences. **À lire
en priorité** avant d'écrire du code Vue ou JS dans ce projet.

### Contexte aurora-core (conventions internes du bundle)
[`.claude/memory/aurora-core/MEMORY.md`](.claude/memory/aurora-core/MEMORY.md),
conventions, décisions, pièges et heuristiques du bundle aurora-core.
Utile pour comprendre *pourquoi* une API est faite ainsi avant de l'étendre.

### Patterns d'extension aurora-client
[`.claude/memory/aurora-client/MEMORY.md`](.claude/memory/aurora-client/MEMORY.md),
tout pour étendre une entité, un DTO, un Manager, un Serializer, la Vue
ou un template Twig depuis un projet client.

**Checklist d'extension complète** :
[`.claude/memory/aurora-client/checklist_extend_full_entity.md`](.claude/memory/aurora-client/checklist_extend_full_entity.md),
pas-à-pas pour étendre une entité de bout en bout.

**Pattern par couche** (5 couches Sylius) :
- [Entité](.claude/memory/aurora-client/pattern_extend_entity.md)
- [DTO](.claude/memory/aurora-client/pattern_extend_dto.md)
- [Manager](.claude/memory/aurora-client/pattern_extend_manager.md)
- [Serializer](.claude/memory/aurora-client/pattern_extend_serializer.md)
- [Vue](.claude/memory/aurora-client/pattern_extend_vue.md)
- [Twig override](.claude/memory/aurora-client/pattern_override_twig.md)
- [Repository](.claude/memory/aurora-client/pattern_extend_repository.md)

**Pièges à connaître** :
- [Toujours override `create<X>()` quand on étend une entité](.claude/memory/aurora-client/pitfall_create_hook_required.md)
- [Toujours `parent::applyInput()` AVANT d'ajouter ses setters](.claude/memory/aurora-client/pitfall_call_parent_apply_input.md)

---

## 🛠️ Skills Claude Code partagés (symlinks vers vendor via `make aurora-update`)

Les skills marqués `scope: shared` dans
`vendor/axelraboit/aurora/.claude/skills/` sont symlinkés dans
`.claude/skills/` de ce projet et donc invocables comme commandes
(`/extend-aurora-entity`, …). Le symlink est créé/refresh par
`make sync-claude-md` (lancé automatiquement par `make aurora-update`).

Skills aurora-shared disponibles côté client :
- `/extend-aurora-entity` - scaffold les 5 couches d'extension d'une
  entité aurora-core (entité concrète + DTO + Manager + Serializer + Vue
  wrapper) à partir d'un champ donné. Suit la convention canonique.

Pour ajouter un skill **spécifique** au projet client (pas réutilisable
ailleurs), créer un dossier dans `.claude/skills/<mon-skill>/SKILL.md` -
il ne sera pas écrasé par le sync (qui ne touche que les noms symlinkés
depuis vendor).

---

## 📖 Documentation développeur (lue depuis vendor - pas de copie locale)

Toute la documentation Aurora vit dans `vendor/axelraboit/aurora/docs/`.
Pas de symlink, pas de sync : on lit directement le vendor. Tu obtiens
toujours la version installée d'aurora-core, et un `composer update` met
la doc à jour en même temps que le code.

### vendor/axelraboit/aurora/docs/aurora-client/ - Guide dev pour ce projet
- [getting-started/philosophy.md](vendor/axelraboit/aurora/docs/aurora-client/getting-started/philosophy.md) - Philosophie (deux modes : étendre Aurora vs créer un module)
- [getting-started/setup.md](vendor/axelraboit/aurora/docs/aurora-client/getting-started/setup.md) - Installation locale
- [getting-started/architecture.md](vendor/axelraboit/aurora/docs/aurora-client/getting-started/architecture.md) - Structure du projet
- [dev/dev_workflow.md](vendor/axelraboit/aurora/docs/aurora-client/dev/dev_workflow.md) - Commandes du quotidien
- [dev/database.md](vendor/axelraboit/aurora/docs/aurora-client/dev/database.md) - Migrations, fixtures, séquences
- [dev/assets_vue.md](vendor/axelraboit/aurora/docs/aurora-client/dev/assets_vue.md) - Composants Vue côté client
- [dev/update_aurora.md](vendor/axelraboit/aurora/docs/aurora-client/dev/update_aurora.md) - Mettre à jour aurora-core
- [deployment/](vendor/axelraboit/aurora/docs/aurora-client/deployment/) - Tout le déploiement prod (systemd, mod_xsendfile, OCR)
- [extending/add_module.md](vendor/axelraboit/aurora/docs/aurora-client/extending/add_module.md) - Créer un nouveau module client (5 cas types)
- [extending/extend_module.md](vendor/axelraboit/aurora/docs/aurora-client/extending/extend_module.md) - Étendre un module Aurora (5 couches d'entité + Twig + finders + décorateurs + permissions custom)

### vendor/axelraboit/aurora/docs/aurora-shared/ - Conventions trans-couches
- [form_validation.md](vendor/axelraboit/aurora/docs/aurora-shared/form_validation.md) - DTO + PayloadValidator + useForm
- [testing_php.md](vendor/axelraboit/aurora/docs/aurora-shared/testing_php.md) - Patterns PHPUnit
- [testing_vue.md](vendor/axelraboit/aurora/docs/aurora-shared/testing_vue.md) - Patterns Vitest
- [translations.md](vendor/axelraboit/aurora/docs/aurora-shared/translations.md) - Workflow i18n
- [scheduler.md](vendor/axelraboit/aurora/docs/aurora-shared/scheduler.md) - Symfony Scheduler
- [convention_seo_head.md](vendor/axelraboit/aurora/docs/aurora-shared/convention_seo_head.md) - SEO macros frontend

### vendor/axelraboit/aurora/docs/aurora-core/ - Architecture interne du bundle
- [philosophy.md](vendor/axelraboit/aurora/docs/aurora-core/philosophy.md) - Philosophie (zéro fork, 5 couches, modules)
- [dev/app_architecture.md](vendor/axelraboit/aurora/docs/aurora-core/dev/app_architecture.md) - Architecture, modules, Vite aliases
- [dev/entity_extensibility_convention.md](vendor/axelraboit/aurora/docs/aurora-core/dev/entity_extensibility_convention.md) - Convention d'extensibilité des entités
- [dev/extending_aurora.md](vendor/axelraboit/aurora/docs/aurora-core/dev/extending_aurora.md) - Points d'extension publics du bundle
- [ops/prerequisites.md](vendor/axelraboit/aurora/docs/aurora-core/ops/prerequisites.md) - Checklist exhaustive des prérequis

---

## Quand ajouter une nouvelle mémoire ?

Si pendant une session tu rencontres :
- Un **nouveau pattern client** (cas non couvert par les fichiers ci-dessus)
- Un **piège côté client** (config Symfony, conflit DI, schéma migration, etc.)
- Une **décision spécifique au client** (ex: choix d'architecture interne au
  projet client, conventions équipe)

→ Trois options selon le scope :

1. **Pattern d'extension Aurora utilisable par tous les clients** : ajouter
   à `aurora-core/.claude/memory/aurora-client/` + commit + push côté aurora-core.
   Sera disponible chez tous les clients via vendor au prochain `make aurora-update`
   (symlinké depuis `.claude/memory/aurora-client/`).

2. **Convention/pattern interne au projet client** (pas réutilisable) :
   créer une mémoire **locale** ici dans `aurora-client/.claude/memory/`
   (à créer si pas existant) avec son propre `MEMORY.md`. Référencer ce
   fichier ici dans CLAUDE.md.

3. **Préférence personnelle de l'utilisateur** : remonter à la mémoire
   user-level (Claude le fera automatiquement si la préférence est
   inter-projets).

---

## Commandes utiles

```bash
# Symfony
php bin/console cache:clear
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
php bin/console debug:container <ServiceName>  # vérifier qu'un AsAlias prend bien

# Tests
php bin/phpunit

# Assets
npm run dev
npm run build

# Mettre à jour aurora-core (pull les nouvelles mémoires aussi)
make aurora-update
```

---

## Mémoire locale du projet client (optionnelle)

Si ce projet client a des conventions / pièges / décisions **spécifiques**
qui ne doivent pas être écrasés au prochain `aurora-update`, créer
`CLAUDE.local.md` à côté de ce fichier. Claude Code charge automatiquement
les deux (CLAUDE.md auto-généré + CLAUDE.local.md custom).

Recommandation : `CLAUDE.local.md` doit lister les conventions internes
qui n'ont rien à voir avec aurora-core (architecture du projet, conventions
équipe, intégrations tierces spécifiques, etc.).

---

## Targets Makefile spécifiques au client (optionnel)

Le `Makefile` est synchronisé depuis aurora-core et **écrasé** à chaque
`make aurora-update`. Pour ajouter des targets propres au projet client
sans les perdre :

1. Créer un `Makefile.local` à la racine du projet client.
2. Y mettre les targets custom :
   ```makefile
   deploy-staging:
       ./bin/deploy.sh staging

   reset-fixtures:
       php bin/console doctrine:fixtures:load --no-interaction
   ```
3. Le Makefile principal fait `-include Makefile.local` à la fin → les
   targets sont disponibles via `make deploy-staging` etc. comme s'ils
   étaient dans le Makefile principal.

`Makefile.local` n'est **jamais** touché par `sync-makefile`.

---

## Contrat de synchronisation - qui possède quoi

`make aurora-update` (et `make pull-update`) touchent au projet client.
Voici exactement ce qui est écrasé vs ce qui appartient au client.

### Sync agressif - écrasé à chaque run

| Fichier / dossier | Mécanisme |
|---|---|
| `CLAUDE.md` | symlink vers vendor (toujours canonique) |
| `Makefile` | `cp` depuis vendor - **refuse si tu as des edits non commités** (sauf `FORCE=1`). Targets perso → `Makefile.local`. |
| `config/packages/security.yaml` | `cp` depuis vendor à chaque run. Custom-sec → `security_custom.yaml` chargé en complément, ou `EventSubscriber`. |
| `jsconfig.json` | régénéré depuis les modules vendor (`bin/sync-client-jsconfig`) |
| `.claude/memory/aurora-core/` | symlink vers vendor |
| `.claude/memory/aurora-client/` | symlink vers vendor |
| `.claude/memory/aurora-shared/` | symlink vers vendor |
| `.claude/skills/<nom>/` (avec `scope: shared`) | symlink par skill vers vendor |

### Sync partiel - bloc encadré écrasé, le reste du fichier client-owned

| Fichier | Mécanisme |
|---|---|
| `README.md` | **bloc entre `<!-- aurora-canonical:start -->` et `<!-- aurora-canonical:end -->` écrasé** par `make sync-readme` (intégré à `aurora-update` / `pull-update`). Titre, intro et section "Spécifique à ce projet" préservés. Si les markers manquent (legacy README), le fichier est laissé tel quel avec un warning suggérant de les ajouter. |
| `.env` blocs `###> aurora/* ###` | **ajoutés** si absents (`make sync-env`), insérés **au-dessus** du divider `# === CLIENT CUSTOM ===`. Valeurs existantes jamais touchées. |

### Seed once - créé si absent, jamais écrasé

| Fichier | Mécanisme |
|---|---|
| `.claude/settings.json` | copié depuis le template au 1er install. Une fois présent = client-owned. |

#### Layout d'un `.env` aurora-client

```
# Symfony Flex blocks (DATABASE_URL, MAILER_DSN, …)
# aurora/* blocks (sync-env les maintient ici, valeurs préservées)
…
# ====================== CLIENT CUSTOM ======================
# Variables propres au projet client.
# `make aurora-update` ne touche JAMAIS aux lignes en dessous.
# ===========================================================
MY_API_KEY=…             ← À toi
WHATEVER_TOKEN=…         ← À toi
```

`sync-env` ajoute le divider tout seul au premier run et insère les
nouveaux blocs aurora juste au-dessus.

#### Layout d'un `README.md` aurora-client

```markdown
# <Project name>                    ← client-owned (titre)
<intro paragraph>                   ← client-owned

<!-- aurora-canonical:start -->     ← marker (ne pas toucher)
## 🚀 Quickstart                    ← bloc géré par sync-readme
## Le quotidien
## Intégration continue
## Comment utiliser Aurora ?
## Customisation projet
<!-- aurora-canonical:end -->       ← marker (ne pas toucher)

## Spécifique à ce projet           ← client-owned
<URL staging, contacts équipe, etc.>
```

`sync-readme` détecte la paire de markers et remplace **uniquement** le
contenu entre les deux. Sans markers (READMEs legacy), le fichier est
laissé tel quel - le client doit ajouter les markers manuellement une
fois pour opter dans le sync.

### 100% client-owned - jamais touché par les sync

- **Tout ce qui est sous le divider `# === CLIENT CUSTOM ===` du `.env`**
- `composer.json` (le client ajoute ses propres deps)
- `.env.local`, `.env.test`, `.env.test.local`
- `config/services.yaml` (client-side)
- `config/packages/*.yaml` **sauf** `security.yaml`
- `src/`, `assets/`, `templates/`, `tests/`, `migrations/`
- `Makefile.local`
- `CLAUDE.local.md`
- `.claude/skills/<custom-name>/` (skills non symlinkés)
- `public/uploads/`, `var/`

### Effets en DB (additifs, pas destructifs)

| Commande | Effet |
|---|---|
| `make migrate-f` | applique les nouvelles migrations Doctrine (vendor + client). Jamais de downgrade auto. |
| `aurora:privileges:sync` | crée les nouveaux `NavPermission` déclarés dans le code. Ne supprime pas les anciens. |
| `aurora:application-parameter` | synchronise les settings registry. Ne supprime jamais une valeur saisie côté admin. |
| `aurora:install` | crée les données de socle manquantes de chaque module (locales, thème, types de contenu, taxonomies, menus). Ne remplace jamais ce qui a été édité côté admin. |

### Points d'attention

- **`config/services.yaml`** : pas sync. Si aurora-core introduit un nouveau pattern `_instanceof` (genre `aurora.assistant.tool`), le client doit l'ajouter manuellement.
- **Privilège renommé** (ancien `notes.write` → nouveau `notes.markdown.write`) : pas de migration auto, prévoir une commande de cleanup ad-hoc.
- **Migration vendor + client partagent le même namespace** (`DoctrineMigrations`). Éviter de créer une migration locale avec le même horodatage qu'une à venir côté core.

