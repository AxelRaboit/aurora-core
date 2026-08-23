---
name: Convention de nommage des tables côté client
description: Les tables client doivent être préfixées app_ (jamais core_), les séquences seq_app_*
type: feedback
---

## Règle

Toute table créée côté aurora-client (nouvelle entité ou substitution d'une
entité Aurora) doit être préfixée `app_`. Les séquences PK correspondantes
suivent le même préfixe : `seq_app_<entity>_id`.

**Exemples corrects :**
- Table : `app_agencies`, `app_deals`, `app_contracts`
- Séquence : `seq_app_agency_id`, `seq_app_deal_id`

**Exemples incorrects :**
- `client_agencies`, `seq_client_agency_id` - préfixe `client_` non standard
- `core_agencies` - réservé à Aurora-core, collision garantie

## Pourquoi

- Aurora-core réserve le préfixe `core_` pour toutes ses tables et `seq_core_*`
  pour ses séquences. Un client qui utilise le même préfixe provoquerait des
  collisions silencieuses difficiles à déboguer.
- Le préfixe `app_` est idiomatique Symfony (namespace `App\`), cohérent avec
  l'organisation du projet client.
- Les séquences `seq_app_*` sont exclues du `schema_filter` Aurora qui cible
  `seq_core_*` - elles ne perturbent pas les `doctrine:migrations:diff`
  d'aurora-core.

## Comment l'appliquer

Dans l'entité concrète cliente :

```php
#[ORM\Entity(repositoryClass: AppAgencyRepository::class)]
#[ORM\Table(name: 'app_agencies')]
class Agency extends AbstractAgency implements AgencyInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_agency_id', initialValue: 1)]
    #[ORM\Column]
    protected ?int $id = null;
}
```

La règle s'applique aussi aux entités entièrement nouvelles (pas de substitution)
créées côté client : elles doivent toujours porter le préfixe `app_`, jamais
`core_`.
