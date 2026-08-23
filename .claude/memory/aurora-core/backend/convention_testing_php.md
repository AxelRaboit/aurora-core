---
name: convention_testing_php
description: Convention de test PHP/PHPUnit - Unit vs Integration, structure, base classes, helpers, nommage.
metadata:
  type: feedback
---

## Règle

**Tests PHP → dossier centralisé `tests/` qui miroir `src/`** (convention Symfony/PHPUnit).

```
src/Module/Billing/Service/InvoiceExtractor.php
tests/Unit/Module/Billing/Service/InvoiceExtractorTest.php   ← miroir
```

C'est intentionnellement différent des tests Vue/JS (co-localisés) - deux écosystèmes, deux conventions établies. Ne pas mélanger : pas de `.test.php` co-localisé dans `src/`.

```
tests/
  Unit/         ← sans BDD, mocks uniquement
  Integration/  ← BDD réelle + kernel Symfony
    Concern/    ← traits partagés (CreatesTestUsers, BuildsPostPayload)
    IntegrationTestCase.php ← charge AppFixtures une fois par classe
```

**Critère** : touche Doctrine ou le kernel → Integration. Sinon → Unit.

## Test Unit

```php
#[AllowMockObjectsWithoutExpectations]
final class MyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $this->dep = $this->createMock(DepInterface::class);
        $this->sut = new MyService($this->dep);
    }

    public function testDoSomething(): void
    {
        $this->dep->method('get')->willReturn('foo');
        self::assertSame('foo', $this->sut->doSomething());
    }
}
```

- Classe `final`, `setUp()` instancie le SUT
- `#[AllowMockObjectsWithoutExpectations]` si pas de `expects()` explicites
- Mocker par interface, jamais par concrete

## Test Integration Controller

```php
final class MyControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');
    }

    public function testCreate(): void
    {
        $this->client->jsonRequest('POST', '/backend/my-route', ['field' => 'value']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }
}
```

## Nommage

- Classe : `{Sujet}Test` (final)
- Méthode : `test{ActionBehavior}`
- Namespace : miroir de `src/` → `Aurora\Tests\Unit\Module\Billing\Service\InvoiceExtractorTest`
- Fichier : `tests/{Unit|Integration}/{miroir de src/}`

## Fixtures

`IntegrationTestCase::setUpBeforeClass()` charge `AppFixtures` **une fois par classe**. Si un test modifie des données, nettoyer dans `tearDown()`.

Créer un user ad hoc : `use CreatesTestUsers` + `$this->createTestUser('Alice', role: UserRoleEnum::User)`.

## Pourquoi

**Why:** Documenté lors de l'audit testing (2026-05-14). Cohérence avec la convention Vue/JS (co-location, guide dédié).

**How to apply:** Nouveau service/manager → test Unit en miroir dans `tests/Unit/`. Nouveau Controller → test Integration dans `tests/Integration/Controller/`. 3-5 assertions ciblées par test.

**Doc canonique** : [`docs/aurora-shared/testing_php.md`](../../../docs/aurora-shared/testing_php.md)
