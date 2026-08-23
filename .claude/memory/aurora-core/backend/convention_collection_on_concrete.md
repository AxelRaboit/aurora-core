---
name: convention_collection_on_concrete
description: Les Collection Doctrine (ManyToMany owning, OneToMany inverse) vivent sur la classe concrète, pas sur l'Abstract MappedSuperclass.
metadata:
  type: feedback
---

## Règle

Les propriétés `Collection` Doctrine (ManyToMany owning, OneToMany inverse,
ManyToMany inverse) sont déclarées **sur la classe concrète**, jamais sur
`Abstract<Name>` (MappedSuperclass). Le constructeur qui les initialise
(`new ArrayCollection()`) vit lui aussi sur la concrete.

```php
// ✅ Sur Concrete
class ListingCategory extends AbstractListingCategory implements ListingCategoryInterface
{
    /** @var Collection<int, ListingInterface> */
    #[ORM\ManyToMany(targetEntity: ListingInterface::class, mappedBy: 'categories')]
    private Collection $listings;

    /** @var Collection<int, ListingCategoryInterface> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: ListingCategoryInterface::class)]
    private Collection $children;

    public function __construct()
    {
        $this->listings = new ArrayCollection();
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }
}
```

Précédents établis dans cette session : `Listing::$categories`, `Listing::$tags`,
`ListingCategory::$children`, `ListingCategory::$translations`,
`ListingTag::$listings`, `ListingTag::$translations`.

## Pourquoi

Si le constructeur (et donc l'initialisation des collections) vit dans
`Abstract<Name>`, **tout client qui étend doit appeler `parent::__construct()`**,
une seule oubli et les collections sont `null`, l'app crash au premier `add()`.

En gardant constructeur + propriétés sur la concrete :

- Aurora-core garantit lui-même l'init des collections de la concrete par défaut.
- Le client qui substitue (`AppListing extends Listing`) peut overrider le
  constructeur **proprement** (`parent::__construct()` reste optionnel selon
  qu'il garde ou non les collections de base).
- Pas de couplage caché entre MappedSuperclass et concrete.

Cohérent avec [[convention_extensibility]] et [[pitfall_readonly_class]] :
laisser le maximum de flexibilité dans la concrete.

## Comment l'appliquer

1. Ajout d'une relation ManyToMany / OneToMany inverse → la propriété va dans
   `<Name>.php` (concrete). Pas dans `Abstract<Name>.php`.
2. Le getter/setter peut vivre dans `Abstract<Name>` (logique partagée) si la
   propriété est protected. Mais l'init `new ArrayCollection()` reste dans le
   constructor de la concrete.
3. Toujours type-hint les collections avec l'**Interface** de l'entité cible
   (cf [[convention_interface_over_concrete]]).
