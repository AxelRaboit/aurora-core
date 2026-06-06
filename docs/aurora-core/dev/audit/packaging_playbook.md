# Playbook de packaging Composer

> Comment le monorepo (`axelraboit/aurora`) est splitté en N packages. Le split
> est **réalisé** (`bin/split-modules.sh` produit les 13 packages, branches
> `master`) ; ce doc garde l'anatomie d'un package + les findings. Voir
> `decoupling_strategy.md` (le découplage) et, côté client,
> `../../../aurora-client/getting-started/installing_modules.md` (l'adoption).

## 0. Câblage services per-package : testable DANS le monorepo (corrigé 2026-05-30)

**Conclusion antérieure (erronée)** : « le services-per-package ne se teste
qu'au split réel ». Issue de l'échec `#[AutoconfigureTag]` →
`merge() does not support merging autoconfiguration`.

**Correction (validé end-to-end)** : ce conflit est
spécifique à l'autoconfiguration **globale** (`registerForAutoconfiguration`,
ce que déclenche `#[AutoconfigureTag]`). Un `instanceof()` déclaré dans le
**`config/services.php` d'un bundle** est *file-scoped* → **aucun conflit**,
même avec le `_instanceof` central encore présent. Donc chaque module embarque
son `config/services.php` (load + `instanceof` local + son exclusion du glob
central), et **on le valide dans le monorepo** (`cache:clear`, `lint:container`,
tags présents, tests verts) **avant** de créer le moindre repo enfant. Seule
l'**install composer réelle** dans un client neuf attend l'infra (repos +
Packagist). Le monorepo garde `Aurora\: resource '../src/'` + `_instanceof`
central pour tout le code **non encore** muni de son `services.php`.

## 1. Cible (rappel)

13 packages en étoile (tous → `aurora-core`) :

| Package | Contenu | Subtree splitsh |
|---|---|---|
| `axelraboit/aurora-core` | `Core/` + Platform + Configuration + Dev + Ged + General | (le reste, hors `src/Module/<extraits>`) |
| `axelraboit/aurora-commerce` | Ecommerce + Erp | `src/Module/Ecommerce` + `src/Module/Erp` |
| `axelraboit/aurora-crm` | Crm | `src/Module/Crm` |
| `axelraboit/aurora-billing` | Billing | `src/Module/Billing` |
| `axelraboit/aurora-editorial` | Editorial | `src/Module/Editorial` |
| `axelraboit/aurora-photo` | Photo | `src/Module/Photo` |
| `axelraboit/aurora-project` | Project | `src/Module/Project` |
| `axelraboit/aurora-hr` | Hr | `src/Module/Hr` |
| `axelraboit/aurora-notes` | Notes | `src/Module/Notes` |
| `axelraboit/aurora-personal-finance` | PersonalFinance | `src/Module/PersonalFinance` |
| `axelraboit/aurora-planning` | Planning | `src/Module/Planning` |
| `axelraboit/aurora-tools` | Tools | `src/Module/Tools` |
| `axelraboit/aurora-assistant` | Assistant | `src/Module/Assistant` |

> `aurora-commerce` regroupe **2 sous-dossiers** (Ecommerce+Erp) → splitsh
> splitte les 2 prefixes vers des sous-dossiers `Ecommerce/` + `Erp/` du repo
> enfant ; le `composer.json` racine mappe `Aurora\Module\Ecommerce\: Ecommerce/`
> + `Aurora\Module\Erp\: Erp/`. **Validé in-monorepo** : un **seul**
> `Ecommerce/config/services.php` charge les 2 namespaces (les contrôleurs
> Ecommerce autowire le `ProductRepository` concret d'Erp → un services.php par
> module casserait avec « type excluded »). `AuroraErpBundle` ne ship pas de
> services.php. Ce `composer.json` racine est un artefact de split (il ne peut
> pas vivre dans `src/Module/Ecommerce/` du monorepo).

> **État (2026-05-30)** : les **13 modules** ont leur `composer.json` +
> `config/services.php` in-monorepo et sont exclus du glob central. `aurora-tools`
> est splitté + installé pour de vrai. Les 11 autres : repos GitHub + publish
> restants (hors-code).

## 2. Anatomie d'un package module (ex. `aurora-tools`)

Le subtree `src/Module/Tools/` doit devenir un package autonome. Y ajouter
(dans le monorepo, sous `src/Module/Tools/`, pour que splitsh les emporte) :

