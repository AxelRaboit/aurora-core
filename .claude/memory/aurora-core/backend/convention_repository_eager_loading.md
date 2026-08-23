---
name: convention_repository_eager_loading
description: Nommer les méthodes de repo qui pré-chargent des relations pour éviter les N+1 selon le contexte d'utilisation
metadata:
  type: feedback
---

## Règle

Quand un `*ViewBuilder` (ou tout autre appelant) déclenche des N+1 sur des collections Doctrine, ajouter une méthode dédiée dans le repository avec un nom qui décrit **le contexte d'utilisation**, pas l'implémentation.

### Convention de nommage

| Contexte | Nom |
|---|---|
| Page liste (serializer léger) | `findAllForIndex()` |
| Associations spécifiques sans les collections profondes | `findAllWith<Relation>And<Relation>()` |
| Batch après pagination | méthode `private hydrate<X>Collections(array $items)` |

### Patterns selon le cas

**1. Pas de pagination - charger toutes les collections en une query DQL :**
```php
public function findAllForIndex(): array
{
    return $this->createQueryBuilder('m')
        ->leftJoin('m.items', 'i')
        ->addSelect('i')
        ->orderBy('m.name', Order::Ascending->value)
        ->getQuery()
        ->getResult();
}
```

**2. Associations multiples sans termes profonds :**
```php
public function findAllWithTranslationsAndPostTypes(): array
{
    return $this->createQueryBuilder('tx')
        ->leftJoin('tx.translations', 'trt')
        ->leftJoin('tx.postTypes', 'pt')
        ->addSelect('trt', 'pt')
        ->orderBy('tx.slug', Order::Ascending->value)
        ->getQuery()
        ->getResult();
}
```

**3. Pagination + collections - batch hydration APRÈS pagination (jamais de JOIN collection avec LIMIT) :**
```php
private function hydratePostCollections(array $posts): void
{
    if ([] === $posts) {
        return;
    }
    $ids = array_map(static fn (Post $post): int => $post->getId(), $posts);

    $this->createQueryBuilder('p')
        ->leftJoin('p.terms', 'terms')
        ->addSelect('terms')
        ->where('p.id IN (:ids)')
        ->setParameter('ids', $ids)
        ->getQuery()
        ->getResult();  // Doctrine identity map met à jour les entités déjà chargées
}
```
Appeler cette méthode juste après `$this->paginate(...)`.

## Pourquoi

- `findAll()` / `findBy()` de Doctrine ne chargent jamais les collections → N+1 garanti dès qu'un serializer accède à une relation.
- Joindre des collections avec `LIMIT` (pagination) fausse les offsets → toujours batch-loader après.
- Les noms `findAllForIndex`, `findAllWithRelations`, `findAllForIndex` sont lisibles dans le `ViewBuilder` sans avoir à ouvrir le repo.

## Comment l'appliquer

1. Dès qu'un `*ViewBuilder` appelle `findAll()` ou `findBy()`, vérifier si le serializer accède à des collections → si oui, ajouter la méthode dédiée.
2. Pour les queries paginées : ne jamais ajouter de JOIN collection dans la query principale, toujours hydration batch post-pagination - pattern détaillé dans [[pattern_two_pass_n_plus_1_hydration]] (cas spécifique ManyToMany + `setMaxResults` qui crée un produit cartésien).
3. Ne pas utiliser `findAll()` / `findBy()` dans un `*ViewBuilder` si le serializer touche une relation.
