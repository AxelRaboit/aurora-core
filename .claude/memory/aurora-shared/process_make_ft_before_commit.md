---
name: process_make_ft_before_commit
description: Lancer make ft (= fix + test + migrate-check) avant chaque commit - résoudre tous les problèmes avant de committer
metadata:
  type: feedback
---

## Règle

**Avant chaque commit**, lancer `make ft` (= `make fix && make test && make migrate-check`) et **résoudre** tous les problèmes détectés. Ne jamais committer avec des erreurs phpstan, des formatters non appliqués, ou des tests rouges.

## Pourquoi

- **`make fix`** applique les formatters (php-cs-fixer, twig-cs-fixer, rector, prettier, eslint). Garantit un style uniforme.
- **`make stan`** (lancé dans `make fix`) détecte les erreurs de typing statique.
- **`make test`** lance phpunit (+ Vitest côté JS). Confirme que rien n'est cassé runtime.

Sans `make ft`, des erreurs latentes s'accumulent silencieusement et créent une dette technique difficile à payer plus tard.

## Comment l'appliquer

```bash
make ft
# Si OK :
git add <files> && git commit -m "..."
```

**Aucune échappatoire** : pas de `--no-verify`, pas de `@phpstan-ignore` sans raison documentée.

### Cas refacto large

Lancer `make ft` à chaque entité instrumentée dans le rollout, pas juste à la fin. Ça permet d'attraper les régressions au bon endroit (commit qui les a introduites) plutôt qu'en bloc à la fin.

## Targets Makefile concernés

```bash
make ft         # = fix + test + migrate-check
make fix        # = fix-js + fix-twig + fix-rector + fix-php + stan
make stan       # phpstan (analyse statique PHP)
make test       # = test-frontend (Vitest) + test-backend (PHPUnit)
make fix-php    # php-cs-fixer (écriture) ; dry-run : make lint-php
make fix-twig   # twig-cs-fixer (écriture) ; dry-run : make lint-twig
make fix-js     # eslint --fix ; dry-run : make lint-js
```

> Cibles telles que définies dans le Makefile d'un **projet client**.
> Dans aurora-core lui-même, `make fix` commence en plus par `make translation`
> et `make ft` inclut `make build` - voir la version core de cette mémoire.
