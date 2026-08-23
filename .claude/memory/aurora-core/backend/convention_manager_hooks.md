# Hooks Manager - les 3 familles

## Règle

Tout `<Name>Manager` instrumenté expose 3 familles de hooks `protected` :

### 1. Instanciation - `create<X>(): <X>Interface`

**Un hook par classe** que le Manager instancie. Sans exception.

```php
protected function createAgency(): AgencyInterface { return new Agency(); }
protected function createOrderLine(): OrderLineInterface { return new OrderLine(); }
```

Le client override pour retourner sa classe étendue :
```php
protected function createAgency(): AgencyInterface { return new App\Module\Core\Agency\Entity\Agency(); }
```

### 2. Hydratation - `applyInput(<Name>Interface, <Name>InputInterface): void`

**Requis par défaut** sauf variante "Manager à hooks multiples" (cf
`decision_variant_user_style.md`). Centralise la copie DTO → entité.

```php
protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
{
    $agency->setName($input->getName());
}
```

Le client override en faisant `parent::applyInput()` puis ajoute ses
champs :
```php
protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
{
    parent::applyInput($agency, $input);
    $agency->setCode($input->getCode());
}
```

### 3. Audit - `auditCreated/Updated/Deleted` + `auditPayload`

```php
protected function auditCreated(AgencyInterface $agency): void
{
    $this->auditLogger->log('core', 'agency.created', 'Agency', $agency->getId(), $this->auditPayload($agency));
}
// idem auditUpdated, auditDeleted

protected function auditPayload(AgencyInterface $agency): array
{
    return ['name' => $agency->getName()];
}
```

Pour les **domain events** (validate, paid, stage_changed, …) qui ne
suivent pas la triplet CRUD standard, garder l'appel `auditLogger->log()`
inline mais splat-merger `$this->auditPayload()` pour rester extensible :

```php
$this->auditLogger->log('crm', 'deal.stage_changed', 'Deal', $deal->getId(), [
    ...$this->auditPayload($deal),
    'from' => $oldStage->value,
    'to' => $newStage->value,
]);
```

## Pourquoi

- **createX** : `resolve_target_entities` ne s'applique qu'aux relations
  Doctrine, pas à `new`. Sans hook, un client qui étend `Agency` ne peut
  pas faire instancier sa classe.
- **applyInput** : sépare la mécanique (persist + flush + audit) de
  l'hydratation (extensible). Le client n'override que ce qu'il change.
- **auditPayload** : le client qui ajoute `code` veut que tous les audit
  logs (create/update/delete + domain events) le contiennent. Sans payload
  hook, il devrait recopier le flow complet.

## Comment l'appliquer

Quand on écrit un nouveau Manager :
1. Lister toutes les classes que le Manager instancie → un `create<X>()`
   chacune.
2. Si flow create+update simple via DTO unique → `applyInput()`.
3. Si AuditLogger est utilisé → `auditCreated/Updated/Deleted` + `auditPayload`.
   Pour les domain events, garder inline mais splat-merger le payload.

## Doc canonique

Section 3 (couche 3 du pattern) de
[`docs/aurora-core/dev/entity_extensibility_convention.md`](../../docs/aurora-core/dev/entity_extensibility_convention.md).
