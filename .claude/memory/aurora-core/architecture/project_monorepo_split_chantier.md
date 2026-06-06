# Chantier — Split aurora-core en monorepo de N packages Composer

## Règle

Transformation d'`axelraboit/aurora` (mono-package) en **monorepo Composer** :
1 package core (`aurora-core`) + 12 packages module, distribués en étoile.
Le développement reste mono-codebase ; seule la distribution Composer est
splittée. Pattern Symfony / Doctrine / Sylius / Laravel.

**Statut (2026-05-30)** : ✅ **CHANTIER TERMINÉ et validé end-to-end**. 13
packages sur GitHub (branches `master`, `dev-master`), install à la carte prouvée
(client réel, 12 modules, build vert, symfony 7.4), adoption turnkey
(auto-découverte bundles/routes/assets/boot + kit `.claude/client_template/`).
Découplage complet (graphe étoile + cat-F). Détail dans la table « État » + les
findings ci-dessous.

> **Outillage aligné (2026-05-31)** : les skills de scaffolding `/add-module`,
> `/register-module-toggle`, `/audit-module-toggles`, `/add-submodule` + la doc
> `docs/aurora-core/dev/add_module.md` génèrent/attendent désormais la forme
> packagée (per-module `<Module>ModuleParameterEnum` + provider +
> `Aurora<Module>Bundle` + `composer.json`/`config/services.php`, bundle
> enregistré dans `bundles.php` + module exclu du glob central). Avant ça ils
> patchaient encore l'enum `ModuleParameterEnum` central (monde monolithique).
> Cf. [[architecture_module_parameter_enum]].

> Les docs de **planning** du chantier (audits 9/11 phases, workplan J0-J6,
> snapshots inventory/dependency_graph/baseline/coupling/package_layout,
> compte-rendu POC) ont été **supprimés** une fois le chantier terminé (voir git
> si besoin) — la rationale durable vit dans cette mémoire + les 3 docs vivants.

## Pourquoi

- Le user veut pouvoir proposer un **aurora "léger"** : un client qui ne
  fait que du CMS n'a pas besoin d'embarquer Billing/CRM/Ecommerce/ERP.
- Le système de **module toggles** existant cache l'UI mais n'allège pas
  l'install (vendor reste plein de code mort). Composer sub-packages
  résout cette dimension-là.
- À l'inverse, un fork multi-repos = piège classique (double maintenance,
  divergence, double CI). Le monorepo + split automatique (`splitsh/lite`)
  donne le meilleur des deux mondes.

## Docs vivants (la planning a été supprimée)

- **[`decoupling_strategy.md`](../../../../docs/aurora-core/dev/audit/decoupling_strategy.md)** —
  le **pourquoi** : graphe en étoile, taxonomie cat A-E, fusion commerce.
- **[`packaging_playbook.md`](../../../../docs/aurora-core/dev/audit/packaging_playbook.md)** —
  le **comment** : anatomie d'un package + findings ; runbook = `bin/split-modules.sh`
  (wrappé : `make split-module REPO=aurora-crm` pour un module, `make split-modules`
  pour tous).
- **[`installing_modules.md`](../../../../docs/aurora-client/getting-started/installing_modules.md)** —
  l'**adoption client** à la carte + kit `.claude/client_template/`.

**Migration clients existants** : pas de migration forcée. Un client monolithique
reste sur `axelraboit/aurora: dev-develop` (le monorepo contient tous les
modules) ; un client léger part en à la carte (`split/core` + modules choisis).
Le méta-package de transition n'a de sens qu'avec Packagist (cf. décision « pas
de Packagist » : install VCS, repos pré-listés dans le template).

## État du chantier

