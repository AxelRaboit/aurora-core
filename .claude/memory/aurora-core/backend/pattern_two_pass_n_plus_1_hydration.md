---
name: pattern_two_pass_n_plus_1_hydration
description: Pagination + collections ManyToMany - paginer d'abord, puis hydrater les collections via une 2e query sur les ids.
metadata:
  type: feedback
---

## Règle

Quand un repository **paginate** (`setMaxResults`/`setFirstResult`) **et** que
le serializer consomme ensuite une collection ManyToMany (ou OneToMany-many),
ne **JAMAIS** joindre la collection dans la query principale paginée - ça
produit un produit cartésien qui fausse le slice (n entités × m liens = bien
plus que `pageSize` lignes ; le `setMaxResults` coupe au milieu d'une entité
hydratée).

### Pattern correct (two-pass)

```php
public function findVisibleByCategoryIdsPaginated(array $categoryIds, int $page, int $pageSize): array
{
    // 1) Page query : pagination + ToOne joins uniquement
    $qb = $this->createQueryBuilder('l')
        ->leftJoin('l.product', 'p')->addSelect('p')           // ToOne OK
        ->innerJoin('l.categories', 'c')
        ->where('c.id IN (:ids)')->setParameter('ids', $categoryIds)
        ->andWhere('l.visible = true')
        ->setFirstResult(($page - 1) * $pageSize)
        ->setMaxResults($pageSize);

    $listings = $qb->getQuery()->getResult();
    if ([] === $listings) {
        return [];
    }

    // 2) Hydration pass : repeuple les ManyToMany via l'identity map
    $ids = array_map(static fn (ListingInterface $l): int => $l->getId(), $listings);
    $this->createQueryBuilder('l2')
        ->leftJoin('l2.categories', 'cat')->addSelect('cat')
        ->leftJoin('l2.tags', 'tag')->addSelect('tag')
        ->where('l2.id IN (:ids)')->setParameter('ids', $ids)
        ->getQuery()
        ->getResult(); // résultat ignoré - Doctrine peuple l'identity map

    return $listings;
}
```

Le sérialiseur lit ensuite `$listing->getCategories()` sans N+1 et sans
requête supplémentaire.

## Pourquoi

- Doctrine ne sait pas paginer correctement une query qui contient un join
  collection : `LIMIT 10` peut couper l'entité 5 au milieu de ses tags.
- La 2ᵉ passe (`findBy` ou QueryBuilder par ids) sur les **ids de la page
  seulement** alimente l'identity map → les entités déjà chargées en passe 1
  voient leurs collections fetchées.

Précédents établis cette session :
`ListingRepository::findVisibleByCategoryIdsPaginated`,
`ListingRepository::findVisibleByTagIdsPaginated`.

Lié : [[convention_repository_eager_loading]] (le pattern y est documenté
sous "Pagination + collections - batch hydration"). Cette mémoire est le
zoom détaillé avec exemple concret.

## Comment l'appliquer

1. Le repo paginate + le serializer touche une collection → split en 2 passes.
2. La passe 1 peut joindre les ToOne (`leftJoin` + `addSelect`) - pas de
   cartésien.
3. La passe 2 charge **toutes** les collections nécessaires en un coup
   (multiple `leftJoin` + `addSelect`). Ne pas faire une passe par collection.
4. Si une collection profonde est touchée (collection of collection), 3ᵉ
   passe sur les ids feuilles.
