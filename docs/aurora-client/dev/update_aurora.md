# Mettre à jour son environnement

Trois scénarios, trois commandes. Choisir le bon évite de wiper la DB
locale par accident.

| Situation | Commande | Effet sur la BDD |
|---|---|---|
| 1ʳᵉ installation du projet | `make install-dev` | Crée la DB, applique migrations, charge fixtures, lance le watcher Vite |
| Pull d'une PR aurora-client (nouvelle entité, migration, etc.) | **`make pull-update`** | **Données préservées** : `composer install` (lock) + `pnpm install` + `migrate` + cache + syncs config |
| Bump volontaire d'aurora-core | `make aurora-update` | Données préservées : `composer update axelraboit/aurora` + sub-installs + syncs |

⚠️ **Ne JAMAIS faire `make install-dev` sur un projet déjà setup** - il purge la
DB via `doctrine:fixtures:load`. Tes données de dev sont écrasées par les fixtures.

---

## `make pull-update` - pull d'une PR aurora-client

```bash
git pull
make pull-update
```

Enchaîne :

| Étape | Commande | Rôle |
|---|---|---|
| 1 | `composer install` | Sync `vendor/` selon `composer.lock` (PR a peut-être bumpé une dep ou aurora-core) |
| 2 | `composer install --working-dir=vendor/axelraboit/aurora --no-scripts` | Au cas où aurora-core a été pull avec une nouvelle sub-dep |
| 3 | `pnpm install` | Sync `node_modules/` racine selon `pnpm-lock.yaml` |
| 4 | `pnpm --dir=vendor/axelraboit/aurora install` | Sync les `node_modules` d'aurora-core (eslint, vitest, etc.) |
| 5 | `php bin/console cache:clear` | Cache Symfony purgé |
| 6 | `make migrate-f` | Nouvelles migrations appliquées |
| 7 | `make sync-jsconfig` | Aliases Vite mis à jour si aurora-core en a ajouté |
| 8 | `make sync-security` | `security.yaml` resynced si firewall a changé |
| 9 | `make sync-claude-md` | Symlinks CLAUDE.md + mémoires Claude rafraîchis |
| 10 | `make sync-makefile` | Le Makefile lui-même resynced depuis le template aurora-core |

Toutes les étapes sont idempotentes - safe à relancer.

---

## `make aurora-update` - bump explicite d'aurora-core

```bash
make aurora-update
```

À utiliser **uniquement** quand on veut explicitement la dernière version
d'aurora-core (et non celle figée dans `composer.lock`). Pour le pull d'une
PR d'un collègue, préférer `make pull-update` (qui respecte le lock).

---

## Fréquence

Lancer `make aurora-update` :
- Après chaque push sur la branche `develop` d'aurora-core
- Avant de démarrer un chantier qui touche des entités ou des conventions Aurora
- En cas de comportement inattendu (pour s'assurer d'avoir la dernière version)

Lancer `make pull-update` :
- À chaque `git pull` qui ramène une PR d'un collègue
- En CI après le checkout (idem effet : sync vendor + node_modules + DB)

---

## Vérifier après une mise à jour

```bash
make ft             # tests verts + linters
make schema-validate  # le schéma Doctrine est cohérent
```

Si des migrations ont été ajoutées dans aurora-core, elles sont jouées
automatiquement à l'étape 5. Vérifier que la migration n'a pas de conflit
avec les migrations client existantes.

---

## Breaking changes

Les breaking changes sont listés dans le `CHANGELOG.md` d'aurora-core
sous une ligne préfixée `BREAKING:`.

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

---

## Customisations préservées au sync

| Fichier | Comportement |
|---|---|
| `CLAUDE.md` | **Écrasé** - ne pas éditer, créer `CLAUDE.local.md` à la place |
| `Makefile` | **Écrasé** - targets custom dans `Makefile.local` (jamais touché) |
| `config/packages/security.yaml` | **Écrasé** - géré par Aurora |
| `.claude/memory/aurora-core/` | Symlink vers vendor - automatiquement à jour |
| `.claude/memory/aurora-client/` | Symlink vers vendor - automatiquement à jour |
| `docs/aurora-core/` | Symlink vers vendor - automatiquement à jour |
| `docs/aurora-client/` | Symlink vers vendor - automatiquement à jour |
| `src/`, `templates/`, `assets/client/` | **Jamais touchés** |
| `.env.local` | **Jamais touché** |
| `Makefile.local` | **Jamais touché** |
| `CLAUDE.local.md` | **Jamais touché** |