| Date | Jalon | État |
|---|---|---|
| 2026-05-30 | J0 — Préparation | ✅ Fait (branche `feat/monorepo-audit`, tag `pre-monorepo-audit`, dossier `docs/aurora-core/dev/audit/`, baseline) |
| 2026-05-30 | J1 — Cartographie commune | ✅ Fait (3 livrables posés, voir ci-dessous) |
| 2026-05-30 | Gate 1 — Décision groupings | ✅ **TRANCHÉ : graphe en étoile** (aucune dép. latérale ; 1 seule fusion Ecommerce+Erp). Voir `audit/decoupling_strategy.md`. |
| 2026-05-30 | **J1.5 — Pass de découplage (PRÉREQUIS)** | ✅ **TERMINÉ** — cat. A/B/C/D/E toutes faites. Invariant atteint : tous les modules métier ne dépendent que du core (sauf Ecommerce↔Erp = intra-package `aurora-commerce`). cat. D : soft-ref via `EntityReferenceResolver` core (Billing/Photo/Project→Crm + migrations DB) ; Project→Billing relocalisé client. |
| 2026-05-30 | **J3 — Bundling tous modules** | ✅ **TERMINÉ** — **13 `Aurora<X>Bundle`** (tous les modules métier) via `AbstractAuroraModuleBundle`. `AuroraBundle` = bundle **core pur** (16 RTE Platform/Config/Dev/Ged). `bundles.php` : 1 ligne = 1 module on/off. Suite verte 2747 à chaque étape. |
| 2026-05-30 | **ModuleParameterEnum extensible** | ✅ **TERMINÉ** (mécanisme + distribution). Consommateurs registry-driven (`SettingsService` cascade, `ModulesViewBuilder`, `UsersViewBuilder`) ; `ModuleToggle.displayParentKey` (structurel ≠ `parentKey` cascade) + `getDisplayTopLevel()`/`getDisplayChildrenOf()`. **Distribution** : les 13 modules métier ont chacun leur `<Module>ModuleParameterEnum` + `<Module>ModuleParameterProvider` (settings préservés, tous tagués) ; central `ModuleParameterEnum` = **17 cases core only** (General/Platform/Configuration/Media/Ged). Consommateurs cross-module (DashboardViewBuilder, MenuRenderer) → clés string. Tests migrés (per-module + 5 cross-cutting réécrits sur cases core). Fait via sous-agents parallèles (template Notes). Commits `3ba05725`, `ec930116`. Suite verte 2744. ⚠️ `<Module>Context::isEnabled` prend la string `->value` (ModuleAccessChecker accepte `ModuleParameterEnum|string`). |
| — | J2 — Audit technique parallélisé | Bloqué (J1.5) |
| 2026-05-30 | **Gate 2 — Stratégie assets Vue** | ✅ **TRANCHÉ : option B** (glob étendu au vendor). aurora-core ship un **plugin Vite** qui, en mode vendored, découvre les packages `vendor/axelraboit/aurora-*` (hors core) et expose leurs `assets/**/*.vue` via un module virtuel. **Finding** : un glob relatif naïf (`../../../../aurora-*`) **collisionne en dev** (le parent du monorepo contient `aurora-client`/`aurora-core`) → l'implémentation **doit** détecter le mode (`__dirname` contient `/vendor/`) et générer un module virtuel, pas un `import.meta.glob` statique relatif. `dedupe` (vue/vue-i18n/…) déjà en place gère le version-skew des deps partagées. |
| 2026-05-30 | **J3 — POC + rollout bundles (8 leaves)** | ✅ **FAIT** — `AbstractAuroraModuleBundle` (core) + **8 `Aurora<X>Bundle`** (Tools, Assistant, Crm, Editorial, Hr, Notes, PersonalFinance, Planning). AuroraBundle les exclut tous (RTE+use retirés) et ne pilote plus que Core + 5 modules couplés. Preuve toggle bundle = module on/off. 189 entités mappées, 2747 tests verts. Reste pour package Composer complet : composer.json + services/routes embarqués + `ModuleParameterEnum` extensible + splitsh. |
| — | Gate 3 — Go / No-Go final | de facto **Go** (chantier mené jusqu'au bout in-monorepo) |
| 2026-05-30 | **J4 — Planification packaging** | ✅ **Playbook posé** (`audit/packaging_playbook.md`) : anatomie d'un package (composer.json + `config/services.php` dans le subtree ; routes.php INUTILE), templates, splitsh, ordre (Tools POC d'abord), validation Phase 9.3, migrations côté client, transition Option C. |
| 2026-05-30 | **J5 — POC packaging `aurora-tools` end-to-end** | ✅ **FAIT (sauf install réelle, bloquée infra)**. **Finding clé** : `instanceof()` dans le `config/services.php` d'un bundle est *file-scoped* → **aucun** conflit de merge avec le `_instanceof` central (contrairement à `#[AutoconfigureTag]` global). Donc **le câblage services/tags par package se valide DANS le monorepo**, package par package — ça dé-risque tout le chantier (la conclusion J4 antérieure « seulement au split réel » était fausse). Monté : `Tools/composer.json` (PSR-4 `Aurora\Module\Tools\: ""`), `Tools/config/services.php` (load + 2 `instanceof` locaux), `AbstractAuroraModuleBundle::loadExtension()` importe le services.php si présent, exclusion de Tools du glob central. Vérifs : cache:clear test+dev, lint:container, tags présents, **2744 tests verts**. **Routes** : pas de routes.php (le loader `routing.controllers` découvre les contrôleurs via leur enregistrement service). **Split** : `git subtree split` (substitut splitsh-lite absent) → composer.json+bundle+config à la racine, PSR-4 `""` correct. |
| 2026-05-30 | **J5 — Install RÉELLE à la carte validée (aurora-tools)** | ✅ **FAIT end-to-end sur 2 vrais repos GitHub**. Repo `aurora-tools` (subtree split poussé) + branche `split/core-no-tools` sur le repo core (= develop moins `src/Module/Tools`, package toujours `axelraboit/aurora`). `aurora-client` câblé en VCS (`axelraboit/aurora: dev-split/core-no-tools` + `axelraboit/aurora-tools: dev-master`). **Résultat** : `composer install` OK, `cache:clear` OK, `make build` OK avec **VaultApp + 14 composants Vault bundlés DEPUIS `vendor/axelraboit/aurora-tools`** (le core n'a plus Tools → preuve Gate 2 B en vrai), `ToolsModule` tagué `aurora.module` (finding services.php confirmé en install réelle), entités Vault mappées, **routes `backend_tools_*` résolues**, `doctrine:schema:validate` OK. Findings ci-dessous. |
| 2026-05-30 | **J5b — Template généralisé aux 13 modules (in-monorepo)** | ✅ **FAIT**. Les 13 modules ont chacun `composer.json` + `config/services.php` (load + `instanceof` file-scoped) et sont **exclus du glob central**. Defs d'args spéciales déplacées du central vers le services.php du module (Editorial MenuLocationRegistry, Billing OCR×2, Assistant LLM×4, Ecommerce Stripe, Photo GalleryAccess). **Merge `aurora-commerce`** : Ecommerce+Erp = 1 seul `Ecommerce/config/services.php` chargeant **les 2 namespaces** (les contrôleurs Ecommerce autowire le `ProductRepository` concret d'Erp). Validé : cache:clear + lint:container OK, tags intacts (module 18, param 15, dashboard 6, front 4, block 1), **2744 tests verts**. Reste hors-code : repos GitHub (11 autres) + Packagist. |

