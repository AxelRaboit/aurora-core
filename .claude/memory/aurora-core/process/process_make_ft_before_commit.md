# Convention : `make ft` avant chaque commit

## Règle

**Avant chaque commit**, lancer `make ft` (= `make fix && make test &&
make build && make migrate-check`) et **résoudre** tous les problèmes
détectés (formatters + phpstan + tests + build + migrations en attente).
Ne jamais commit avec des erreurs phpstan, des formatters non appliqués,
ou des tests rouges.

Si aucun asset (Vue / JS / CSS) n'a été touché, `make ftl` fait la même
chose sans le build Vite - sensiblement plus rapide.

## Pourquoi

- **`make fix`** applique les formatters (php-cs-fixer, twig-cs-fixer,
  rector, prettier, eslint). Garantit un style uniforme.
- **`make stan`** (lancé dans `make fix`) détecte les erreurs de typing
  statique. **C'est ici qu'on attrape les régressions** introduites par
  un refacto (ex: type-hint Concrete au lieu d'Interface après le rollout
  d'extensibilité).
- **`make test`** lance phpunit (+ Vitest côté JS). Confirme que rien
  n'est cassé runtime.

Sans `make ft`, des erreurs latentes s'accumulent silencieusement et
créent une dette technique difficile à payer plus tard (cf l'historique
récent du rollout - 100 erreurs phpstan accumulées car stan n'était pas
lancé à chaque commit).

## Comment l'appliquer

### Cas standard (commit prêt)

```bash
make ft
# Si OK :
git add -A && git commit -m "..."
```

### Cas refacto large (rollout massif)

Lancer `make ft` à chaque entité instrumentée dans le rollout, pas
juste à la fin. Ça permet d'attraper les régressions au bon endroit
(commit qui les a introduites) plutôt qu'en bloc à la fin.

### Cas où `make stan` plante massivement après un refacto

Si le refacto introduit beaucoup d'erreurs (ex: 100 erreurs après le
rollout), résoudre par **catégorie de pattern** + commits atomiques :
1. Lot A : repos `@return Interface|null`
2. Lot B : type-hints Manager `Interface` au lieu de Concrete
3. Lot C : `instanceof Interface`
4. Lot D : variance `Collection<int, Interface>`

Chaque lot = un commit. Tester `make ft` entre chaque lot.

### Échappatoire

**Aucune.** Pas de `--no-verify`, pas de `@phpstan-ignore`, pas de
baseline d'évitement. Si un cas force vraiment l'échappatoire,
documenter pourquoi en commentaire de code + en mémoire ici.

## Targets Makefile concernés

```bash
make ft         # = fix + test + build + migrate-check
make ftl        # = fix + test + migrate-check (sans build d'assets)
make fix        # = translation + fix-js + fix-twig + fix-rector + fix-php + stan
make stan       # phpstan (analyse statique PHP)
make test       # = test-frontend (Vitest) + test-backend (PHPUnit)
make rector     # rector en DRY-RUN - l'autofix, c'est `make fix-rector`
make fix-php    # php-cs-fixer (écriture)
make fix-twig   # twig-cs-fixer (écriture)
make fix-js     # eslint --fix
```

> Les cibles `php-cs-fix` et `twig-cs-fix` citées ici auparavant n'ont
> jamais existé dans le Makefile : ce sont `fix-php` et `fix-twig`.

## Source

Convention demandée par l'utilisateur le 8 mai 2025 après que `make ft`
ait révélé 100 erreurs phpstan accumulées suite au rollout
d'extensibilité (rollout réalisé sans `make stan` régulier, juste
`phpunit`).

À appliquer **systématiquement** désormais.
