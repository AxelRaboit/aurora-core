# Convention : type-hint l'Interface, pas la Concrete

## Règle

**Dans toutes les signatures (paramètres, retours, propriétés, génériques),
type-hint l'Interface d'une entité instrumentée - jamais la Concrete.**

S'applique partout où une entité instrumentée (cf
[`convention_extensibility.md`](convention_extensibility.md)) circule :

- **Repository** : params `findByXxx(EntityInterface $x)` + retours
  `@return list<EntityInterface>`. Le repo extends
  `ResolveTargetEntityRepository<EntityInterface>`, donc `findOneBy`,
  `findBy`, `find` retournent l'Interface - propage la signature.
- **Manager** : params `Manager::doStuff(EntityInterface $x)`, retours
  `: EntityInterface`. Same dans `<Name>ManagerInterface`.
- **Serializer** : `serialize(EntityInterface $x): array`.
- **Entity setters/getters** des relations : si `Project` a un
  `responsibleUser`, alors `setResponsibleUser(?CoreUserInterface)` et
  `getResponsibleUser(): ?CoreUserInterface` (dans Interface, Abstract,
  Concrete).
- **Collections génériques** : `Collection<int, EntityInterface>` partout
  (Doctrine `OneToMany` / `ManyToMany`).
- **`array_map` / `array_filter` callbacks** sur résultats de repo :
  `static fn (EntityInterface $x): array => ...` - pas la Concrete.
- **`@implements UserProviderInterface<CoreUserInterface>`**, pareil pour
  toute interface générique acceptant une entité instrumentée.

**Exception** : la **Concrete elle-même** (instanciation `new Concrete()`,
type-hint des Voters/Security qui *requièrent* la concrete pour Doctrine).
Là on garde la concrete.

## Pourquoi

Un client (aurora-client ou autre) qui étend une entité via la convention
extensibility (`AppPost extends Post`) doit pouvoir circuler dans tout le
code Aurora-core sans variance error. Si Aurora-core a `setAuthor(User $u)`,
le client ne peut pas y passer son `AppUser extends User` sans recast - et
un Manager héritant ne peut pas overrider la signature pour `AppUser`
(violation LSP).

**Type-hinter l'Interface partout** garantit :
1. Le client peut substituer son entité sans variance error.
2. Le `resolve_target_entities` Doctrine fonctionne (les repos retournent
   l'Interface, pas la Concrete).
3. Les decorateurs `#[AsDecorator]` sont effectivement appelés (cf
   [`pitfall_type_hint_interface.md`](pitfall_type_hint_interface.md)).
4. PHPStan passe sans contortion (pas de cast, pas d'`assert()`, pas de
   `@phpstan-ignore`).

## Comment l'appliquer

### À l'écriture de code neuf

Réflexe : dès qu'une entité instrumentée apparaît dans une signature,
écrire `XxxInterface`, pas `Xxx`. Si l'IDE auto-importe la Concrete,
re-pointer manuellement vers l'Interface.

### À l'audit / refacto

```bash
# Repos qui type-hint la Concrete au lieu de l'Interface
grep -rn "function findBy.*\(.*Entity\\\\[A-Z][a-z]*\)" src/ --include="*.php" \
  | grep -v Interface

# array_map sur la Concrete au lieu de l'Interface
grep -rn "fn.*Entity\\\\[A-Z][a-z]*[a-zA-Z]*\\s*\\\$" src/ --include="*.php"
```

### Quand widen une signature

Si PHPStan râle parce qu'un `setXxx(?Concrete)` reçoit `?Interface` :

- **Élargir le setter** (Interface + Abstract + Concrete) à l'Interface,
  ne **pas** caster côté appelant.
- **Élargir le getter** par cohérence si ça pointe une relation.
- Si l'entité Concrete dépend de méthodes spécifiques à la Concrete
  (jamais le cas pour les relations), c'est un signal qu'il manque une
  méthode dans l'Interface - l'ajouter là.

### Échappatoire

Aucune. Si une situation force vraiment à type-hint la Concrete (ex:
Voter Security qui doit comparer l'identité Doctrine via `instanceof
Concrete`), documenter le pourquoi en commentaire de code.

## Source

Rollout extensibility octobre 2025 → 100 erreurs phpstan latentes
détectées en mai 2026 par `make ft`. Toutes les erreurs venaient du
même pattern : `Concrete` dans les signatures là où `Interface` aurait
suffi. Fixées en bloc dans la session du 2026-05-08.

À appliquer **systématiquement** à l'avenir pour éviter d'accumuler
le même type de dette.
