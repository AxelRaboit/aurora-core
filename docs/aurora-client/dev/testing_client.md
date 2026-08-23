# Tester un projet aurora-client

Ce document couvre **les spécificités du test côté client** : exécution,
kernel utilisé, comment tester une extension d'entité ou un override de
service. Pour le détail de l'écriture des tests (Unit vs Integration,
fixtures, helpers, conventions), lire **avant** :

- [`../../aurora-shared/testing_php.md`](../../aurora-shared/testing_php.md) - tests PHP (Unit/Integration, PHPUnit)
- [`../../aurora-shared/testing_vue.md`](../../aurora-shared/testing_vue.md) - tests Vue (Vitest)

---

## 1. Exécuter les tests

```bash
make test               # frontend + backend
make test-backend       # PHPUnit (recrée la DB de test, joue le schéma)
make test-frontend      # Vitest (i18n régénéré au préalable)
make ft                 # fix (linters) + test - à passer avant chaque commit
make coverage           # rapport de couverture HTML dans var/coverage/ (nécessite php8.4-pcov)
```

`make test-backend` enchaîne :

1. `make db-test` - drop + recreate la DB de test, `doctrine:schema:create`,
   `doctrine:migrations:sync-metadata-storage`, `migrations:version --add --all`.
   On utilise **`schema:create`** plutôt que rejouer les migrations pour
   éviter les problèmes d'ordre entre les deux namespaces (`DoctrineMigrations`
   client + `AuroraMigrations` vendor).
2. `php vendor/axelraboit/aurora/bin/phpunit --testdox`.

Le binaire `phpunit` vit dans le **vendor Aurora**. La config PHPUnit du projet
client (`phpunit.xml`) hérite de celle d'Aurora.

---

## 2. Où vivent les tests client

```
tests/
├── Unit/                  # sans DB (mocks), miroir de src/
│   └── Module/<Mirror>/…
├── Integration/           # avec DB, kernel Symfony
│   └── Module/<Mirror>/…
└── bootstrap.php          # bootstrap projet : Dotenv + autoload
```

Mirror rule (idem `src/`) :

| Code testé | Test |
|---|---|
| `src/Module/Platform/Agency/Manager/AgencyManager.php` | `tests/Unit/Module/Core/Agency/Manager/AgencyManagerTest.php` |
| `src/Module/Tracking/Project/Manager/ProjectManager.php` | `tests/Integration/Module/Tracking/Project/Manager/ProjectManagerTest.php` |

Règle simple :
- Le test touche Doctrine ou le kernel → **Integration**
- Logique pure (mocks autorisés) → **Unit**

---

## 3. Kernel et environnement utilisés

- Le kernel monté en test est `App\Kernel` (qui délègue à `Aurora\Kernel`).
- `.env.test` (versionné) + `.env.test.local` (jamais committé) configurent
  l'environnement. `DATABASE_URL` ajoute automatiquement le suffixe `_test`
  via `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` (cf.
  `config/packages/doctrine.yaml`).
- Les **fixtures Aurora** sont disponibles via le `vendor/`. Vos fixtures
  client (sous `src/DataFixtures/`) sont chargées par-dessus si vous en
  créez.

Pour les classes de base et helpers (`IntegrationTestCase`, fixtures
loaders), voir
[`../../aurora-shared/testing_php.md`](../../aurora-shared/testing_php.md).
Aurora les expose en tant que classes du vendor - vous pouvez les hériter
directement.

---

## 4. Tester un override d'entité

Cas typique : vous avez étendu `Agency` avec un champ `code`. Vous voulez
valider que le flow controller → factory → manager → entité persiste bien
ce champ.

### Test Unit du Manager (sans DB)

```php
namespace App\Tests\Unit\Module\Core\Agency\Manager;

use App\Module\Platform\Agency\Dto\AgencyInput;
use App\Module\Platform\Agency\Entity\Agency;
use App\Module\Platform\Agency\Manager\AgencyManager;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AgencyManagerTest extends TestCase
{
    public function testCreateAppliesCodeAndAuditsIt(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $auditLogger->expects(self::once())
            ->method('log')
            ->with('core', 'agency.created', self::anything(), self::anything(),
                self::callback(fn (array $payload) => 'X-42' === $payload['code']));

        $manager = new AgencyManager($entityManager, $auditLogger);
        $agency = $manager->create(new AgencyInput('Foo', 'X-42'));

        self::assertInstanceOf(Agency::class, $agency);
        self::assertSame('X-42', $agency->getCode());
    }
}
```