```
src/Module/Tools/
├── composer.json            ← (nouveau) manifeste du package
├── config/
│   └── services.php         ← (nouveau) services + instanceof local (routes.php INUTILE)
├── AuroraToolsBundle.php     ← (existe) auto-enregistre Doctrine/Twig/i18n/RTE
├── Setting/
│   ├── ToolsModuleParameterEnum.php       ← (existe) toggles du module
│   └── ToolsModuleParameterProvider.php   ← (existe) settings (évite le wipe)
├── Vault/ … PasswordGenerator/ …          ← (existe) le code métier
├── translations/  templates/  assets/     ← (existe)
└── tests/                   ← (à déplacer) les tests du module
```

### 2.1 `composer.json` (template)

```json
{
    "name": "axelraboit/aurora-tools",
    "description": "Tools module (Vault, PasswordGenerator) for Aurora.",
    "type": "symfony-bundle",
    "license": "proprietary",
    "require": {
        "php": ">=8.4",
        "axelraboit/aurora-core": "self.version"
    },
    "autoload": {
        "psr-4": { "Aurora\\Module\\Tools\\": "" }
    },
    "extra": {
        "symfony": { "bundle": "Aurora\\Module\\Tools\\AuroraToolsBundle" }
    }
}
```

> `"Aurora\\Module\\Tools\\": ""` car splitsh extrait `src/Module/Tools/*` à la
> racine du repo enfant. À **valider au POC** (le mapping PSR-4 root est le
> point le plus à risque ; alternative : restructurer le subtree sous `src/`).

### 2.2 `config/services.php` (le bout que le monorepo ne peut pas activer)

Quand le package est seul, ceci ne crée **aucun** conflit de merge :

```php
return static function (ContainerConfigurator $c): void {
    $services = $c->services()->defaults()->autowire()->autoconfigure();
    $services->load('Aurora\\Module\\Tools\\', '../')
        ->exclude(['../{Setting/*Enum.php,*/Entity,*Bundle.php}']);
    // _instanceof local POUR LES interfaces que CE module implémente
    // (ConfigurationTabProvider, ApplicationParameterProvider, …) :
    $services->instanceof(ApplicationParameterProviderInterface::class)
        ->tag('aurora.application_parameter_provider');
    // … (uniquement les tags pertinents pour Tools)
};
```

Le `AuroraToolsBundle::loadExtension()` importe ce fichier. **Côté `aurora-core`**,
au split, retirer Tools du glob central (déjà simulé par `$extractedModules`).

### 2.3 `config/routes.php` — dépend du routing de l'app cliente (nuance install réelle)

**Cas service-based** (`routing.controllers` dans `config/routes.yaml`) : pas de
`routes.php` nécessaire — le loader découvre les contrôleurs via leur
enregistrement comme services (faits par le `services.php` du module). Validé en
monorepo.

**Cas directory-scanning** (ce que fait `aurora-client` réel) : le client liste
des `resource: '../vendor/axelraboit/aurora/src/'` `type: attribute`. Ce scan est
**path-based** → il ne voit PAS un module dans un autre package. Il faut alors
**une entrée par package extrait** dans le `config/routes.yaml` du client :

```yaml
aurora_tools:
    resource: '../vendor/axelraboit/aurora-tools/'
    type: attribute
```

(Validé : sans cette entrée, `debug:router` ne montre aucune route `backend_tools_*` ;
avec, toutes résolvent.) C'est une étape de **migration côté client** à documenter.

## 3. Outillage split

- **`splitsh/lite`** (binaire/Docker) — utilisé par Symfony. Rapide, splitte
  l'historique git d'un sous-dossier.
- Alternative : `symplify/monorepo-builder` (Sylius) — plus de features
  (versioning synchronisé, `merge`), plus lourd.

Config indicative (`splitsh` via script, ou `monorepo-builder.php`) :

```
src/Module/Tools         → git@github.com:axelraboit/aurora-tools.git
src/Module/Notes         → git@github.com:axelraboit/aurora-notes.git
src/Module/Ecommerce+Erp → git@github.com:axelraboit/aurora-commerce.git
…
(le reste)               → git@github.com:axelraboit/aurora-core.git
```

Commande type (splitsh-lite) :
```bash
splitsh-lite --prefix=src/Module/Tools --target=heads/split-tools
git push aurora-tools split-tools:main
```

