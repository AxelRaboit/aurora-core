# Aurora-core - Guide pour Claude

Ce fichier est chargé automatiquement par Claude Code à chaque session dans ce
dépôt. Il résume les conventions et points d'entrée nécessaires pour bien
travailler sur `aurora-core` et son écosystème (`aurora-client`).

> **📚 Base de mémoire structurée** : voir [`.claude/memory/MEMORY.md`](.claude/memory/MEMORY.md)
> pour l'index racine. Les mémoires sont organisées en deux sous-dossiers :
> - [`.claude/memory/aurora-core/`](.claude/memory/aurora-core/MEMORY.md) -
>   conventions, décisions, pièges et préférences propres au bundle core.
>   Toute nouvelle mémoire core va ici (un fichier `.md` + ligne dans l'index).
> - [`.claude/memory/aurora-client/`](.claude/memory/aurora-client/MEMORY.md) -
>   patterns d'extension côté consommateur, distribués via composer
>   (lus depuis `vendor/axelraboit/aurora/.claude/memory/aurora-client/`).

> **🧠 Règle d'hygiène mémoire (obligatoire)** :
> Toujours travailler avec la **mémoire projet versionnée**
> (`.claude/memory/aurora-core/`, suivie par git, partagée avec l'équipe) -
> **pas** la mémoire user-level Claude Code (`~/.claude/projects/...`).
> Cette dernière est personnelle et invisible aux autres devs.
>
> À chaque session et **à la fin de chaque tâche significative** (nouvelle
> feature, refacto, fix non-trivial, décision d'archi), faire ce cycle :
>
> 1. **Lire** les mémoires potentiellement concernées par la tâche (ne pas se
>    limiter au titre dans l'index - ouvrir les fichiers source).
> 2. **Vérifier la fraîcheur** : si une mémoire affirme l'existence d'un
>    fichier/classe/flag, le vérifier dans le code courant avant de s'y fier
>    (cf. la section "Before recommending from memory" de l'auto-memory).
> 3. **Corriger ou supprimer** les mémoires devenues obsolètes (refacto,
>    décision changée) - ne pas accumuler de fausse info.
> 4. **Ajouter** une nouvelle mémoire dès qu'une convention émerge, qu'un
>    piège est découvert, ou qu'une décision d'archi est prise. Format :
>    `<type>_<topic>.md` (un fichier par sujet) + une ligne dans
>    `.claude/memory/aurora-core/MEMORY.md`. Capturer **règle**, **pourquoi**,
>    **comment l'appliquer** - pas du contenu dérivable du code/git.
> 5. **Ne pas dupliquer** les docs `docs/aurora-core/dev/*.md` - y pointer
>    depuis la mémoire si besoin.
> 6. **Synchroniser** : après tout ajout/modif/suppression dans
>    `.claude/memory/aurora-core/`, lancer `make sync-claude-memory`. Cette
>    commande recopie les fichiers vers `~/.claude/projects/.../memory/`
>    pour que Claude les lise automatiquement à la prochaine session.
>    Sans ce sync, les nouvelles mémoires n'arrivent pas dans le contexte.
>
> Cette boucle est ce qui maintient la cohérence sur le long terme. Faire
> remonter à l'utilisateur les mémoires modifiées/ajoutées en fin de tâche.

---

## 1. Stack et architecture

- **PHP 8.4 + Symfony 7** côté serveur, **Vue 3 + Vite** côté client
- Bundle distribué : `axelraboit/aurora` (composer), monté dans une app cliente
  via `aurora-client/` (séparé). Les clients consomment aurora-core comme un
  bundle Symfony et étendent des points d'extension typés (Sylius-style).
- Architecture en couches `src/Core/` (infrastructure partagée) +
  `src/Module/{Billing,Crm,Ecommerce,Editorial,Erp,Photo,Project,Ged}/`
  (modules métier autonomes).

**Lecture rapide** : [`docs/aurora-core/dev/app_architecture.md`](docs/aurora-core/dev/app_architecture.md)
pour la cartographie complète (templates, assets, namespaces Twig, etc.).

---

## 2. Convention d'extensibilité (centrale, à respecter scrupuleusement)

Toute entité de aurora-core qui a une page backend CRUD suit le pattern Sylius
en 5 couches. **Doc canonique** :
[`docs/aurora-core/dev/entity_extensibility_convention.md`](docs/aurora-core/dev/entity_extensibility_convention.md).

**Résumé des règles dures** :

1. **Couche 1 - Entity** : `<Name>Interface` + `Abstract<Name>` (MappedSuperclass)
   + concrete `<Name>` non-`final`. Sequence nommée `seq_core_<entity>_id` (le
   préfixe `seq_core_` est obligatoire pour éviter les collisions avec des
   entités client homonymes). Référencé dans `AuroraBundle::$resolve_target_entities`.
2. **Couche 2 - DTO** : `<Name>InputInterface` + `<Name>InputFactoryInterface`
   + `<Name>InputFactory` (avec `#[AsAlias(<Name>InputFactoryInterface::class)]`)
   + `<Name>Input` non-`final` avec `public readonly` sur chaque propriété
   (PAS `readonly class` global - ça empêcherait un client d'ajouter une
   propriété mutable en étendant). Pas de `static fromArray()` dans le DTO,
   c'est la factory qui le fait.
3. **Couche 3 - Manager** : `<Name>ManagerInterface` dans `Manager/` (jamais
   `Contract/` - l'ancien dossier est interdit pour les Managers
   instrumentés). `<Name>Manager` non-`final` + props `protected readonly` +
   `#[AsAlias(<Name>ManagerInterface::class)]`. Trois familles de hooks :
   - **Instanciation** : `protected create<X>(): <X>Interface` pour
     **chaque classe** que le Manager instancie (sans exception).
   - **Hydratation** : `protected applyInput(<Name>Interface, <Name>InputInterface)`,
     sauf variante User-style (3 critères : ≥6 méthodes spécialisées, pas de
     create+update simple, validation/sécurité distincte par opération).
   - **Audit** : `protected auditCreated/Updated/Deleted` + `auditPayload`.
     Les domain events (validate, paid, stage_changed, …) restent inline mais
     splat-mergent `auditPayload()` pour rester extensibles.
4. **Couche 4 - Serializer** : `<Name>SerializerInterface` + `<Name>Serializer`
   non-`final` + `#[AsAlias]`.
5. **Couche 5 - Vue** : `<Plural>App.vue` avec props `extraFields` + slots
   `extra-headers`/`extra-cells`/`extra-form-fields` ; composable
   `useXxxForm.js` unifié create+edit avec option `extraFields`.

**Variantes structurelles documentées** (4 cas) :
- Manager à hooks multiples sans `applyInput` (User, Menu pré-DTO, Billing,
  Order)
- Composables Vue séparés `useXxxCreate` + `useXxxEdit` (User invite/edit, Theme)
- Editor full-page au lieu de modal (Post)
- Tree-based editor sans table (MarkdownNote) - slots adaptés au layout
  arbre + éditeur, `extra-cells` propagé récursivement via `NoteTreeItem`

**Repository** : `<Name>Repository` étend
`Aurora\Core\Repository\ResolveTargetEntityRepository` (jamais
`ServiceEntityRepository` directement). Pas d'interface aurora-core pour les
finder methods custom - limite documentée, le client étend le repo et
déclare son propre `repositoryClass` sur l'entité concrète.

---

## 3. Côté client (aurora-client)

Pour étendre une entité depuis l'app client :
- **Cheatsheet** : [`docs/aurora-client/getting-started/setup.md`](docs/aurora-client/getting-started/setup.md)
- **Guide pas-à-pas** (exemple Agency complet) :
  [`docs/aurora-core/dev/extending_agency_pilot.md`](docs/aurora-core/dev/extending_agency_pilot.md)
- **Vue d'ensemble** : [`docs/aurora-core/dev/extending_aurora.md`](docs/aurora-core/dev/extending_aurora.md)

Patterns clés pour étendre :
- Substituer une entité : étendre `Abstract<Name>`, déclarer
  `#[ORM\Entity(repositoryClass: …)]`, mettre à jour
  `App\AuroraBundle::$resolve_target_entities` côté client.
- Substituer un DTO : étendre `<Name>Input`, étendre `<Name>InputFactory`,
  décorer la factory via `#[AsAlias(<Name>InputFactoryInterface::class)]`.
- Substituer un Manager : étendre, override les hooks `protected`
  (`create<X>()`, `applyInput()`, `auditPayload()`), décorer via
  `#[AsAlias(<Name>ManagerInterface::class)]`.
- Substituer un Serializer : pareil.
- Étendre la Vue : passer la prop `extraFields` + utiliser les slots scoped
  (`extra-headers`, `extra-cells`, `extra-form-fields`).

---

## 3bis. Philosophie d'architecture - "penser long terme"

**Préférence utilisateur (2026-05-16)** : sur ce projet, on **anticipe**
les évolutions plutôt que d'attendre qu'un besoin force la refacto. Le
défaut Aurora n'est PAS "Three similar lines is better than a premature
abstraction" - c'est l'inverse :

> **Si une abstraction est architecturalement saine (SOLID, séparation
> des concerns, extensibilité documentée), faire la refacto MAINTENANT
> même sans utilisateur concret immédiat.**

Exemples qui passent le filtre :
- Séparer domaine ↔ présentation (entités sans `getPublicUrl()`,
  URL building via `UrlGeneratorInterface` injecté)
- Sortir un helper partagé dès le 3e site similaire (cf. `Num::clamp`)
- Conventions extensibilité Sylius-style sur les entités CRUD
  (Interface + non-final + hooks `protected`)
- Settings admin éditables même pour 1 client unique (préparer multi-deploy)
- Routes sémantiques par module (jamais hardcoder `/uploads/...`)

**Garde-fous quand même** (penser grand ≠ over-engineering aveugle) :
1. **Pas d'interface sans implémenteur multiple plausible**
   (`MarkdownNoteImageServiceInterface` sans 2nd service serait du fluff)
2. **Pas de hook sans usecase d'override identifié**
   (méthode `protected` qui ne sera jamais surchargée = bruit)
3. **Pas de config sans utilisateur** (un setting Aurora-bundle = OK
   car les clients consommateurs peuvent en avoir besoin ; un setting
   sur une app one-shot = non)
4. **Le coût doit rester proportionnel** - refactor 22 fichiers pour
   séparer domaine/HTTP = OK. Ajouter 8 classes d'abstraction pour un
   cas hypothétique = non.

Cette philosophie remplace la règle générique "Don't design for
hypothetical future requirements" pour **l'écosystème Aurora entier**
(core + clients). Voir la mémoire shared
[`pref_think_long_term.md`](.claude/memory/aurora-shared/pref_think_long_term.md) -
distribuée via composer aux clients.

---

## 4. Conventions de naming (à appliquer)

> **Heuristique mnémotechnique** :
> - Lu par un humain (URL, folder assets, CSS) → `kebab-case`
> - Identifiant interne (route name, setting, DB column, i18n) → `snake_case`
> - Classe PHP / composant Vue → `PascalCase`
> - Variable / fonction JS → `camelCase`
>
> Convention résumée ici ; voir aussi la mémoire shared `convention_naming.md`.
> Mémoire shared : `.claude/memory/aurora-shared/convention_naming.md`.

- **Variables** : noms complets (jamais 1-2 lettres). Ex : `$company`, pas
  `$c` ; `$translation`, pas `$tr`.
- **Repos: éviter le N+1** : `findBy(['id' => $ids])` plutôt que `find()`
  dans une boucle pour hydrater plusieurs entités.
- **Manager vs Service** :
  - `Manager/` = classes qui persistent / flushent / orchestrent un cycle
    de vie d'entité.
  - `Service/` = logique stateless pure (helpers, calculs, validateurs).
- **DTO** : dossier `Dto/` (jamais `DTO/` majuscules - l'acronyme reste
  "DTO" en prose mais le namespace est `Dto`).
- **Tests** : helper d'instanciation dans le test si l'API DTO change
  beaucoup, plutôt que recopier `new XxxInput(...)` partout.

---

## 5. Commandes utiles

```bash
# Tests (492+ tests, doivent rester verts)
php bin/phpunit

# Build assets Vue
npm run build

# Lint Symfony
php bin/console lint:twig templates/
php bin/console lint:yaml config/

# Cache (souvent nécessaire après refacto DI)
php bin/console cache:clear --env=test

# Schema/migrations Doctrine
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:diff
```

**Avant chaque commit** : tests verts + build OK. Pas d'exception.

---

## 5bis. Storage des fichiers

**Tous les fichiers uploadés/générés vivent sous `var/uploads/`**, hors
document root. Aucun fichier n'est servable directement par Apache -
chaque accès passe par un controller PHP via la route catch-all
`/uploads/{path}` (`UploadsServeController` côté `Core/Storage/`) qui
délègue à `Aurora\Core\Storage\BinaryFileServer` (path-traversal guard
+ `BinaryFileResponse` + X-Sendfile).

Conventions :
- `app.upload_dir` pointe sur `%kernel.project_dir%/var/uploads`
- Sous-dossier par module : `var/uploads/media/`, `var/uploads/profile-photos/`,
  `var/uploads/notes-markdown/`, etc.
- Entités exposent `getPublicUrl()` retournant la forme `/uploads/<path>`
  (URL stable que la route Symfony intercepte - pas de fuite du
  storage backend dans l'URL côté front)
- Auth granulaire : pour gater une catégorie (factures OCR, PDF
  signés, notes per-user), définir une route plus spécifique sous
  `/backend/<module>/files/...` qui prend précédence sur le catch-all
- Prod : `mod_xsendfile` offload les bytes une fois l'auth check PHP
  passé. Voir `docs/aurora-client/deployment/apache_xsendfile.md`

Mémoire de référence : `aurora-shared/convention_storage_var_uploads.md`.
Mémoire shared (distribuée aux clients) :
[`convention_storage_var_uploads.md`](.claude/memory/aurora-shared/convention_storage_var_uploads.md).

---

## 6. Conventions Git / commits

- **Pas de `Co-Authored-By` Claude** dans les messages de commit (préférence
  utilisateur explicite).
- **Pas de `--no-verify`** sur les hooks pre-commit. Si un hook échoue,
  fixer la cause.
- **Préfixes de message standardisés** : `feat:`, `refactor:`, `docs:`,
  `fix:`, `test:` (suivre l'historique récent : `git log --oneline -20`).
- **Commits atomiques par entité** lors du rollout d'extensibilité (cf
  l'historique récent : 24 commits, un par entité).
- **Audit docs/mémoires avant chaque commit** : pour chaque fichier
  modifié, vérifier si une doc sous `docs/` ou une mémoire sous
  `.claude/memory/` mentionne le sujet touché (`grep -rn
  "<ClassName>\|<methodName>" docs/ .claude/memory/`). Si oui,
  s'assurer que les snippets et affirmations sont encore exacts contre
  le code actuel ; sinon, mettre à jour **dans le même commit**
  (préférable) ou dans un commit `docs:` qui suit immédiatement. Vaut
  dans les deux sens : renommer/supprimer une doc → grep ses
  références aussi. Détail :
  [`process_doc_audit_before_commit.md`](.claude/memory/aurora-core/process/process_doc_audit_before_commit.md).

---

## 7. État du rollout d'extensibilité

✅ **43/43 entités CRUD instrumentées** (rollout terminé).
- Commits : `git log --oneline --grep="instrument"` pour la liste

Plus aucune entité ne devrait avoir un Manager `final readonly` ou un dossier
`Contract/` (sauf pour des interfaces non-Manager légitimes : provider
patterns, location registries, etc.).

---

## 8. Checklist pour ajouter une nouvelle entité Aurora

1. Créer `Interface + Abstract + concrete` dans `Entity/` avec sequence
   `seq_core_<entity>_id`.
2. Ajouter au `resolve_target_entities` de `AuroraBundle.php` - **seule
   ligne manuelle nécessaire**. Tout le reste est auto-découvert par glob :
   Doctrine mappings, Twig namespaces, Symfony translator paths,
   DumpJsTranslationsCommand.
3. Repository qui étend `ResolveTargetEntityRepository`.
4. **Si backend CRUD** : 4 fichiers DTO (Input, InputInterface,
   InputFactoryInterface, InputFactory) + Manager (Interface + class non-final
   + AsAlias + hooks) + Serializer (Interface + class non-final + AsAlias) +
   Controller (type-hint les interfaces) + Vue (extraFields + slots) sous
   `src/Module/<Module>/assets/backend/` (co-localisé avec les classes PHP du
   module - plus de root `assets/` depuis 0.5).
5. Ajouter à la table 2.1 de `entity_extensibility_convention.md` si la
   liste change.
6. Tests + build verts, commit atomique.

> **Créer un nouveau module** (`src/Module/<Module>/`) : le dossier seul
> suffit pour que Doctrine, Twig et les traductions le découvrent
> automatiquement. Seul `resolve_target_entities` est à renseigner
> manuellement pour chaque entité du module.
> **Doc + commit de référence** : [`docs/aurora-core/dev/add_module.md`](docs/aurora-core/dev/add_module.md)
> - commit `167aafa` (PasswordGenerator) illustre la checklist complète.

---

## 9. Pièges connus

- **Doctrine resolve_target_entities** ne s'applique qu'aux relations Doctrine,
  pas aux `new <Name>()` directs. C'est pour ça que le hook `create<X>()`
  existe : il permet au client de retourner sa classe substituée.
- **`readonly class` PHP 8.2+** force tout enfant à être également `readonly`
  → plus difficile à étendre. Préférer `class { public readonly … }` par
  propriété.
- **Sub-DTO** (ex: `PostTranslationInput` dans `PostInput`) : restent
  `final readonly`, **pas instrumentés**. Seul le DTO racine consommé par le
  controller a une factory + interface.
- **`#[AsAlias]` sur l'interface** : permet la substitution. Mais la
  décoration via `#[AsDecorator]` ne marche que si les consommateurs
  type-hint l'**interface**, pas la classe concrète. Toujours type-hint
  l'interface dans les controllers/services tiers.
