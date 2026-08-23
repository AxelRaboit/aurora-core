# Audit log - payload extensible via auditPayload()

## Règle

Tout appel `$this->auditLogger->log(…)` dans un Manager instrumenté doit
splat-merger `$this->auditPayload($entity)` dans son tableau de payload :

```php
$this->auditLogger->log('crm', 'deal.stage_changed', 'Deal', $deal->getId(), [
    ...$this->auditPayload($deal),
    'from' => $oldStage,
    'to' => $newStage,
]);
```

## Pourquoi

Sans cette règle, si un client ajoute le champ `code` à `Deal`, il devrait
override **chaque** méthode publique du Manager pour injecter son champ
dans les audit logs des domain events (created/updated/deleted *et*
stage_changed/won/lost/etc.). Avec splat-merge, override `auditPayload()`
**une fois** suffit :

```php
// côté client
protected function auditPayload(DealInterface $deal): array
{
    return [...parent::auditPayload($deal), 'code' => $deal->getCode()];
}
```

→ tous les audit logs Deal incluent désormais `code`, sans toucher aux
méthodes publiques.

## Comment l'appliquer

### Pour les events CRUD standard (created/updated/deleted)

Utiliser les hooks dédiés `auditCreated/Updated/Deleted` qui appellent
`auditPayload()` directement - pas de splat-merge nécessaire :

```php
protected function auditCreated(DealInterface $deal): void
{
    $this->auditLogger->log('crm', 'deal.created', 'Deal', $deal->getId(), $this->auditPayload($deal));
}
```

### Pour les domain events (stage_changed, paid, validated, …)

Garder l'appel inline mais splat-merger :

```php
$this->auditLogger->log('billing', 'invoice.validated', 'Invoice', $invoice->getId(),
    $this->auditPayload($invoice)  // si seulement le payload de base
);

// ou avec champs spécifiques au domain event :
$this->auditLogger->log('ecommerce', 'order.cancelled', 'Order', $order->getId(), [
    ...$this->auditPayload($order),
    'previousStatus' => $previous->value,
    'refundIssued' => $refundIssued,
]);
```

## Anti-pattern

❌ **Ne pas faire** :
```php
$this->auditLogger->log('crm', 'deal.stage_changed', 'Deal', $deal->getId(), [
    'name' => $deal->getName(),  // hardcodé, pas extensible
    'from' => $oldStage,
    'to' => $newStage,
]);
```

✅ **Faire** :
```php
$this->auditLogger->log('crm', 'deal.stage_changed', 'Deal', $deal->getId(), [
    ...$this->auditPayload($deal),  // extensible
    'from' => $oldStage,
    'to' => $newStage,
]);
```

## Source

Cette règle est issue de l'audit post-Editorial qui a révélé que les domain
events échappaient à l'extensibilité du payload. Cf
[`decision_4_hard_rules.md`](decision_4_hard_rules.md) règle 3.