> **Substitut validé** (splitsh-lite absent localement) : `git subtree split
> --prefix=src/Module/Tools -b split-aurora-tools` produit le même arbre racine
> (composer.json + bundle + config à la racine → PSR-4 `""` correct). Plus lent
> (rejoue l'historique) mais sans dépendance binaire. Suffisant pour le POC ;
> `splitsh-lite` reste reco pour le rollout (vitesse + idempotence des hashes).

**Runbook au quotidien** — `bin/split-modules.sh` (subtree split + `push -f` par
URL, aucun remote git permanent), wrappé par le Makefile :

```bash
make split-module REPO=aurora-crm        # un module précis
make split-module REPO=aurora-commerce   # Ecommerce + Erp combinés
make split-modules                       # les 12 packages d'un coup
```

Pousse ce qui est **committé** (pas le working tree) sur la branche `master` du
repo cible ; les repos GitHub doivent exister au préalable.

## 4. Ordre d'extraction (du plus simple au plus dur)

1. **POC** : `aurora-tools` (leaf pur, petit) — ✅ **fait** (2026-05-30) :
   composer.json, services.php + tags, PSR-4 root, subtree split, tests verts —
   tout validé **dans le monorepo**. Reste ☐ l'install réelle dans un
   `aurora-client` neuf (bloquée par l'infra repos/Packagist, pas le code).
2. Leaves : Hr, Planning, Notes, PersonalFinance, Assistant.
3. Soft-ref : Photo, Editorial, Crm, Billing, Project.
4. Fusion : Commerce (Ecommerce+Erp) en dernier.

## 5. Validation par package (critères Phase 9.3)

Pour chaque package extrait :
- [ ] `composer require axelraboit/aurora-<x>` dans un `aurora-client` neuf.
- [ ] `php bin/console cache:clear` OK (bundle s'enregistre seul).
- [ ] `doctrine:schema:validate` OK ; `debug:router` montre les routes du module.
- [ ] `app:application-parameter` **ne wipe pas** les settings du module (provider présent).
- [ ] Toggles visibles dans `/dev/dashboard/modules` (registry).
- [ ] Tests du package verts en isolation.
- [ ] Extension Sylius-style depuis le client OK (étendre une entité, override un manager).
- [ ] Build Vite OK (selon stratégie assets — Gate 2, encore ouvert).

## 6. Migrations (Phase 5)

Les 3 migrations soft-ref (`down()` re-crée des FK CRM) ne sont valides que si
Crm installé. Choix à figer **avant** le rollout :
- (reco) **migrations côté client** : le client orchestre le schéma de tous ses
  packages installés ; chaque package fournit ses migrations, le client les joue.
- ou : repartir d'un schéma propre par package (perte d'historique).

## 7. Transition clients existants (Phase 11)

Reco = **Option C** : méta-package `axelraboit/aurora` marqué `deprecated`,
`require` tous les sous-packages en v2.0 pendant 1-2 versions, puis hard-cut.
Pattern Symfony.

## 8. Prérequis infra (décisions hors-code)

- [ ] Installer `splitsh-lite` (binaire ou Docker) — **absent localement**.
- [ ] Créer les repos GitHub enfants (`aurora-tools`, …) OU script de création.
- [ ] Accès Packagist / repo Composer privé pour publier.
- [ ] CI : pipeline par package (ou monorepo CI qui split + push à chaque tag).

## 9. Stratégie assets Vue (Gate 2 — ✅ TRANCHÉ : option B)

**Décision (2026-05-30)** : option **B** (glob étendu au vendor). aurora-core
ship `vite-plugin-aurora-modules.js` : en mode vendored (le `packageDir` est
sous un dossier `vendor/`), il scanne les packages siblings
`vendor/axelraboit/aurora-*` (hors `aurora-core`), collecte leurs
`assets/**/*.vue` et les expose via le module virtuel
`virtual:aurora-vendor-modules`, spread dans `app.js` entre les modules aurora
et les modules client (le client garde la priorité d'override). `fs.allow`
inclut le dossier parent ; `dedupe` (vue/vue-i18n/…) gère le version-skew.

**Finding** : un `import.meta.glob` relatif statique (`../../../../aurora-*`)
est inutilisable — il collisionne en dev (le parent du monorepo contient
`aurora-client`/`aurora-core`). D'où le plugin qui **détecte le mode** et
génère un module virtuel (no-op en monorepo).

**Validé** : build monorepo vert (plugin no-op, VaultApp toujours bundlé via
`src/Module`), découverte vendored unit-testée (clés `./tools/...`,
`./personalfinance/...` tirets aplatis, `aurora-core` exclu, `node_modules`
sauté), `lint-js` + 860 tests JS verts. Mapping **identique** à celui du
monorepo → `vue_component('tools/backend/vault/VaultApp')` résout pareil en dev
et en vendored. **Reste** : valider l'install réelle (client avec aurora-core +
aurora-tools vendored séparément).

---

**TL;DR** : le découplage est fini. Le packaging réel = boucle « ajouter
composer.json + config/{services,routes} au subtree → splitsh → install dans un
client neuf → valider » package par package, en commençant par `aurora-tools`.
Prérequis bloquants restants : **splitsh installé**, **repos GitHub**, et le
**Gate 2 assets** pour les packages avec front.
