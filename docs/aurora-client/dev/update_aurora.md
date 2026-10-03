# Mettre à jour son environnement

Trois scénarios, trois commandes. Choisir le bon évite de wiper la DB
locale par accident.

| Situation | Commande | Effet sur la BDD |
|---|---|---|
| 1ʳᵉ installation du projet (clone neuf) | `make install-dev` | **Supprime et recrée la DB**, `schema:create` + migrations marquées appliquées, `aurora:install`, fixtures, puis lance Vite. Procédure complète : [`../getting-started/joining_a_project.md`](../getting-started/joining_a_project.md) |
| Pull d'une PR aurora-client (nouvelle entité, migration, etc.) | **`make pull-update`** | **Données préservées** : deps depuis les locks + migrations + cache + syncs |
| Bump volontaire d'aurora-core | `make aurora-update` | Données préservées : monte aurora-core, sous-installs, migrations, syncs, traductions, build |

⚠️ **Ne JAMAIS faire `make install-dev` sur un projet déjà setup** : il
supprime la base (`doctrine:database:drop`) avant de la recréer. Tes
données de dev disparaissent.

---

## `make pull-update` - pull d'une PR aurora-client

```bash
git pull
make pull-update
```

Enchaîne :

| Étape | Commande | Rôle |
|---|---|---|
| 1 | `composer install` | Sync `vendor/` selon `composer.lock` (la PR a peut-être bumpé une dep ou aurora-core) |
| 2 | `composer install --working-dir=vendor/axelraboit/aurora --no-scripts` | Dépendances propres d'aurora-core (son `vendor/` imbriqué) |
| 3 | `composer install` dans `vendor/axelraboit/aurora/tools/{php-cs-fixer,twig-cs-fixer,rector,phpstan}` | Les quatre linters, chacun dans son install |
| 4 | `pnpm install` | Sync `node_modules/` racine selon `pnpm-lock.yaml` |
| 5 | `pnpm --dir=vendor/axelraboit/aurora install` | Sync les `node_modules` d'aurora-core (Vite, ESLint, Vitest, etc.) |
| 6 | `php bin/console cache:clear` | Cache Symfony purgé |
| 7 | `make migrate-f` | Nouvelles migrations appliquées (client et aurora-core) |
| 8 | `make sync-jsconfig` | Aliases Vite mis à jour si aurora-core en a ajouté |
| 9 | `make sync-env` | Ajoute à `.env` les blocs `###> aurora/* ###` manquants, sans toucher aux valeurs existantes |
| 10 | `make sync-readme` | Remplace le bloc canonique du `README.md` (entre les marqueurs), le reste est préservé |
| 11 | `make sync-security` | `security.yaml` recopié depuis aurora-core |
| 12 | `make sync-claude-md` | Liens `CLAUDE.md`, `.claude/memory/` et skills partagés rafraîchis |
| 13 | `make sync-makefile` | Le Makefile lui-même recopié depuis le modèle d'aurora-core |

Toutes les étapes sont idempotentes - safe à relancer.

