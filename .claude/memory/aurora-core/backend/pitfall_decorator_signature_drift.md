---
name: pitfall-decorator-signature-drift
description: Étendre une méthode de Manager sans mettre à jour l'interface ET les decorators fait silencieusement perdre les nouveaux args (PHP les drop sans erreur)
metadata:
  type: feedback
---

## Règle

Quand tu ajoutes un paramètre à une méthode d'un Manager (ou de n'importe
quelle classe décorée), tu DOIS dans le **même commit** :

1. Mettre à jour l'**interface** (`<X>ManagerInterface`)
2. Mettre à jour **chaque decorator** qui wrappe cette interface
3. S'assurer que chaque decorator **forward** le nouvel arg à `$this->inner`

Si tu oublies (2) ou (3), PHP n'émettra **aucune erreur** - il drop
silencieusement les args en trop côté appelant. Les tests unitaires qui
ciblent le Manager direct (sans decorator) passent. Mais en runtime
(controller → service via DI), le decorator est en première ligne et la
donnée disparaît.

## Why

PHP est permissif avec les args en trop au call-site : appeler une méthode
3-args avec 4 args ne lève pas d'erreur, le 4e est juste ignoré. C'est
asymétrique par rapport à une méthode strict-typed côté receveur (qui
elle, lèverait `TooFewArguments` s'il en manquait).

Donc :
```php
// Controller
$this->userManager->updateSidemenuPreferences($user, [], [], $colors);

// Decorator (interface 3-args, jamais mis à jour)
public function updateSidemenuPreferences(User $user, array $a, array $b): void {
    $this->inner->updateSidemenuPreferences($user, $a, $b); // ← $colors perdu
}
```

→ Le controller compile. Aucun warning. Les tests sur `UserManager` direct
passent. Mais en prod la 4e valeur n'arrive jamais en DB.

## How to apply

Pour chaque ajout d'arg à une méthode d'un Manager :

1. **Grep `implements <X>ManagerInterface`** → liste des implémenteurs,
   tous doivent avoir la nouvelle signature.
2. **Grep `class .*Decorator\b` dans le module** → chaque decorator
   doit aussi être mis à jour ET forward le nouvel arg.
3. **Tester via le controller** (pas juste le test unit du Manager) - un
   test d'intégration ou un test manuel via l'UI attrape ce cas.

Cas concret : commit qui a introduit le bug - ajout de `$navSectionColors`
à `UserManager::updateSidemenuPreferences()` (commit `9137db67`) sans
mettre à jour `UserManagerInterface` ni `AuditUserManagerDecorator`. Les 5
tests d'intégration du Manager passaient. Le bug s'est manifesté en UI :
le picker remontait bien la valeur, le controller la passait au Manager,
mais le decorator l'absorbait silencieusement → DB `[]`. Fix dans le commit
suivant : sync de l'interface + signature 4-args sur le decorator +
forward de `$navSectionColors` à `$this->inner`.

Cf. aussi [[convention_interface_over_concrete]] et
[[pitfall_type_hint_interface]] pour la cohérence type-hint qui fait que
le decorator est bien en première ligne.
