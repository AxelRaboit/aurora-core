# Pattern DTO + Factory + AsAlias

## Règle

Tout DTO d'entrée consommé par un controller a **3 fichiers** + une
implémentation concrète :

```
Aurora\<Module>\<Feature>\Dto\
├── <Name>InputInterface.php          // contrat des getters
├── <Name>InputFactoryInterface.php   // contrat de la factory (fromArray)
├── <Name>InputFactory.php            // implémentation #[AsAlias(<Name>InputFactoryInterface::class)]
└── <Name>Input.php                   // class { public readonly … } implements <Name>InputInterface
```

## Pourquoi

- Le controller ne fait jamais `new <Name>Input(...)` ni `<Name>Input::fromArray(...)`.
  Il injecte `<Name>InputFactoryInterface` et appelle `$this->inputFactory->fromArray($data)`.
- Le client peut décorer la factory pour ajouter ses propres champs au DTO
  étendu sans toucher au controller Aurora.
- Pas de méthode statique `fromArray()` sur le DTO concret : si un client
  étend `<Name>Input`, il ne peut pas overrider une méthode statique.

## Comment l'appliquer

1. Créer les 4 fichiers (Interface + FactoryInterface + Factory + Input).
2. Marquer la Factory avec `#[AsAlias(<Name>InputFactoryInterface::class)]`
   (Symfony 7+).
3. Le controller injecte l'**Interface** de la factory, jamais la concrete.
4. **Pas** de `static fromArray()` dans `<Name>Input` - la responsabilité
   est dans `<Name>InputFactory`.
5. **Pas** `readonly class <Name>Input` - utiliser `class <Name>Input
   implements <Name>InputInterface { public readonly string $foo; … }`. Cf
   `pitfall_readonly_class.md`.

## Exception : sub-DTOs

Les sub-DTOs (consommés en interne par un DTO racine, jamais par un
controller direct) restent `final readonly class` sans Interface ni
Factory. Exemples : `PostTranslationInput` (sub-DTO de `PostInput`),
`InvoiceLineDraft` (sub-DTO de `InvoiceDraft`), `ReorderFieldsInput`
(action sub-flow). Le client qui veut étendre une sub-DTO étend le DTO
racine et fournit ses propres sub-DTO via la factory qu'il décore.

## Squelette

```php
// <Name>InputInterface.php
interface <Name>InputInterface
{
    public function getName(): string;
    // … autres getters
}

// <Name>InputFactoryInterface.php
interface <Name>InputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): <Name>InputInterface;
}

// <Name>InputFactory.php
#[AsAlias(<Name>InputFactoryInterface::class)]
class <Name>InputFactory implements <Name>InputFactoryInterface
{
    public function fromArray(array $data): <Name>InputInterface
    {
        return new <Name>Input(
            name: Str::trimFromArray($data, 'name'),
            // …
        );
    }
}

// <Name>Input.php
class <Name>Input implements <Name>InputInterface
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $name = '',
        // …
    ) {}

    public function getName(): string { return $this->name; }
    // …
}
```
