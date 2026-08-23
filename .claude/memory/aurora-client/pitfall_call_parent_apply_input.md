# Piège : oublier `parent::applyInput()` lors d'un override

## Symptôme

Tu as override `applyInput()` sur ton `App\Module\Core\Agency\Manager\AgencyManager` pour
hydrater `code`. Tu crées une nouvelle agence : `code` est bien copié,
mais `name` reste vide et tous les autres champs Aurora sont `null`.

## Cause

Override sans appeler `parent::applyInput()` court-circuite l'hydratation
Aurora :

```php
// ❌ MAUVAIS
protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
{
    if ($input instanceof AppAgencyInput && $agency instanceof AppAgency) {
        $agency->setCode($input->getCode());
    }
    // → Aucun setName(), setDescription(), … Tous les champs Aurora restent null !
}
```

## Règle

**Toujours** `parent::applyInput()` AVANT d'ajouter ses propres setters :

```php
// ✅ BON
protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
{
    parent::applyInput($agency, $input);  // hydrate les champs Aurora

    if ($input instanceof AppAgencyInput && $agency instanceof AppAgency) {
        $agency->setCode($input->getCode());  // ajoute le champ custom
    }
}
```

## Variante : on veut SAUTER le parent (cas rare)

Très rarement, on veut **remplacer** complètement la logique Aurora (ex:
slug calculé différemment). Dans ce cas :
- Recopier la logique du parent.
- **Documenter pourquoi** dans un commentaire.
- **Vérifier** que la logique parent ne change pas dans les futures
  versions Aurora (ou s'engager à suivre les changements upstream).

```php
protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
{
    // OVERRIDE TOTAL - on ne veut pas le slug auto d'Aurora car on calcule
    // notre propre slug depuis le code+name. Synchroniser avec parent à
    // chaque update d'aurora-core.
    $agency->setName($input->getName());
    $agency->setSlug($this->customSlugGenerator->forAgency($input));
    if ($input instanceof AppAgencyInput && $agency instanceof AppAgency) {
        $agency->setCode($input->getCode());
    }
}
```

## Symptômes du bug

Ce bug est **silencieux** : pas d'exception, pas d'erreur - juste des
champs vides en base. Souvent détecté seulement à l'usage par un
utilisateur qui rapporte "je remplis le name mais après création il est
vide".

## Vérification

Test unitaire Manager :

```php
public function testCreateHydratesAuroraFieldsAndCustomFields(): void
{
    $input = new AppAgencyInput(name: 'Acme', code: 'ACM-01');
    $agency = $this->manager->create($input);

    self::assertSame('Acme', $agency->getName());      // champ Aurora
    self::assertSame('ACM-01', $agency->getCode());    // champ client
}
```
