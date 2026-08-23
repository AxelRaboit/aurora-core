# Migration aurora-core 0.3.x → 0.4.0

**Type** : breaking - namespaces déplacés.

Les entités Core qui appartenaient logiquement à un module parent (Platform,
Configuration, General, Media, Dev) vivent désormais dans un sous-dossier
du module. La convention est alignée sur celle déjà en place côté
`src/Module/` (Vault, Notes, Editorial…) : **1 module = 1 dossier =
sous-modules à l'intérieur**.

> **DB intacte** : aucune migration Doctrine n'est nécessaire. Les tables
> (`core_user`, `core_agency`, `core_audit_log`, `core_media`,
> `core_setting`, etc.) gardent le même nom. Seules les classes PHP
> bougent.

## Assets co-location (root `assets/` supprimé)

**Côté core** (aurora-core) : le dossier `assets/` à la racine du repo a été
éliminé. Tout le JS/Vue/CSS est désormais co-localisé sous `src/`, en
miroir de la structure PHP.

| Avant | Après |
|---|---|
| `assets/Module/<X>/...` | `src/Module/<X>/assets/...` |
| `assets/Core/backend/...` | `src/Core/assets/backend/...` |
| `assets/Core/frontend/...` | `src/Core/assets/frontend/...` |
| `assets/Core/utils/...` | `src/Core/assets/utils/...` |
| `assets/shared/...` | `src/Core/assets/shared/...` |
| `assets/locales/generated/...` | `src/Core/assets/locales/generated/...` |
| `assets/css/...` (sauf modules) | `src/Core/assets/css/...` |
| `assets/css/modules/notes/markdown/preview.css` | `src/Module/Notes/assets/backend/markdown/components/preview.css` |
| `assets/css/modules/editorial/prose.css` | `src/Module/Editorial/assets/backend/posts/prose.css` |
| `assets/css/core/sidemenu.css` | `src/Core/assets/backend/sidemenu/sidemenu.css` |
| `assets/controllers/` | `src/Core/assets/stimulus/` (renommé pour éviter le clash avec `Controller/` PHP) |
| `assets/controllers.json` | `src/Core/assets/stimulus.json` (override Symfony : `config/packages/stimulus.yaml`) |
| `assets/tests/` | `src/Core/assets/tests/` |
| `assets/.client-fallback/` | `src/Core/assets/.client-fallback/` |
| `assets/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` | `src/Core/assets/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` |

- Les **aliases Vite** (`@vault`, `@editorial`, `@platform`, `@configuration`,
  `@media`, `@general`, `@dev`, …) continuent de fonctionner ; leurs cibles
  pointent désormais vers `src/Module/<X>/assets/`. `@core`, `@`, `@shared`
  résolvent sous `src/Core/assets/`.
- Les imports `@/css/...` ont été remplacés par des **chemins relatifs**
  (`./preview.css`, `./prose.css`, `./sidemenu.css`) puisque les CSS sont
  désormais co-localisés avec leur SFC.
- **Stimulus** : le folder a été renommé (`controllers/` → `stimulus/`,
  `controllers.json` → `stimulus.json`) pour éviter la confusion avec les
  controllers PHP. La convention par défaut Symfony (`assets/controllers/`)
  est overridée via `config/packages/stimulus.yaml`.
- **Translations dump path** : `src/Core/assets/locales/generated/`.
- **Entry points Vite** : `./assets/app.js` → `./src/Core/assets/app.js`
  (et de même pour `flash`, `theme`, `guest`, `i18n`, `stimulus_bootstrap`).

**Côté client** (aurora-client) : **rien ne change**. Votre layout
`assets/client/Module/<X>/` reste inchangé. Il suffit de pull la dernière
release composer d'aurora-core et de re-builder :

```bash
make aurora-update
pnpm install  # si lock changé
pnpm run build
```

## Table de correspondance

