# Pattern : étendre un Serializer

## Règle

Override `serialize()` avec spread `parent::serialize()` puis ajouter les
champs custom. Décorer via `#[AsAlias(<Name>SerializerInterface::class)]`.

## Comment l'appliquer

```php
namespace App\Module\Platform\Agency\Serializer;

use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializer as BaseAgencySerializer;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializerInterface;
use App\Module\Platform\Agency\Entity\Agency as AppAgency;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AgencySerializerInterface::class)]
class AgencySerializer extends BaseAgencySerializer
{
    public function serialize(AgencyInterface $agency): array
    {
        $payload = parent::serialize($agency);

        if ($agency instanceof AppAgency) {
            $payload['code'] = $agency->getCode();
        }

        return $payload;
    }
}
```

## Pourquoi

- Le payload de base (`id`, `name`, `createdAt`, …) reste géré par Aurora.
- Le client n'ajoute que ses champs custom.
- Si Aurora ajoute un champ au payload de base (ex: nouvelle propriété),
  il est automatiquement inclus côté client sans modification.

## Pièges

### 1. Ne pas overrider tout le payload

```php
// ❌ MAUVAIS - perd les champs Aurora si Aurora évolue
public function serialize(AgencyInterface $agency): array
{
    return [
        'id' => $agency->getId(),
        'name' => $agency->getName(),
        'code' => $agency->getCode(),  // pas extensible
    ];
}

// ✅ BON
public function serialize(AgencyInterface $agency): array
{
    return [
        ...parent::serialize($agency),
        'code' => $agency->getCode(),
    ];
}
```

### 2. `instanceof` pour les types étendus

Comme pour Manager, le `serialize()` reçoit l'interface Aurora. Pour
accéder à `getCode()`, vérifier `instanceof AppAgency`.

## Cas particulier : Serializer avec multiples méthodes publiques

Certains Serializers exposent plusieurs méthodes (`serialize`,
`serializeFull`, `serializeListPayload`, etc - ex: `MenuSerializer`,
`GallerySerializer`). Override seulement celles qu'on veut enrichir :

```php
class MenuSerializer extends BaseMenuSerializer
{
    public function serialize(MenuInterface $menu): array
    {
        return [
            ...parent::serialize($menu),
            'customField' => …,
        ];
    }

    // Ne pas override serializeFull si on n'a rien à ajouter - il appelle
    // serialize() en interne, donc le custom field sera inclus
    // automatiquement dans le payload "full".
}
```
