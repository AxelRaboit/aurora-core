# Piège : oublier d'override `create<X>()` côté client

> **`Agency` n'existe plus.** Le module a été supprimé ; l'exemple filé
> ci-dessous reste juste dans sa forme, mais on ne peut plus aller lire le
> code auquel il renvoie. Le pilote vivant du même pattern est
> `DocumentCategory`, déroulé en entier dans
> `docs/aurora-core/dev/extending_category_pilot.md`.


## Symptôme

Tu as :
- Étendu `Agency` en `App\Module\Core\Agency\Entity\Agency` avec un champ `code`.
- Étendu `AgencyInput` en `App\Module\Core\Agency\Dto\AgencyInput` avec `code`.
- Décoré la `AgencyInputFactory`.
- Override `applyInput()` pour copier `code` du DTO vers l'entité.

Tu crées une nouvelle agence depuis l'admin. Le formulaire envoie `code`,
le DTO le reçoit, mais... après création, `code` est `null` en base et
l'entité Doctrine n'est pas un `App\Module\Core\Agency\Entity\Agency` mais un `Aurora\…\Agency`.

## Cause

Le Manager Aurora fait `new \Aurora\…\Agency()` directement (sans hook
`createAgency()` override par toi). Doctrine persiste cette classe Aurora
qui n'a pas la colonne `code`. Ton `applyInput()` essaie d'appeler
`$agency->setCode(…)` mais `Aurora\…\Agency` n'a pas cette méthode → soit
silent ignore (si typed weakly), soit erreur fatale.

## Règle

**Toujours** override `create<X>()` quand on étend une entité, même si on
ne change rien d'autre :

```php
class AgencyManager extends BaseAgencyManager
{
    protected function createAgency(): AgencyInterface
    {
        return new \App\Module\Core\Agency\Entity\Agency();  // ✅ classe client
    }
}
```

## Pourquoi (rappel)

`resolve_target_entities` ne s'applique qu'aux **relations Doctrine**, pas
aux `new` directs dans le code. Le hook `create<X>()` est l'équivalent
runtime de `resolve_target_entities` pour les instanciations directes.

Cf [pitfall_resolve_target_entities.md](../pitfall_resolve_target_entities.md)
côté core pour le contexte complet.

## Vérification

Après extension, vérifier :

```bash
# Le service AgencyManagerInterface doit pointer vers AppAgencyManager
php bin/console debug:container Aurora\\Core\\Agency\\Manager\\AgencyManagerInterface
```

Et faire un test :

```php
$agency = $manager->create(new App\Module\Core\Agency\Dto\AgencyInput(name: 'Test', code: 'X'));
self::assertInstanceOf(App\Module\Core\Agency\Entity\Agency::class, $agency);  // doit passer
self::assertSame('X', $agency->getCode());  // doit passer
```