### 🔑 Finding merge / exclusion (2026-05-30)

Exclure un module du glob central **alors qu'un service chargé centralement
autowire sa classe concrète** → `Cannot autowire ... type has been excluded in
config/services.yaml`. Cas réel : `Ecommerce\...\ListingsController`
(central) → `Erp\Product\Repository\ProductRepository` (Erp exclu). **Fix** :
ne jamais splitter séparément deux modules couplés par classe concrète → les
**fusionner** (un `services.php` qui charge les 2 namespaces, autowiring
intra-fichier). C'est exactement la justification du package `aurora-commerce`
(seule fusion du graphe en étoile). Les 11 autres modules sont des leaves/soft-ref
sans couplage concret → split séparé OK.

### 🔑 Findings install réelle (2026-05-30)

1. **Gate 2 B = 2 pièces** : (a) `vite-plugin-aurora-modules.js` (découverte des `vendor/axelraboit/aurora-*`), **+** (b) `aliases.js` rendu *vendored-aware* (`moduleAlias` fallback sur `../aurora-<kebab>/assets` quand `src/Module/<X>/assets` absent) — sinon les imports intra-module `@tools/...` cassent. Les deux shippées dans aurora-core.
2. **Routing — nuance du finding « routes.php inutile »** : vrai seulement pour le routing **service-based** (`routing.controllers`). `aurora-client` fait du **directory attribute scanning** (`resource: '../vendor/axelraboit/aurora/src/'`) → il faut **une entrée routing par package extrait** (`resource: '../vendor/axelraboit/aurora-tools/'`). Migration client documentée.
3. **Migration client du bundle-split** : `config/bundles.php` du client doit enregistrer les **13 bundles modules** (avant, archi monolithique = `AuroraBundle` seul). 1 ligne = 1 module on/off.
4. **Composer VCS = 1 nom de package par repo** (celui de la branche par défaut). D'où POC en `axelraboit/aurora` partout ; le rename `aurora-core` exigera un **repo dédié** (rollout).
5. **Symfony 7.4 sans Flex** : le sandbox ne joint pas les recipes Flex (404) → `composer update -W --no-plugins` fait dériver les **transitifs** symfony en 8.x (aurora épingle ses ~25 deps directes en `7.4.*`, mais pas les transitifs string/process/security-core/… que seul le pin global `extra.symfony.require` de Flex couvre). **Solution validée** : restaurer le `composer.lock` 7.4 (`git checkout HEAD -- composer.lock`) + **update ciblé sans `-w`** (`composer update axelraboit/aurora axelraboit/aurora-tools --no-plugins`) → ne re-résout que les 2 packages nommés, les transitifs restent verrouillés en 7.4. Résultat final validé : **stack full symfony 7.4** (seul `pentatrion/vite-bundle v8.x` = versioning propre du bundle, pas une dérive), boot + build + Vault depuis le vendor OK.
| 2026-05-30 | **J6 — Rollout : 11 repos splittés + poussés** | ✅ **FAIT**. `bin/split-modules.sh` a splitté + poussé les 11 packages restants vers leurs repos GitHub : aurora-{crm,billing,editorial,photo,project,hr,notes,personal-finance,planning,assistant} (single-prefix, historique préservé) + **aurora-commerce** (Ecommerce+Erp combinés sous `Ecommerce/`+`Erp/` + composer.json racine, snapshot). Avec aurora-tools (déjà fait) + aurora-core (le monorepo) = **les 13 packages existent sur GitHub**. |
| 2026-05-30 | **Validation RÉELLE multi-modules (12 packages, build vert)** | ✅ **FAIT end-to-end**. Branche `split/core` (aurora-core core-only, sans les 13 modules) + **12 packages modules** installés en VCS dans `aurora-client`. Résultat : `composer install` (**symfony 7.4 tenu** via update ciblé sans `-w`), `cache:clear` OK, **442 routes** + entités + bundles **auto-découverts**, `make build` **VERT** avec la Vue des 12 modules bundlée depuis leurs packages vendored (310 chunks : editorial 19, personal-finance 16, tools 15, notes 11, commerce 10, …). **Bugs trouvés + corrigés** : (a) merge commerce — `@ecommerce`/`@erp` → `aurora-commerce/{Ecommerce,Erp}/assets` + clés plugin par sous-dossier ; (b) **dedupe-all** des deps aurora-core (un module vendored important `vue-draggable-plus` ne résolvait pas — Rolldown ne remonte pas jusqu'au node_modules d'aurora-core). **Findings cat-F** (couplage app↔module dans le core) — ✅ **3 découplés + 1 géré** : (1) **MainSchedule** → registry `RecurringMessageProviderInterface` (tag `aurora.recurring_message_provider`), chaque module ship son provider (`1502d038`) ; (2) **messenger routing** Billing → `AuroraBillingBundle::prependExtension` (`1502d038`) ; (3) **tabRegistry.js** Vue → **boot hooks par module** : `app.js` eager-globe `**/assets/**/*.register.js` (monorepo+client) + module virtuel `virtual:aurora-vendor-boot` (vendored), Assistant ship `assistantSettingsTab.register.js` qui self-register son onglet ; core n'importe plus `@assistant` (`3388cab4`). Mécanisme réutilisable pour toute registration de module au boot. (4) **DataFixtures** (92 refs) = **données démo app-level** (require-dev, `use` lazy) → **pas du code lib** ; retirées du package au split (`split/core`), relocalisation per-module hors scope (faible valeur). |
| 2026-05-30 | **Adoption client turnkey (sans Packagist)** | ✅ **FAIT**. Décision : **pas de Packagist**, install VCS. Pour réduire le boilerplate par module : `AuroraModuleBundles::all($projectDir)` (scanne `vendor/axelraboit/aurora-*/composer.json` → `extra.aurora.bundles`\|`extra.symfony.bundle`, le client spread dans `bundles.php`) + `AuroraModuleRouteLoader` (`type: aurora_modules`, 1 entrée routing importe les contrôleurs de tous les packages installés). Assets Vue déjà auto (Gate 2 B). Donc adoption = `repositories` VCS + `require` ; bundles/routes/assets **auto-découverts**. Guide : `docs/aurora-client/getting-started/installing_modules.md`. Reste : méta-package de transition (quand bascule des clients existants). |

