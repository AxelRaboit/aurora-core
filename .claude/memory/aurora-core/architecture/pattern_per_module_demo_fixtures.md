---
name: Pattern fixtures démo par module
description: Depuis le split, chaque module ship son <Module>DemoFixtures (dev-only, auto-découvert) ; données partagées via références ; plus de DemoFixtures monolithique
type: project
---

## Règle

Chaque module porte ses propres données de démo dans
`fixtures/<X>/<X>DemoFixtures.php`, namespace `Aurora\Fixtures\<X>` (ex.
`fixtures/Editorial/EditorialDemoFixtures.php`). Plus de `DemoFixtures`
monolithique central - il couplait le core à toutes les entités modules et
cassait les clients à-la-carte qui n'installent qu'un sous-ensemble. Chaque
classe :

- `extends Fixture implements DependentFixtureInterface, FixtureGroupInterface`
- `getGroups(): ['demo']` (cf. [[convention_fixture_group_demo]])
- `getDependencies()` pointe vers les fixtures dont elle consomme les données

## Chargement dev-only (jamais en prod)

`doctrine/doctrine-fixtures-bundle` est **require-dev** → la classe `Fixture`
est absente en prod. Deux verrous, et il faut les deux :

- **L'emplacement, `fixtures/` et non `src/`.** C'est le verrou qui compte.
  Plusieurs mécanismes parcourent `src/` répertoire par répertoire et
  **autoloadent chaque classe rencontrée** avant de décider quoi en faire : le
  driver attribut de Doctrine (`getAllClassNames()`), le loader de routes
  attributaires (`AttributeDirectoryLoader`, via le `resource:
  '../vendor/axelraboit/aurora/src/' type: attribute` du `routes.yaml` client),
  et le glob de services. Charger une classe qui étend un `Fixture` absent est
  une erreur fatale, pas un skip. Une liste d'exclusions doit être maintenue à
  chaque nouveau scanner ; un répertoire hors `src/` n'est visité par aucun.
- **L'enregistrement en services**, dev/test uniquement : `config/services.yaml`
  déclare `Aurora\Fixtures\: '../fixtures/'` sous `when@dev:` et `when@test:`.
  `AuroraBundle` importe ce `services.yaml` → le gating protège aussi le prod
  des clients.

Pour les **modules extraits** (bundle standalone),
`AbstractAuroraModuleBundle::loadExtension` enregistre `DataFixtures/` comme
services seulement si `kernel.environment ∈ {dev,test}` ET
`class_exists(Fixture::class)`, et le `config/services.php` du module exclut
`DataFixtures` de son glob.

> ⚠️ Le premier verrou manque encore côté modules extraits : leur
> `DataFixtures/` vit dans le répertoire du paquet, et `AuroraModuleRouteLoader`
> importe ce répertoire entier en `type: attribute`. Le premier module réellement
> extrait qui ship des fixtures reproduira le bug. À traiter en les sortant vers
> un `fixtures/` du paquet, comme ici.

## Données partagées via références (découplage cross-module)

Les producteurs exposent des refs statiques, les consommateurs les tirent par
référence (jamais d'import de données concrètes d'un autre module) :

| Producteur (core/module) | Refs exposées |
|---|---|
| `CoreDemoFixtures` (core) | `userRef(i)` + `USER_COUNT` ; crée users + agencies/services |
| `GedDemoFixtures` (Ged, core) | `mediaRef(i)` ; documents média partagés |
| `CrmDemoFixtures` | `companyRef(i)`, `contactRef(i)` |
| `ErpDemoFixtures` | `productRef(i)` |

Consommation : `getDependencies()` + `$this->getReference(...)`. Pour une dép
**cross-package optionnelle** (ex. Photo/Project consomment les contacts Crm,
mais Crm peut ne pas être installé), garder le `make demo` robuste :

```php
public function getDependencies(): array
{
    $deps = [CoreDemoFixtures::class];
    if (class_exists(CrmDemoFixtures::class)) { $deps[] = CrmDemoFixtures::class; }
    return $deps;
}
// dans load() :
if (class_exists(CrmDemoFixtures::class)) {
    for ($i = 0; $this->hasReference(CrmDemoFixtures::contactRef($i), Contact::class); ++$i) { ... }
}
```

`Ecommerce → Erp` (même package commerce) et `* → Ged media` (Ged toujours
présent en core) n'ont pas besoin de garde.

## i18n du menu (piège associé)

Le sidemenu est en Vue (vue-i18n). `app:translations:dump-js` doit découvrir
les traductions des **packages modules siblings** (`vendor/axelraboit/aurora-*`),
pas seulement `$auroraDir`. Gating : `basename(dirname($auroraDir)) === 'axelraboit'`
(actif uniquement en install vendored, laisse le standalone intact). Sans ça,
les libellés nav des modules s'affichent en clés brutes (`backend.nav.posts`).

## Scaffolding

`/add-module` ne scaffolde **pas encore** de `<Module>DemoFixtures` - à créer à
la main pour l'instant (gap connu). Modèles de référence : `HrDemoFixtures`
(users only), `EcommerceDemoFixtures` (multi-refs), `GedDemoFixtures` (producteur
média + favicon).

Voir aussi [[project_monorepo_split_chantier]], [[convention_fixture_group_demo]].