`make pull-update` refuse de tourner moins de 5 minutes après un `make
aurora-update` : l'ordre correct est `pull-update` puis `aurora-update`
(se caler sur l'équipe, puis monter par-dessus). `make pull-and-bump`
enchaîne les deux dans cet ordre.

---

## `make aurora-update` - bump explicite d'aurora-core

```bash
make aurora-update
```

À utiliser **uniquement** quand on veut explicitement une version
d'aurora-core plus récente que celle figée dans `composer.lock`. Pour le
pull d'une PR d'un collègue, préférer `make pull-update` (qui respecte le
lock).

La cible lance `composer update axelraboit/aurora` : Composer prend le
**dernier tag stable** qui satisfait la contrainte du `composer.json`
(`^1.0`), pas la branche `develop`. Ensuite, comme `pull-update` : les
sous-installs (aurora-core, les quatre linters, les deux `pnpm install`),
`cache:clear`, `make migrate-f`, puis `aurora:privileges:sync`, les mêmes
syncs, et enfin `make translation` + `make build` pour que les nouvelles
clés de traduction et le bundle de prod suivent.

Le `composer.json` déclare aurora-core comme dépôt VCS en SSH : résoudre
une nouvelle version peut demander une clé SSH ou un token GitHub.

---

## Fréquence

Lancer `make aurora-update` :
- Après chaque release d'aurora-core (nouveau tag)
- Avant de démarrer un chantier qui touche des entités ou des conventions Aurora
- En cas de comportement inattendu (pour s'assurer d'avoir la dernière version)

Lancer `make pull-update` :
- À chaque `git pull` qui ramène une PR d'un collègue

La CI n'utilise pas `make pull-update` : le workflow installe ses
dépendances lui-même et monte une base de test neuve (cf.
[`../deployment/github_actions_ci.md`](../deployment/github_actions_ci.md)).

---

## Vérifier après une mise à jour

```bash
make ft             # tests verts + linters
make schema-validate  # le schéma Doctrine est cohérent
```

Si des migrations ont été ajoutées dans aurora-core, elles sont jouées
automatiquement par `make migrate-f` (étape 7 de `pull-update`, et aussi
dans `aurora-update`). Vérifier que la migration n'a pas de conflit avec
les migrations client existantes.

---

## Breaking changes

Depuis la 1.0.0, un changement qui casse fait monter la **version majeure**
d'aurora-core (voir la règle dans
[`propagating_updates.md`](../../aurora-core/dev/propagating_updates.md#le-numéro-de-version)).
La contrainte `^1.0` ne le prend donc pas toute seule : `make aurora-update`
reste sur la dernière 1.x. Pour passer en 2.0, lire la section « Dans
aurora-client » de la version, faire ce qu'elle demande, puis changer la
contrainte en `^2.0`.

### Le commit du bump, et la version du client

Le client calcule son propre numéro à partir de ses commits (Conventional
Commits, `.github/workflows/release.yml`). Le type du commit qui monte
aurora-core suit donc ce que la version apporte :

| Version d'aurora-core | Commit du bump | Version du client |
|---|---|---|
| majeure | `chore(deps)!: aurora-core v2.0.0` | majeure |
| mineure | `feat(deps): aurora-core v1.5.0` | mineure |
| correctif | `fix(deps): aurora-core v1.4.3` | correctif |

Un bump qui traverse plusieurs versions prend la plus haute d'entre elles.

Avant une mise à jour importante :

```bash
cat vendor/axelraboit/aurora/CHANGELOG.md | head -50
```

---

## Cas particulier : Makefile mis à jour

Si `make sync-makefile` détecte que le Makefile a changé, il l'écrase et
affiche :

```
✅ Makefile updated from aurora-core - re-run 'make aurora-update' if needed
```

Dans ce cas, **relancer `make aurora-update`** pour que les nouvelles targets
soient disponibles dès la suite de la séquence.

Si le `Makefile` a des modifications locales non commitées, la sync
refuse de l'écraser : déplace tes cibles dans `Makefile.local`, ou force
avec `make sync-makefile FORCE=1`.

---

## Customisations préservées au sync

| Fichier | Comportement |
|---|---|
| `CLAUDE.md` | **Lien vers le vendor** - ne pas éditer, créer `CLAUDE.local.md` à la place |
| `Makefile` | **Écrasé** - targets custom dans `Makefile.local` (jamais touché) |
| `config/packages/security.yaml` | **Écrasé** - géré par Aurora |
| `README.md` | **Bloc canonique remplacé** (entre `aurora-canonical:start` et `aurora-canonical:end`) ; titre, intro et "Spécifique à ce projet" préservés |
| `.env` | **Complété** : blocs `###> aurora/* ###` manquants ajoutés, valeurs existantes jamais modifiées |
| `.claude/memory/aurora-core/`, `aurora-client/`, `aurora-shared/` | Liens vers le vendor - automatiquement à jour |
| `.claude/skills/<skill>` (skills `scope: shared`) | Liens vers le vendor - automatiquement à jour |
| `.claude/settings.json` | Créé depuis le modèle s'il n'existe pas, jamais écrasé ensuite |
| `docs/aurora-core/`, `docs/aurora-client/`, `docs/aurora-shared/` | **Supprimés** s'ils existent : la doc se lit dans `vendor/axelraboit/aurora/docs/`, pas de copie locale |
| `src/`, `templates/`, `migrations/` | **Jamais touchés** |
| `.env.local`, `.env.test.local` | **Jamais touchés** |
| `Makefile.local` | **Jamais touché** |
| `CLAUDE.local.md` | **Jamais touché** |

Résumé côté projet :
[Ce que les synchronisations n'écrasent jamais](../getting-started/joining_a_project.md#ce-que-les-synchronisations-nécrasent-jamais).
