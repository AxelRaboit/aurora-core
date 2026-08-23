---
name: convention-fixture-group-demo
description: Client fixtures must implement FixtureGroupInterface with getGroups(): ['demo'] to be loaded by `make demo`. Without it they are silently skipped.
metadata:
  type: feedback
---

Toute classe de fixtures dans un projet client (`src/DataFixtures/...`) qui doit
être seedée par `make demo` doit explicitement déclarer son appartenance au
groupe `demo` via `FixtureGroupInterface` :

```php
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

class WeldingFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['demo'];
    }
    // ...
}
```

**Why:** `make demo` invoque `doctrine:fixtures:load --group=demo`. Doctrine ne
sélectionne que les fixtures qui implémentent `FixtureGroupInterface` ET qui
renvoient `'demo'` parmi leurs groupes. Une fixture sans ce tag est
silencieusement ignorée par le filtre - elle ne provoque pas d'erreur, juste
elle ne se charge pas. Le piège est subtil : `fixtures:load` (sans
`--group=demo`) la chargera, donc en dev rapide on ne voit pas le bug ;
seul le workflow standard `make demo` l'occulte. Confirmé sur WeldingFixtures
le 2026-05-24 - la classe seedait correctement à la main mais était absente
de `make demo`.

**How to apply:** dès qu'une nouvelle classe de fixtures est créée dans un
projet client (ou via `/add-module`), vérifier qu'elle implémente
`FixtureGroupInterface` et déclare le bon groupe. Si le seed doit aussi
exister hors-demo (e.g. fixtures de test ou initiales obligatoires), retourner
plusieurs groupes : `return ['demo', 'test']`. Les fixtures aurora-core
servent de référence - toutes les `DemoFixtures` du bundle implémentent déjà
cette interface.

Voir aussi [[convention-module-structure]] pour le placement général.
