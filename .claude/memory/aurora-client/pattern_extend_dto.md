# Pattern : étendre un DTO Input

> **`Agency` n'existe plus.** Le module a été supprimé ; l'exemple filé
> ci-dessous reste juste dans sa forme, mais on ne peut plus aller lire le
> code auquel il renvoie. Le pilote vivant du même pattern est
> `DocumentCategory`, déroulé en entier dans
> `docs/aurora-core/dev/extending_category_pilot.md`.


## Règle

Pour ajouter un champ au DTO d'entrée (ex: `code` sur `AgencyInput`), 2 étapes :

1. Étendre `Aurora\…\<Name>Input` avec les nouveaux champs `public readonly`.
2. Étendre `Aurora\…\<Name>InputFactory` et override `fromArray()` :
   `parent::fromArray($data)` puis hydrater les champs custom.

## Pourquoi

- Le DTO Aurora est `class { public readonly … }` non-`final`, conçu pour
  être étendu.
- La factory Aurora a `#[AsAlias(<Name>InputFactoryInterface::class)]` ;
  décorer = remplacer le service routé par l'interface.
- Le controller Aurora type-hint l'**Interface** de la factory, donc
  injecte automatiquement la version étendue.

## Comment l'appliquer

### 1. DTO étendu

```php
namespace App\Module\Platform\Agency\Dto;

use Aurora\Module\Platform\Agency\Dto\AgencyInput as BaseAgencyInput;
use Aurora\Module\Platform\Agency\Dto\AgencyInputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class AgencyInput extends BaseAgencyInput
{
    public function __construct(
        string $name = '',
        #[Assert\Length(max: 50)]
        public readonly ?string $code = null,
    ) {
        parent::__construct($name);
    }

    public function getCode(): ?string
    {
        return $this->code;
    }
}
```

**Note** : si le DTO étendu doit être consommé par du code qui type-hint
`AgencyInputInterface`, on peut soit :
- Étendre l'interface aussi (`AppAgencyInputInterface extends AgencyInputInterface`)
- Ou utiliser `instanceof App\Module\Platform\Agency\Dto\AgencyInput` quand on a besoin de
  `getCode()`.

### 2. Factory étendue

```php
namespace App\Module\Platform\Agency\Dto;

use Aurora\Module\Platform\Agency\Dto\AgencyInputFactory as BaseAgencyInputFactory;
use Aurora\Module\Platform\Agency\Dto\AgencyInputFactoryInterface;
use Aurora\Module\Platform\Agency\Dto\AgencyInputInterface;
use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencyInputFactoryInterface::class)]
class AgencyInputFactory extends BaseAgencyInputFactory
{
    public function fromArray(array $data): AgencyInputInterface
    {
        return new AgencyInput(
            name: Str::trimFromArray($data, 'name'),
            code: Str::trimOrNullFromArray($data, 'code'),
        );
    }
}
```

**Important** : `#[AsAlias(<Name>InputFactoryInterface::class)]` sur la
sous-classe **remplace** automatiquement la factory Aurora dans le
container Symfony.

### Variante : décoration plutôt qu'extension

Si on veut **enrichir** plutôt que **remplacer** complètement :

```php
#[AsDecorator(decorates: AgencyInputFactoryInterface::class)]
class AgencyInputFactoryDecorator implements AgencyInputFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private AgencyInputFactoryInterface $inner,
    ) {}

    public function fromArray(array $data): AgencyInputInterface
    {
        $base = $this->inner->fromArray($data);
        // mais ici on ne peut pas reconstruire un DTO étendu sans recopier…
        return $base;
    }
}
```

**Pour les Inputs, l'extension directe via `#[AsAlias]` est plus simple
que la décoration**. La décoration a plus de sens pour les Managers (où
on veut intercepter avant/après une action métier).

## Étapes suivantes

- Pour étendre le Manager (utiliser `getCode()` du DTO étendu dans
  `applyInput`) : [pattern_extend_manager.md](pattern_extend_manager.md)

## Pièges

- Si on oublie `#[AsAlias]` sur la factory étendue, Symfony continuera
  d'autowirer la factory Aurora → le `code` sera ignoré.
- Vérifier avec `php bin/console debug:container | grep AgencyInputFactory`
  que l'alias pointe bien vers la classe client.