Points utiles :
- On instancie `App\…\AgencyManager` directement - pas besoin du conteneur,
  les hooks `protected` sont scellés par le test du `extends`.
- On vérifie que `parent::auditPayload()` a bien splat-mergé (clé `name`
  conservée) en plus de `code`.

### Test Integration (avec DB)

```php
namespace App\Tests\Integration\Module\Core\Agency;

use Aurora\Module\Platform\Agency\Manager\AgencyManagerInterface;
// … hérite de la classe IntegrationTestCase d'Aurora
final class AgencyExtensionTest extends \Aurora\Tests\Integration\IntegrationTestCase
{
    public function testManagerInterfaceResolvesToClientManager(): void
    {
        $manager = self::getContainer()->get(AgencyManagerInterface::class);
        self::assertInstanceOf(\App\Module\Platform\Agency\Manager\AgencyManager::class, $manager);
    }
}
```

Ce test vérifie que `#[AsAlias]` est bien câblé : `AgencyManagerInterface`
résout vers votre classe, pas vers celle d'Aurora.

---

## 5. Mocker / ne pas mocker les services Aurora

**Règle** : 99% du temps, **ne pas mocker** un service Aurora - utiliser
le conteneur réel via `IntegrationTestCase`. La DI Aurora est suffisamment
légère pour qu'un test Integration soit rapide, et c'est le seul moyen de
vérifier que vos `#[AsAlias]` ne sont pas cassés.

Mocker uniquement :
- les dépendances externes (HTTP, fichier, mailer SMTP),
- `EntityManagerInterface` + `AuditLogger` pour un test **Unit** isolé d'un
  Manager (cf. §4),
- les services dont le coût de boot est démesuré pour le scope du test.

---

## 6. Pièges connus

### a) Cache stale après `#[AsAlias]` modifié

Symptôme : l'interface continue de résoudre vers la classe Aurora alors que
vous venez d'ajouter `#[AsAlias]`.

```bash
make sf CMD="cache:clear --env=test"
# ou
php vendor/axelraboit/aurora/bin/phpunit ...   # déjà appelé via make test
```

`make test-backend` recrée la DB de test mais **ne vide pas systématiquement
le cache du conteneur** - un `make cc` ou un changement d'env sur un fichier
PHP suffisent à l'invalider.

### b) Validation du schéma en test

```bash
make schema-validate
```

⚠️ La validation est très sensible aux conflits entre `app_*` et `core_*`.
Si vous voyez une erreur du type *"Sequence already exists"*, c'est qu'une
de vos entités cliente partage un nom de séquence avec une entité Aurora -
préfixez **`seq_app_*`** systématiquement (cf.
[`../extending/extend_module.md`](../extending/extend_module.md) §1).

### c) Ordre des fixtures

Les fixtures sont chargées dans cet ordre :
1. Fixtures Aurora (déclarées avec leur dépendance interne)
2. Fixtures client sous `src/DataFixtures/` (groupes : `default` ou `demo`)

Si une fixture client référence une entité Aurora, déclarer la dépendance
via `DependentFixtureInterface`. Sinon erreur de FK au load.

### d) i18n manquant en test Vitest

`make test-frontend` appelle `make translation` en pré-step pour régénérer
`src/Core/assets/locales/generated/{fr,en}.json`. Si vous lancez `pnpm test`
directement, **régénérez d'abord** (`make translation`) sinon Vitest peut planter
sur des clés Vue-i18n manquantes.

### e) Tests qui passent localement, échouent en CI

Vérifier :
- `.env.test.local` n'est pas committé (et donc absent en CI)
- `make db-test` est bien exécuté en CI avant `phpunit`
- `make sf CMD="aurora:application-parameter"` en CI si vos tests dépendent
  de `Setting`/`ApplicationParameter` (rarement nécessaire - `IntegrationTestCase`
  le fait normalement)

---

## 7. Quand écrire un test côté client

À écrire systématiquement :

- Tout **override de Manager** : un test Integration qui vérifie que
  `<Name>ManagerInterface` résout vers votre classe.
- Tout **override de DTO** : un test Unit qui vérifie que `fromArray()`
  produit votre DTO client avec le champ custom rempli.
- Toute **logique métier nouvelle** dans un module client autonome.

À ne pas dupliquer :

- Les tests Aurora-core (déjà 492+ verts dans le vendor). Si vous étendez
  une entité, vous testez **uniquement le delta** - pas le flow Aurora
  complet.
