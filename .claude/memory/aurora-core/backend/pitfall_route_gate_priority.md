---
name: Route gate priority must be < 8 (after Symfony firewall)
description: Tous les RouteGateSubscriber DOIVENT s'enregistrer en priorité < 8 sur KernelEvents::REQUEST, sinon ils fire avant l'authentification et le per-user gating silencieusement no-op
type: project
---

## Règle

Tout `*RouteGateSubscriber` qui consulte `ModuleAccessChecker`
(directement ou via un `*Context`) **doit** s'enregistrer avec une
priorité **strictement inférieure à 8** sur `KernelEvents::REQUEST`.

Convention : priorité `0`.

```php
public static function getSubscribedEvents(): array
{
    return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
}
```

## Pourquoi

Symfony's `Firewall` (depuis `vendor/symfony/security-http/Firewall.php`)
s'enregistre avec priorité **8** sur `KernelEvents::REQUEST`. Les
priorités plus hautes fire en premier.

- Priorité 16 (notre ancienne valeur) → AVANT le firewall → token non
  encore résolu → `Security::getUser()` retourne `null` →
  `ModuleAccessChecker` ne consulte que le toggle global, jamais le
  `disabled_modules` de l'user.
- Priorité 0 → APRÈS le firewall → user authentifié → per-user OK.

Le bug est **silencieux** : le global setting fonctionne, donc en test
on croit que tout va bien. Le piège se révèle seulement quand on essaie
de désactiver un module per-user. C'est exactement comme ça que le
problème "Dashboard désactivé per-user mais on n'est pas redirigé"
nous a été remonté.

## Comment l'appliquer

### Pour tout nouveau RouteGateSubscriber

```php
final readonly class XxxRouteGateSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }
    // ...
}
```

### Audit en ligne de commande

```bash
# Repérer les route gates avec priorité ≥ 8 (suspects)
grep -rn "KernelEvents::REQUEST.*onKernelRequest" src/ \
  | grep -vE "', 0\]|', [1-7]\]"
```

Tout résultat non-trivial = trap potentiel. À vérifier au cas par cas
(certains usages très spécifiques peuvent vouloir fire avant le firewall,
ex: rewriting d'URL, locale resolution - mais c'est très rare).

## Source

Bug remonté le 2026-05-11 sur le Dashboard : "je désactive le dashboard
pour un user, je devrais être redirigé vers /backend/general/profile, mais
j'atterris quand même sur /backend comme si c'était activé". Cause :
GeneralRouteGateSubscriber priorité 16 → firewall n'avait pas encore
authentifié → ModuleAccessChecker ne voyait pas l'user → seul le global
setting comptait → pas de redirect.

Fix : commit `8e6cc9ac` - 11 RouteGateSubscribers passés en priorité 0
(Core/General, Core/Platform + 9 modules), plus 1 côté aurora-client
(Tracking).
