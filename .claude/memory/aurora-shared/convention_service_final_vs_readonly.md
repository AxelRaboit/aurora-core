---
name: convention-service-final-vs-readonly
description: Aurora services are `final readonly` by default - but thin shells (notifications, webhooks, audit-style wrappers) demote to just `readonly` so they stay mock-able in unit tests. Use the decision matrix here.
metadata:
  type: feedback
---

**Default** : Aurora services live in `src/.../Service/` and ship as
`final readonly class`. The `readonly` keyword makes every property
immutable post-construction (no `setX()` allowed); the `final` keyword
forbids subclassing.

This is the right shape for **leaf, complete-on-their-own** services
that the rest of the app shouldn't substitute :
- pure-compute services (`PostTextExtractor`, `DocumentUrlGenerator`,
  `SluggerInterface` adapters)
- data-shape services (`PostAccessService`, `BlocksRenderer`)
- helpers that only the framework instantiates (`DocumentUrlGenerator`,
  encryption services)

**Exception** : a service that's a **thin shell over an external
side-effect** - mail dispatch, HTTP webhook, audit log, event emit -
should be just `readonly class` (drop `final`). Reasons:

1. **Mock-ability** : PHPUnit's `createMock(X)` can't double a `final`
   class. Manager-level unit tests need to verify "the side-effect
   was dispatched" without actually hitting the mailer / HTTP / audit
   pipeline. If the dispatcher is `final`, the test author has to
   either skip that assertion (loses coverage) or run an integration
   test (slower + needs real DB / mailer).

2. **Substitution** : a client deployment may want to swap the
   dispatcher (different mail backend, different webhook signing
   scheme). `final` blocks that without justification - these classes
   have no immutability invariant tied to the class identity.

3. **Pattern precedent** : `AuditLogger` itself is `readonly class`
   (no `final`). The 4 thin-shell services I demoted in the test
   coverage work - `CommentNotificationService`,
   `CommentReactionRepository`, `FormNotificationService`,
   `FormWebhookService` - all match this shape.

**Decision matrix** :

| Service shape                                            | Class declaration       |
|----------------------------------------------------------|-------------------------|
| Pure compute / extraction (no side effects)              | `final readonly class`  |
| Data-shape helper (no I/O, no async)                     | `final readonly class`  |
| Dispatches mail / HTTP / event / audit (thin shell)      | `readonly class`        |
| Writes via Doctrine but is a Service not a Manager       | `readonly class`        |
| Repository (Doctrine-backed)                             | `class` (NOT `final`, no `readonly`) |

**Why** : the test surface tells you. If you find yourself reaching
for `createMock(MyService::class)` in a Manager test and it fails
with `ClassIsFinalException`, that's the linter signal saying "this
service is shell, not core" - demote `final`.

**Drive-by enforcement** :

```bash
# Find candidate demotions: final readonly services with the *Notification* or *Webhook* suffix.
grep -rln "^final readonly class .*Notification\b" src/
grep -rln "^final readonly class .*Webhook\b" src/
```

Voir aussi [[convention-thin-controller]] (les controllers délèguent au
Manager qui délègue au Service - chaque niveau a sa propre raison
d'être final ou pas).
