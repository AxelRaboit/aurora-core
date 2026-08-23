# Pattern : étendre une entité Aurora avec un champ custom

## Règle

Pour ajouter un champ à une entité Aurora (ex: `code` sur `Agency`), 4 étapes :

1. Créer `App\Module\<Mirror>\<Name>\Entity\<Name>` qui étend
   `Aurora\…\Abstract<Name>` et `implements <Name>Interface`.
   Le chemin miroir reprend le namespace Aurora : une entité de
   `Aurora\Module\Platform\Agency` va dans `src/Module/Platform/Agency/Entity/`.
2. Ajouter les colonnes Doctrine + getters/setters pour les champs custom.
3. Inscrire dans `config/packages/doctrine.yaml` →
   `resolve_target_entities`.
4. Migration Doctrine.

## Pourquoi

- L'**Interface** Aurora reste stable (pas modifiée).
- L'**Abstract** Aurora apporte les colonnes communes (MappedSuperclass).
- La concrete client a son propre `id` + sequence + champs custom.
- `resolve_target_entities` route les relations Doctrine vers la classe
  client (mais pas les `new` directs - cf
  `pitfall_create_hook_required.md`).

## Comment l'appliquer

### 1. Entité concrète client

Chemin : `src/Module/Platform/Agency/Entity/Agency.php`
(miroir du namespace Aurora `Aurora\Module\Platform\Agency`)

```php
namespace App\Module\Platform\Agency\Entity;

use Aurora\Module\Platform\Agency\Entity\AbstractAgency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Repository\AgencyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: 'app_agencies')]
class Agency extends AbstractAgency implements AgencyInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_agency_id', initialValue: 1)]
    protected ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    protected ?string $code = null;

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;
        return $this;
    }
}
```

**Notes** :
- Namespace client : `App\Module\<Mirror>\<Name>\Entity\<Name>`.
- Sequence préfixée `seq_app_*`, table préfixée `app_`.
- `protected` pour permettre une éventuelle sous-extension.

### 2. resolve_target_entities

Dans `config/packages/doctrine.yaml` (pas dans `AuroraBundle.php`) :

```yaml
doctrine:
    orm:
        resolve_target_entities:
            Aurora\Module\Platform\Agency\Entity\AgencyInterface: App\Module\Platform\Agency\Entity\Agency
```

### 3. Migration

```bash
php bin/console doctrine:migrations:diff
# Vérifier la migration générée
php bin/console doctrine:migrations:migrate
```

## Étapes suivantes

- Pour étendre le DTO d'entrée : [pattern_extend_dto.md](pattern_extend_dto.md)
- Pour étendre le Manager (et faire instancier la classe client) :
  [pattern_extend_manager.md](pattern_extend_manager.md)
- Pour étendre le Serializer : [pattern_extend_serializer.md](pattern_extend_serializer.md)
- Pour étendre la Vue : [pattern_extend_vue.md](pattern_extend_vue.md)

Vue d'ensemble : [checklist_extend_full_entity.md](checklist_extend_full_entity.md).