| Avant 0.4.0 | Après 0.4.0 |
|---|---|
| `Aurora\Core\Dashboard\*` | `Aurora\Core\General\Dashboard\*` |
| `Aurora\Core\Profile\*` | `Aurora\Core\General\Profile\*` |
| `Aurora\Core\Search\*` | `Aurora\Core\General\Search\*` |
| `Aurora\Core\Audit\*` | `Aurora\Core\Dev\Audit\*` |
| `Aurora\Core\Setting\*` | `Aurora\Core\Configuration\Setting\*` |
| `Aurora\Core\Theme\*` | `Aurora\Core\Configuration\Theme\*` |
| `Aurora\Core\Media\*` | `Aurora\Core\Media\Library\*` |
| `Aurora\Core\User\*` | `Aurora\Core\Platform\User\*` |
| `Aurora\Core\Agency\*` | `Aurora\Core\Platform\Agency\*` |
| `Aurora\Core\Auth\*` | `Aurora\Core\Platform\Auth\*` |
| `Aurora\Core\Service\Entity\*` | `Aurora\Core\Platform\Service\Entity\*` |
| `Aurora\Core\Service\Dto\*` | `Aurora\Core\Platform\Service\Dto\*` |
| `Aurora\Core\Service\Manager\*` | `Aurora\Core\Platform\Service\Manager\*` |
| `Aurora\Core\Service\Repository\*` | `Aurora\Core\Platform\Service\Repository\*` |
| `Aurora\Core\Service\Serializer\*` | `Aurora\Core\Platform\Service\Serializer\*` |
| `Aurora\Core\Service\Controller\*` | `Aurora\Core\Platform\Service\Controller\*` |
| `Aurora\Core\Service\View\*` | `Aurora\Core\Platform\Service\View\*` |
| `Aurora\Core\Service\{Platform,Media,Configuration,General}Context` | `Aurora\Core\{Platform,Media,Configuration,General}\{Same}Context` (à la racine du folder du module) |
| `Aurora\Module\<X>\Service\<X>Context` (12 modules business) | `Aurora\Module\<X>\<X>Context` (à la racine du folder du module) |
| `Aurora\Core\Menu\*` | `Aurora\Module\Editorial\Menu\*` (Menu est un sous-module d'Editorial) |
| `Aurora\Core\MountPoint\*` | `Aurora\Module\Dev\MountPoint\*` |
| **`Aurora\Core\Platform\*`** | **`Aurora\Module\Platform\*`** (tous les modules Core promus sous src/Module/) |
| **`Aurora\Core\Configuration\*`** | **`Aurora\Module\Configuration\*`** |
| **`Aurora\Core\Media\*`** | **`Aurora\Module\Media\*`** |
| **`Aurora\Core\General\*`** | **`Aurora\Module\General\*`** |
| **`Aurora\Core\Dev\*`** | **`Aurora\Module\Dev\*`** |
| **`Aurora\Core\{Platform, Configuration, Media, General, Dev}Module`** | **`Aurora\Module\<X>\<X>Module`** (les classes Module ont suivi leur folder) |

### Templates + assets (2e vague de la promotion 0.4)

Les dossiers `templates/Core/backend/<X>/` et `assets/Core/backend/<X>/` ont
aussi été déplacés vers les modules promus :

| Avant | Après |
|---|---|
| `templates/Core/backend/{agencies,auth,services,users}/` | `templates/Module/Platform/backend/<X>/` |
| `templates/Core/backend/{settings,themes}/` | `templates/Module/Configuration/backend/<X>/` |
| `templates/Core/backend/media/` | `templates/Module/Media/backend/media/` |
| `templates/Core/backend/{dashboard,profile}/` | `templates/Module/General/backend/<X>/` |
| `templates/Core/backend/dev/` | `templates/Module/Dev/backend/` (flattened - plus de `dev/` middle dir) |
| `assets/Core/backend/<X>/` (mêmes 10) | `src/Module/<NewModule>/assets/backend/<X>/` (mêmes 10) |
| `assets/Core/backend/AdministrationApp.vue` | `src/Module/Dev/assets/backend/AdministrationApp.vue` |

**Restent à `Core/backend/`** (templates) : `layout.html.twig`,
`base_guest.html.twig`. Côté assets, l'équivalent sidemenu/notifications a
été déplacé sous `src/Core/assets/backend/sidemenu/` et
`src/Core/assets/backend/notifications/` lors de la suppression du root
`assets/` (voir section "Assets co-location" plus bas).

### Refs côté client à mettre à jour

Pour les overrides Vue/Twig qui réfèrent ces paths :

| Avant (JS imports) | Après |
|---|---|
| `@core/backend/agencies/<X>` | `@platform/backend/agencies/<X>` |
| `@core/backend/auth/<X>` | `@platform/backend/auth/<X>` |
| `@core/backend/services/<X>` | `@platform/backend/services/<X>` |
| `@core/backend/users/<X>` | `@platform/backend/users/<X>` |
| `@core/backend/settings/<X>` | `@configuration/backend/settings/<X>` |
| `@core/backend/themes/<X>` | `@configuration/backend/themes/<X>` |
| `@core/backend/media/<X>` | `@media/backend/media/<X>` |
| `@core/backend/dashboard/<X>` | `@general/backend/dashboard/<X>` |
| `@core/backend/profile/<X>` | `@general/backend/profile/<X>` |
| `@core/backend/dev/<X>` | `@dev/backend/<X>` |
| `@/Core/backend/<X>/<feature>` (variant) | `@/Module/<NewModule>/backend/<X>/<feature>` |

Côté Twig (overrides templates) :

| Avant | Après |
|---|---|
| `{% extends '@Core/backend/agencies/...' %}` | `{% extends '@Platform/backend/agencies/...' %}` |
| `{{ vue_component('core/backend/<X>/...') }}` | `{{ vue_component('<lowercase>/backend/<X>/...') }}` |
| `templates/Core/backend/<X>/` (overrides locaux) | `templates/Module/<NewModule>/backend/<X>/` |

Snippet sed bulk côté client :

```bash
grep -rl "@core/backend/\(agencies\|auth\|services\|users\)" assets src 2>/dev/null \
  | xargs sed -i \
    -e 's|@core/backend/agencies/|@platform/backend/agencies/|g' \
    -e 's|@core/backend/auth/|@platform/backend/auth/|g' \
    -e 's|@core/backend/services/|@platform/backend/services/|g' \
    -e 's|@core/backend/users/|@platform/backend/users/|g' \
    -e 's|@core/backend/settings/|@configuration/backend/settings/|g' \
    -e 's|@core/backend/themes/|@configuration/backend/themes/|g' \
    -e 's|@core/backend/media/|@media/backend/media/|g' \
    -e 's|@core/backend/dashboard/|@general/backend/dashboard/|g' \
    -e 's|@core/backend/profile/|@general/backend/profile/|g' \
    -e 's|@core/backend/dev/|@dev/backend/|g'
```

> **Aliases Vite** : 5 nouveaux aliases ont été ajoutés à `aliases.js`
> côté vendor : `@platform`, `@configuration`, `@media`, `@general`,
> `@dev`. Côté client, `make aurora-update` re-synchronise
> `jsconfig.json` automatiquement (`make sync-jsconfig`) - les imports
> avec les nouveaux aliases marchent immédiatement.

### Inchangé (cross-cutting infra)

Les dossiers suivants ne sont pas des "sous-modules" mais de
l'infrastructure transverse et **n'ont pas bougé** : `Encryption`,
`Frontend`, `Locale`, `Mail`, `Menu`, `Migration`, `Module`,
`MountPoint`, `Notification`, `Repository`, `Scheduler`, `Sequence`,
`Storage`, `Support`, `Timestampable`, `Twig`, `Validation`.

## Procédure côté client

### 1. Mettre à jour le vendor

```bash
composer update axelraboit/aurora
# ou : make aurora-update
```

### 2. Renommer les dossiers d'extension (si présents)

Si vous étendiez `Agency` côté client, votre dossier d'extension passe de
`src/Module/Core/Agency/` à `src/Module/Core/Platform/Agency/` :

```bash
mkdir -p src/Module/Core/Platform
git mv src/Module/Core/Agency src/Module/Core/Platform/Agency

# Pareil pour User si vous étendiez User :
git mv src/Module/Core/User src/Module/Core/Platform/User
# etc.
```

### 3. Renommer les namespaces (sed bulk)

À lancer depuis la racine du projet client :

```bash
# Trouver tous les fichiers PHP qui référencent les anciens namespaces
grep -rl 'Aurora\\Core\\\(Dashboard\|Profile\|Search\|Audit\|Setting\|Theme\|User\|Agency\|Auth\)\\\|Aurora\\Core\\Media\\\|Aurora\\Core\\Service\\Entity' src tests config 2>/dev/null \
  | xargs sed -i \
    -e 's|Aurora\\Core\\Dashboard\\|Aurora\\Core\\General\\Dashboard\\|g' \
    -e 's|Aurora\\Core\\Profile\\|Aurora\\Core\\General\\Profile\\|g' \
    -e 's|Aurora\\Core\\Search\\|Aurora\\Core\\General\\Search\\|g' \
    -e 's|Aurora\\Core\\Audit\\|Aurora\\Core\\Dev\\Audit\\|g' \
    -e 's|Aurora\\Core\\Setting\\|Aurora\\Core\\Configuration\\Setting\\|g' \
    -e 's|Aurora\\Core\\Theme\\|Aurora\\Core\\Configuration\\Theme\\|g' \
    -e 's|Aurora\\Core\\Media\\|Aurora\\Core\\Media\\Library\\|g' \
    -e 's|Aurora\\Core\\User\\|Aurora\\Core\\Platform\\User\\|g' \
    -e 's|Aurora\\Core\\Agency\\|Aurora\\Core\\Platform\\Agency\\|g' \
    -e 's|Aurora\\Core\\Auth\\|Aurora\\Core\\Platform\\Auth\\|g' \
    -e 's|Aurora\\Core\\Service\\Entity\\|Aurora\\Core\\Platform\\Service\\Entity\\|g' \
    -e 's|Aurora\\Core\\Service\\Dto\\|Aurora\\Core\\Platform\\Service\\Dto\\|g' \
    -e 's|Aurora\\Core\\Service\\Manager\\|Aurora\\Core\\Platform\\Service\\Manager\\|g' \
    -e 's|Aurora\\Core\\Service\\Repository\\|Aurora\\Core\\Platform\\Service\\Repository\\|g' \
    -e 's|Aurora\\Core\\Service\\Serializer\\|Aurora\\Core\\Platform\\Service\\Serializer\\|g' \
    -e 's|Aurora\\Core\\Service\\Controller\\|Aurora\\Core\\Platform\\Service\\Controller\\|g' \
    -e 's|Aurora\\Core\\Service\\View\\|Aurora\\Core\\Platform\\Service\\View\\|g'
```

> ⚠️ **Pour les namespaces déclarés (`namespace Aurora\Core\Service\Entity;`)** : 
> Le sed ci-dessus rate les déclarations de namespace terminées par `;`
> pour les sous-namespaces Service (Entity/Dto/etc.). Si vous étendez
> l'entité Service, ajouter au sed une seconde passe ciblant exactement
> ces déclarations (cf. commit `a380781e` d'aurora-core pour le fix).

### 4. Re-générer l'autoload + vider le cache

```bash
composer dump-autoload
make cc
```

### 5. Valider

```bash
make stan      # doit être vert
make test      # doit être vert
make ft        # fix + test combiné
```

## Fichiers de config à vérifier manuellement

Si votre `config/services.yaml` ou `config/packages/*.yaml` référence des
classes Aurora directement (rare), le sed les a déjà couverts. Vérifier
par grep résiduel :

```bash
grep -rn 'Aurora\\Core\\\(Dashboard\|Profile\|Search\|Audit\|Setting\|Theme\|User\|Agency\|Auth\|Media\|Service\\\(Entity\|Dto\|Manager\|Repository\|Serializer\|Controller\|View\)\)' config/
# Doit être vide
```

## Outils Claude Code (skills) déjà mis à jour

Si vous utilisez les skills Aurora dans Claude Code, ils sont déjà à jour
côté vendor :
- `/extend-aurora-entity` génère vers les nouveaux paths
- `/add-entity` connaît la nouvelle hiérarchie
- `/check-extensibility` audit conforme à la nouvelle structure
- `/add-module` (nouveau) scaffolde sur la nouvelle convention
- `/add-submodule` (nouveau) ajoute des sous-features dans un module parent

## Justification de la décision

Voir [`decision_core_submodule_nesting.md`](../../.claude/memory/aurora-core/architecture/decision_core_submodule_nesting.md)
pour le raisonnement complet (discoverabilité, cohésion logique,
alignement avec Vault/Notes).