### 🔑 Décision Gate 1 — graphe en étoile (2026-05-30)

**Invariant** : un module distribuable n'importe JAMAIS un autre module
distribuable (seulement `aurora-core` = Core+Platform+Configuration+Dev+Ged).
Inséparable ⇒ **fusion**, pas de bridge ni `require` latéral. C'est le choix
« penser long terme » (CLAUDE.md §3bis) : zéro dette de couplage dans la
topologie. Remplace l'option bridges/require initiale.

**Taxonomie des arêtes** (toutes auditées, cf. `decoupling_strategy.md`) :
- **A** value enum partagé → remonter en core (`CurrencyEnum`,
  `EcommerceSettingEnum`) — casse le seul cycle.
- **B** event cross-module → contrat d'event core (2 listeners Crm).
- **C** embed/agrégation → registry de providers core (Editorial blocks +
  **General** dashboard/search — sinon core dépendrait des modules).
- **D** lien entité→Crm → **soft reference** (id+type, pas de FK Doctrine) :
  Billing/Project/Photo. **Risque #1 du chantier**, à valider en POC.
- **E** mono-domaine réel → **fusion** : Ecommerce+Erp = `aurora-commerce`
  (seule fusion ; `Product` est une entité concrète partagée).

**Cible** : 13 packages en étoile (1 core + 12 modules). Leaves purs
(Hr/Notes/PersonalFinance/Planning/Tools/Assistant) = split trivial.

