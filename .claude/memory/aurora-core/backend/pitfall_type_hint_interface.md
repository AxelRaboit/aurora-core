# Piège : décoration n'agit que sur le type-hint interface

## Règle

Pour qu'une décoration `#[AsDecorator]` ait effet, **le consommateur doit
type-hint l'interface**, pas la classe concrète.

## Pourquoi

Symfony résout les services par le nom du paramètre type-hinté. Si un
controller fait :

```php
public function __construct(
    private readonly AgencyManager $agencyManager,  // ❌ classe concrète
) {}
```

Et qu'un client tente de décorer :
```php
#[AsDecorator(decorates: AgencyManagerInterface::class)]
class AppAgencyManagerDecorator implements AgencyManagerInterface { … }
```

→ Le decorator est créé et substitue le service `AgencyManagerInterface`.
**Mais le controller n'utilise pas ce service** - il a injecté
`AgencyManager` (la concrete) directement, qui reste non-décorée.

## Comment l'appliquer

### Toujours type-hint l'interface

```php
public function __construct(
    private readonly AgencyManagerInterface $agencyManager,  // ✅
    private readonly AgencyInputFactoryInterface $agencyInputFactory,  // ✅
    private readonly AgencySerializerInterface $agencySerializer,  // ✅
) {}
```

Le `#[AsAlias(AgencyManagerInterface::class)]` sur la concrete classe
fait que l'autowiring de l'interface route vers la concrete par défaut. Si
un client décore l'interface, le controller récupère le décorateur
automatiquement.

### Vérifications

Lors d'un audit / refacto :

```bash
# Trouver les controllers qui type-hint des classes concrètes
grep -rn "private readonly.*Manager \\\$" src/Module/ src/Core/ \
    | grep -v "Interface\|Repository\|EntityManager"

# Devrait sortir aucun résultat sur les Managers instrumentés
```

### Audit des 24 entités

L'audit a vérifié que tous les controllers et ViewBuilders type-hint les
interfaces pour les 4 services suivants :
- `<Name>ManagerInterface`
- `<Name>InputFactoryInterface`
- `<Name>SerializerInterface`
- (Repository : exception assumée - type-hint concrete, cf
  `decision_repository_no_interface.md`)

## Source

Audit Octobre 2025. Confirmé pendant la refacto Project (qui type-hintait
les 11 Managers en concrete avant la refacto, ce qui aurait empêché toute
décoration côté client).
