# Extraire un module aurora-core vers un projet client dédié

Playbook générique pour sortir un module de aurora-core et le déposer
dans son propre projet client (template aurora-client + le module
extrait). Utile quand un module qui semblait générique s'avère
spécifique à un seul secteur métier — au point de polluer aurora-core
pour les autres clients.

## Quand extraire vs garder en core

Extraire si :

- Le module sert **un seul** usecase métier (vertical), pas un pattern transversal
- Il embarque des **dépendances lourdes** (JS deps, binaires CLI, services
  scheduler, sequence prefix, toggle dashboard) que les autres clients
  n'utilisent pas
- Il est devenu **un projet à part entière** avec ses propres sprints,
  son backlog, ses utilisateurs identifiés
- Tu vois **un client unique** consommer ce module à ~100%, pas une
  poignée de clients qui en consomment chacun ~20%

Garder en core si :

- Plusieurs clients réels (ou plausibles court terme) consomment le
  module
- Le module est principalement un **pattern d'extension** (Sylius-style)
  que les clients étendent légèrement chacun de leur côté
- Le coût d'extraction (1-2 jours) dépasse le coût de la maintenance
  partagée

> Référence concrète : la branche
> [`feat/extract-welding-to-client`](https://github.com/AxelRaboit/aurora-core/commits/feat/extract-welding-to-client)
> sur aurora-core porte l'extraction du module Welding (premier
> usage du playbook, mai 2026). Le commit `refactor(core): extract
> Welding module to aurora-welding client project` donne la diff
> complète et le projet [`aurora-welding`](https://github.com/AxelRaboit/aurora-welding)
> est le résultat.

---

## Playbook en 5 phases

### Phase 0 — Safety net

```bash
# Sur aurora-core
git checkout develop
git tag pre-<module>-extract                          # rollback facile
git checkout -b feat/extract-<module>-to-client       # branche dédiée
```

### Phase 1 — Dupliquer aurora-client vers le nouveau projet client

```bash
cp -r aurora-client/ <new-client>/
cd <new-client>/
rm -rf .git vendor node_modules var public/build public/bundles \
       public/uploads composer.lock .vite .cache \
       .env.local .env.test.local .php-cs-fixer.cache \
       .twig-cs-fixer.cache .phpunit.cache
git init -b develop
```

Adapter `composer.json` :

```json
{
    "name": "<owner>/<new-client>",
    "description": "<adapter>",
    "require": {
        "axelraboit/aurora": "dev-feat/extract-<module>-to-client"
    },
    "repositories": [
        {
            "type": "path",
            "url": "../aurora-core",
            "options": { "symlink": true }
        }
    ],
    "autoload-dev": {
        "psr-4": { "App\\Tests\\": "tests/" }
    }
}
```

> Le `path` repo + symlink permet d'itérer sans push/pull entre core et
> client pendant l'extraction. Repasser sur le VCS GitHub canonique
> (cf. Phase 5) quand l'extraction est stable.

### Phase 2 — Déplacer le module

```bash
# Code source
cp -r <aurora-core>/src/Module/<X>/ <new-client>/src/Module/<X>/

# Tests
cp -r <aurora-core>/tests/Unit/Module/<X>/ <new-client>/tests/Unit/Module/<X>/

# Tools si le module a un dossier dédié (ex: PDF gen, OCR, etc.)
cp -r <aurora-core>/tools/<x>/ <new-client>/tools/<x>/

# Documentation
cp -r <aurora-core>/docs/aurora-core/todo/<x>/ <new-client>/docs/<x>/

# Mémoires aurora-core dédiées au module
cp <aurora-core>/.claude/memory/aurora-core/**/pitfall_<x>_*.md \
   <new-client>/.claude/memory/<x>/
```

**Réécrire les namespaces PHP** (Python script ou sed) :

```
Aurora\Module\<X>      → App\Module\<X>
Aurora\Tests\Unit\Module\<X>  → App\Tests\Unit\Module\<X>
```

**Renommer les tables DB** dans les `#[ORM\Table]` + `#[ORM\SequenceGenerator]` :

```
core_<x>_*       → app_<x>_*
seq_core_<x>_*   → seq_app_<x>_*
```

**Supprimer les migrations historiques copiées** — sur le nouveau
client, elles seront re-générées proprement via `doctrine:migrations:diff`
en Phase 4 (un seul namespace, un seul fichier final).

**Adapter les imports JS** : si le module utilisait un alias
`@<x>/...` pour ses propres composables (alias core), les convertir en
imports relatifs. Le futur `@<x>/...` côté client sera resolved par
`jsconfig.json` mais l'alias core n'existera plus.

**Composer JS deps** : ajouter au `package.json` du client les deps
JS spécifiques au module (ex: `pdf-lib`, `pdfjs-dist` pour Welding).

### Phase 3 — Nettoyer aurora-core

Tout ce qui référence le module doit partir. Liste exhaustive
(grep `<X>` et `<x>` dans aurora-core) :

| Fichier | À nettoyer |
|---|---|
| `src/AuroraBundle.php` | `use` statements + entries `resolve_target_entities` du module |
| `src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php` | Cases du module (`<X>Backend`, `<X>SubFeature`, …) + labels + descriptions + `getCascadeRequires()` + `getParentCase()` |
| `src/Core/Scheduler/MessageHandler/<X>Handler.php` | Supprimer si dédié, ou retirer les bits du module |
| `src/Core/Sequence/SequencePrefixEnum.php` | Case `<X>...` |
| `aliases.js` | Alias `@<x>` |
| `package.json` | JS deps welding-specific (`pdf-lib` si Welding-only) — **ATTENTION** vérifier qu'elles ne sont pas utilisées par d'autres modules core (ex: `pdfjs-dist` reste pour Media's PdfThumbnail) |
| `src/Core/DataFixtures/DemoFixtures.php` | Méthodes `create<X>()` + call sites |
| `src/Core/assets/backend/sidemenu/composables/useSidemenuSectionTheme.js` | Theme key du module |
| `docs/aurora-core/todo/<x>/` | Déplacé vers le client |
| `docs/aurora-core/todo/README.md` + `module_roadmap.md` | Marquer le module comme "extrait vers `<new-client>`" |
| `.claude/memory/aurora-core/**/pitfall_<x>_*.md` | Déplacé vers le client |
| `.claude/memory/aurora-core/backend/MEMORY.md` | Retirer les liens vers les pitfalls déplacés |
| `migrations/Version<YYYYMMDD>_<x>_*.php` | Migrations historiques du module (création + alterations) |

Et :

```bash
rm -rf src/Module/<X>/  tests/Unit/Module/<X>/  tools/<x>/ \
       docs/aurora-core/todo/<x>/
```

**Créer un mémo d'extraction** : `docs/aurora-core/dev/extraction_<x>_to_client.md`
documentant *pourquoi* et *comment* l'extraction a eu lieu (référence
au commit série + lien vers le nouveau client repo).

### Phase 4 — Câbler le module dans le nouveau client

Le pattern d'extension Aurora reste en place — le module devient juste
un module client comme un autre.

**`config/packages/doctrine.yaml`** : ajouter `resolve_target_entities` pour
toutes les entités du module :

```yaml
doctrine:
    orm:
        resolve_target_entities:
            App\Module\<X>\Entity\<X>FooInterface: App\Module\<X>\Entity\<X>Foo
            # … une ligne par entité
```

**`config/packages/twig.yaml`** : namespace Twig client :

```yaml
twig:
    paths:
        '%kernel.project_dir%/src/Module/<X>/templates': '<X>'
```

**`config/services.yaml`** : ajouter la source des traductions à
`DumpJsTranslationsCommand` :

```yaml
Aurora\Module\Configuration\Setting\Command\DumpJsTranslationsCommand:
    arguments:
        $extraSourceDirs:
            - '%kernel.project_dir%/src/Module/<X>/translations'
```

**Adapter le module** :

- Le `<X>Module.php` ne peut plus type-hint sur des `ModuleParameterEnum` cases
  qu'on a supprimés du core. Soit retirer le toggle (always-on), soit
  créer un `App\Setting\<X>ModuleParameterEnum` côté client
- Le `<X>Context` qui interrogeait `ModuleAccessChecker` doit être ajusté
- Les services qui utilisaient `SequencePrefixEnum::<X>` doivent
  hardcoder la valeur (ou créer un `App\Sequence\<X>PrefixEnum`)

**Recréer le handler de scheduler** : si aurora-core avait un
`<X>CleanTempFilesHandler` qu'on vient de retirer, le recréer dans le
client comme `App\Module\<X>\Scheduler\<X>CleanTempFilesHandler` qui
subscribe au `CleanTempFilesMessage` d'aurora-core.

**Setup composer + DB** :

```bash
composer install
pnpm install
(cd vendor/axelraboit/aurora && pnpm install)
make db-create
# Setup DB fresh — workaround multi-namespace migrations
php bin/console doctrine:schema:create
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
php bin/console doctrine:migrations:version --add --all --no-interaction
make build
```

### Phase 5 — Verification + finaliser

Côté **aurora-core** :

```bash
php bin/phpunit                          # tous les tests verts (les tests du module sont partis)
php bin/console doctrine:schema:validate # mapping OK
npm run build                            # build OK
```

Côté **nouveau client** :

```bash
php vendor/bin/phpunit                   # tests du module verts
php bin/console doctrine:schema:validate # mapping OK
make build                               # build OK + le module est dans les chunks
```

**Bascule `path` → `vcs` du client** une fois stable. Dans `composer.json`
du client :

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:<owner>/aurora-core.git"
    }
]
```

Puis `composer update axelraboit/aurora` pour repointer vendor sur la
release officielle.

**Merger** `feat/extract-<X>-to-client` → `develop` sur aurora-core et
**pusher** le nouveau client sur son propre repo GitHub.

---

## Pièges connus

- **Mauvaise estimation du périmètre** : un grep `<X>` dans aurora-core
  remonte souvent des refs cachées (vue components, JS deps, fixtures
  partielles, theme keys, doc cross-refs). Faire un grep `-rln` avant
  de commencer pour avoir une vue exhaustive.
- **Migrations en cascade** : les migrations qui créaient les tables
  `core_<x>_*` ont peut-être été *modifiées* par d'autres migrations
  ultérieures (renames, FK adds). Lister TOUTES les migrations qui
  touchent ces tables avant de décider quoi supprimer côté core.
- **JS deps partagées** : avant de retirer `pdf-lib` / `pdfjs-dist` / autre
  de `package.json` core, grep le reste du code pour s'assurer qu'aucun
  autre module ne les utilise (cf. cas `pdfjs-dist` resté en core pour
  Media's PdfThumbnail malgré l'extraction de Welding).
- **Symlinks `public/build` du client** : le client cible est dupliqué
  d'aurora-client donc le symlink `public/build` y est *normalement*
  préservé. Vérifier avec `ls -la public/build` après duplication. Si
  perdu, recréer : `ln -s ../vendor/axelraboit/aurora/public/build public/build`.
- **CI du nouveau client** : le workflow `.github/workflows/ci.yml` du
  template hérité fonctionne tel quel **si** aurora-core est public.
  Si aurora-core est privé chez vous, configurer le PAT
  `AURORA_CORE_READ_TOKEN` (cf. [`../../aurora-client/deployment/github_actions_ci.md`](../../aurora-client/deployment/github_actions_ci.md) §Annexe).
- **Retirer un module client** : si le projet hérité contient un module dont
  le nouveau client ne se sert pas (exemple reconstruit depuis la doc, reliquat
  d'un ancien template), le retirer en suivant la checklist de
  [`../../aurora-client/getting-started/setup.md`](../../aurora-client/getting-started/setup.md)
  §"Checklist — retirer un module client".