**Critère de sortie J1.5** (gate automatisable, futur test deptrac) :
`grep -rhoE "use Aurora\\Module\\[A-Za-z]+" src/Module/<Y>/ | grep -vE
"(<Y>|Platform|Configuration|Dev|Ged)"` retourne **vide** pour tout module Y.

### Topologie (rappel)

- Core = `Core/` + **Platform + Configuration + Dev + Ged + General**.
- **13 modules métier** distribuables, dont **1 seul vrai cycle** : Ecommerce↔Erp
  (couplage par classe concrète `ProductRepository`) → fusionnés dans
  `aurora-commerce`. Les autres = leaves ou soft-ref (resolus via
  `EntityReferenceResolver`), aucun couplage latéral.
- Seul couplage manuel restant = `resolve_target_entities` (par bundle module).

## Liens

- [[pitfall_instanceof_scoping]] — le *file-scoping* de `_instanceof`
  (déjà documenté côté client) **est** le mécanisme qui rend le finding J5
  possible : un `instanceof()` local au `services.php` d'un package ne
  conflicte pas avec le `_instanceof` central. Le piège côté client devient
  une **propriété exploitée** côté packaging.
- [[pattern_core_submodules_split]] — la philosophie « 1 module = 1
  toggle root » qui sous-tend le découpage actuel et la motivation du
  split.
- [[decision_core_submodule_nesting]] — précédent breaking change
  (0.4.0) qui a déjà obligé les clients à migrer ; donne un précédent
  méthodologique pour gérer la transition v2.0.
- [`extracting_a_module.md`](../../../../docs/aurora-core/dev/extracting_a_module.md) —
  playbook complémentaire (spin-off d'un module vers un client dédié,
  pattern 1-to-1). À ne pas confondre avec le split monorepo.
