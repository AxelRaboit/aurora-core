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

## Où les poser : `src/DataFixtures/`, jamais sous `src/Module/`

`src/DataFixtures/` est le seul emplacement sûr. `config/routes.yaml` importe
`../src/Module/` en `type: attribute`, et `config/packages/doctrine.yaml` mappe
le même répertoire : les deux le parcourent fichier par fichier et
**autoloadent chaque classe** avant de décider quoi en faire. Une fixture y
étend `Doctrine\Bundle\FixturesBundle\Fixture`, absent d'un build `--no-dev`,
donc la classe ne se charge pas et l'erreur est fatale. Symptôme en prod :
`cache:clear --env=prod` et `doctrine:schema:create` meurent sur
`Class "Doctrine\Bundle\FixturesBundle\Fixture" not found`, en pointant un
fichier de fixtures que personne ne pensait exécuter.

Aucun scanner ne visite `src/DataFixtures/` : ni le routing, ni le mapping
Doctrine. C'est pour la même raison qu'aurora-core a sorti les siennes vers
`fixtures/` en 0.9.3.

Constaté sur aurora-client le 30/08/2026 : une fixture posée dans
`src/Module/Bnb/DataFixtures/` a bloqué le premier déploiement de prod.

Voir aussi [[convention_module_structure]] pour le placement général.
