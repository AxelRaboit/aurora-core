# Convention : `Order::Ascending->value` / `Order::Descending->value` dans les Repositories

## Règle

Dans les Repositories Doctrine, utiliser **toujours** l'enum
`Doctrine\Common\Collections\Order` plutôt que les chaînes `'ASC'` /
`'DESC'`.

```php
use Doctrine\Common\Collections\Order;

// ✅ BON
$queryBuilder->orderBy('a.name', Order::Ascending->value);
$queryBuilder->orderBy('a.createdAt', Order::Descending->value);

// ❌ MAUVAIS
$queryBuilder->orderBy('a.name', 'ASC');
$queryBuilder->orderBy('a.createdAt', 'DESC');
```

## Pourquoi

- **Type safety** : l'IDE complète automatiquement, l'enum est typo-proof.
- **Refacto-friendly** : si Doctrine renomme jamais (peu probable mais
  possible), l'IDE détecte les usages.
- **Cohérence avec le reste du codebase** : on utilise déjà des enums
  partout pour les statuts, les rôles, etc. Pas de raison d'avoir des
  magic strings pour l'order.
- **Découvrabilité** : en lisant `Order::Ascending`, on sait directement
  que c'est l'enum Doctrine. `'ASC'` ne dit rien.

## Comment l'appliquer

### Imports

```php
use Doctrine\Common\Collections\Order;
```

### Dans les méthodes de Repository

```php
public function findAllAlphabetical(): array
{
    return $this->createQueryBuilder('a')
        ->orderBy('a.name', Order::Ascending->value)
        ->getQuery()->getResult();
}

public function findRecentFirst(int $limit = 50): array
{
    return $this->createQueryBuilder('a')
        ->orderBy('a.createdAt', Order::Descending->value)
        ->setMaxResults($limit)
        ->getQuery()->getResult();
}
```

### Pour les ordres dynamiques

```php
public function findPaginated(int $page, string $direction = 'asc'): array
{
    $order = 'desc' === mb_strtolower($direction)
        ? Order::Descending->value
        : Order::Ascending->value;

    return $this->createQueryBuilder('a')
        ->orderBy('a.name', $order)
        ->getQuery()->getResult();
}
```

### Avec `findBy()` aussi

```php
$repository->findBy(['status' => 'active'], ['createdAt' => Order::Descending->value]);
```

## Exception : DQL inline avec ordre dans la string

Si tu écris du DQL avec l'ordre directement dans la string, c'est OK :

```php
// OK car partie d'une string DQL, pas un argument séparé
$dql = 'SELECT a FROM Agency a ORDER BY a.name ASC';
```

Mais préférer le QueryBuilder qui permet d'utiliser l'enum proprement.

## Vérification

Pour trouver les usages restants à migrer :

```bash
grep -rn "->orderBy.*'ASC'\|->orderBy.*'DESC'" src/ --include='*.php'
grep -rn "=> 'ASC'\|=> 'DESC'" src/ --include='*.php'  # findBy([…], ['col' => 'ASC'])
```

## Source

Convention demandée par l'utilisateur. À appliquer à tous les Repositories
existants quand on les touche, et **systématiquement** sur les nouveaux.
