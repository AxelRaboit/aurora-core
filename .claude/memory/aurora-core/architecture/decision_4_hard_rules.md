# Les 4 règles dures de la convention

## Règle

Issues de l'audit post-Editorial (Octobre 2025), 4 règles **non-négociables**
appliquées uniformément sur les 24 entités :

### 1. `create<X>()` pour chaque classe instanciée - sans exception

Plus de notion "cascade" vs "inline child". Toute classe que le Manager
instancie via `new` doit avoir son hook `create<X>()`. Exemples :

- `MenuManager` → `createMenu()` + `createMenuItem()` + `createMenuItemTranslation()`
- `OrderManager` → `createOrder()` + `createOrderLine()`
- `ProjectManager` → `createProject()` (les autres entités cascade ont
  leurs propres Managers avec leur `createX()`)

### 2. `applyInput()` requis par défaut

Sauf variante "Manager à hooks multiples" (User, Order, Comment, OcrJob,
Tiers, Invoice) qui réunit les **3 critères** :

1. ≥6 méthodes publiques métier distinctes
2. Aucun flow create+update simple via DTO unique n'existe
3. Validation/sécurité distincte par opération

Cf [`decision_variant_user_style.md`](decision_variant_user_style.md).

### 3. Audit hooks obligatoires partout où `AuditLogger` est utilisé

`auditCreated/Updated/Deleted` pour les events CRUD standard +
`auditPayload()` comme base extensible. Les domain events (validate, paid,
…) restent inline mais **doivent splat-merger** `$this->auditPayload()` :

```php
$this->auditLogger->log('billing', 'invoice.validated', …, [
    ...$this->auditPayload($invoice),
    'extraField' => $value,
]);
```

Cf [`convention_audit_payload.md`](convention_audit_payload.md).

### 4. Repository extensibilité = limite assumée

Pas d'interface `<Name>RepositoryInterface` dans aurora-core. Les
controllers/managers Aurora type-hint la classe concrète `<Name>Repository`.
Coût/bénéfice non justifié pour les finder methods custom client.

Pattern client documenté : étendre `<Name>Repository` + déclarer
`repositoryClass` sur l'entité concrète client. `ResolveTargetEntityRepository`
route déjà la query via metadata.

Cf [`decision_repository_no_interface.md`](decision_repository_no_interface.md).

## Pourquoi

Ces règles **éliminent les variantes accidentelles** qui prolifèrent
inévitablement quand on applique une convention sans les fixer. Avant
l'audit, j'avais 5 "variantes structurelles" documentées en 4.bis du doc.
Après audit, **2 vraies variantes** restent (Manager hooks multiples,
Editor full-page) - les 3 autres étaient des règles mal formulées.

## Comment l'appliquer

Avant d'instrumenter une nouvelle entité :
1. Lister chaque `new <X>()` dans le Manager → autant de `create<X>()`.
2. Vérifier si l'entité matche les 3 critères User-style → variante 1 ou
   convention standard.
3. Si AuditLogger utilisé → 4 méthodes audit (3 hooks + 1 payload).
4. Repository : `extends ResolveTargetEntityRepository`. Ne PAS créer
   d'interface.

## Source

Audit du 8 mai 2025 après le module Editorial. Commit `5d3643d` (refacto
TaxonomyManager + harmonisation 4 anciens DTOs).
