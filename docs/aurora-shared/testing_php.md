# Tests PHP - Guide

## Convention de placement

**Tests PHP → dossier centralisé `tests/` qui miroir `src/`.**

```
src/Module/Billing/Service/InvoiceExtractor.php
tests/Unit/Module/Billing/Service/InvoiceExtractorTest.php
```

C'est intentionnellement différent des tests Vue/JS (co-localisés à côté de leur source - voir [testing_vue.md](testing_vue.md)). Deux écosystèmes, deux conventions établies. Ne pas mélanger : pas de `.test.php` dans `src/`.

---

## Structure

```
tests/
  Unit/                   ← tests sans base de données (mocks)
    Dto/                  ← validation des DTOs
    Entity/               ← logique pure des entités
    Enum/                 ← énumérations
    Manager/              ← logique métier isolée
    Module/<Module>/      ← tests unitaires par module
    Serializer/           ← sérialisation
    Service/              ← services purs
    Setting/              ← paramètres applicatifs
    Trait/                ← traits réutilisables
    Translation/          ← cohérence des traductions i18n
  Integration/            ← tests avec base de données réelle
    Concern/              ← traits partagés entre tests d'intégration
    Controller/           ← endpoints HTTP (backend + frontend)
    Manager/              ← managers qui interagissent avec la BDD
    MessageHandler/       ← handlers de messages Symfony
    Module/<Module>/      ← tests d'intégration par module
    Service/              ← services avec dépendances réelles
    IntegrationTestCase.php ← classe de base (charge les fixtures)
  bootstrap.php
```

---

## Unit vs Integration - quand choisir ?

| Situation | Type |
|---|---|
| Logique pure sans Doctrine (calculs, transformations, validations) | **Unit** |
| Tester un Manager ou Service qui ne flush pas | **Unit** (mock EM) |
| Tester un Manager qui persiste en BDD | **Integration** |
| Tester un endpoint HTTP | **Integration** |
| Tester un MessageHandler | **Integration** |
| Tester une query/méthode de Repository | **Integration** |

Règle simple : **si le test touche Doctrine ou le kernel Symfony → Integration**.

---

## Écrire un test Unit

```php
<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class MyServiceTest extends TestCase
{
    private MyService $service;
    private SomeDependency $dependency;

    protected function setUp(): void
    {
        $this->dependency = $this->createMock(SomeDependency::class);
        $this->service = new MyService($this->dependency);
    }

    public function testDoSomething(): void
    {
        $this->dependency->method('getData')->willReturn(['foo' => 'bar']);

        $result = $this->service->doSomething();

        self::assertSame('bar', $result['foo']);
    }
}
```

**Bonnes pratiques :**
- Classe `final` - les tests unitaires ne sont pas étendus
- `#[AllowMockObjectsWithoutExpectations]` si les mocks n'ont pas de `expects()` explicites
- `createMock()` pour les dépendances (interfaces de préférence)
- Un `setUp()` clair qui instancie le SUT (System Under Test)

---

## Écrire un test d'intégration

### Base : `IntegrationTestCase`

Tous les tests d'intégration étendent `IntegrationTestCase`, qui charge les fixtures **une seule fois par classe** (via `setUpBeforeClass`) pour la performance.

```php
<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MyControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
    }
```

### Authentifier un utilisateur

```php
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Platform\User\Entity\User;

$userRepository = static::getContainer()->get(UserRepository::class);
$admin = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
self::assertInstanceOf(User::class, $admin);
$this->client->loginUser($admin, 'admin');
```

Créer un utilisateur ad hoc (trait `CreatesTestUsers`) :
```php
use Aurora\Tests\Integration\Concern\CreatesTestUsers;

final class MyTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    public function testSomething(): void
    {
        $user = $this->createTestUser('Alice', role: UserRoleEnum::User);
        $this->client->loginUser($user, 'admin');
    }
}
```

### Appeler un endpoint JSON

```php
$this->client->jsonRequest('POST', $this->urlGenerator->generate('my_route'), [
    'field' => 'value',
]);

$response = $this->client->getResponse();
self::assertSame(200, $response->getStatusCode());

$data = json_decode((string) $response->getContent(), true);
self::assertTrue($data['success']);
self::assertSame('expected', $data['item']['field']);
```

### Récupérer un service du container

```php
$repository = static::getContainer()->get(MyRepository::class);
$entityManager = static::getContainer()->get(EntityManagerInterface::class);
```

---

## Concerns (traits partagés)

Traits disponibles dans `tests/Integration/Concern/` :

| Trait | Rôle |
|---|---|
| `CreatesTestUsers` | Crée des utilisateurs avec rôle/type/statut configurables |
| `BuildsPostPayload` | Construit des payloads JSON de création/édition de post |

Ajouter un concern quand la même boilerplate apparaît dans 2+ classes de test.

---

## Conventions de nommage

- **Classe** : `{SujetTest}` - ex: `PostsControllerTest`, `GalleryManagerTest`
- **Méthode** : `test{ActionExpectedBehavior}` - ex: `testCreatePostReturnsNewPost`
- **Namespace** : miroir de `src/` - `Aurora\Tests\Unit\Module\Billing\...`
- **Fichier** : `tests/{Unit|Integration}/{miroir de src/}` - ex: `tests/Unit/Module/Billing/Service/InvoiceExtractorTest.php`
- Classe `final` pour les Unit, `final` pour les Integration également

---

## Lancer les tests

```bash
# Tous les tests
php bin/phpunit

# Avec make (fix + lint + tests)
make ft

# Un fichier ou dossier
php bin/phpunit tests/Unit/Service/GalleryManagerTest.php
php bin/phpunit tests/Integration/Controller/

# Un groupe via filtre
php bin/phpunit --filter testCreate
```

---

## Couverture de code (coverage)

### Prérequis - driver PCOV

PHPUnit a besoin d'un driver pour instrumenter le code. PCOV est recommandé
(plus rapide que Xdebug, dédié au coverage uniquement) :

```bash
sudo apt install php8.4-pcov
```

Vérifier que le driver est actif :

```bash
php -m | grep pcov
```

### Générer le rapport HTML

```bash
make coverage
# équivalent : php -d pcov.enabled=1 bin/phpunit --coverage
```

Le rapport est généré dans `var/coverage/` (configurable dans `phpunit.dist.xml`).
Ouvrir `var/coverage/index.html` dans un navigateur pour visualiser :
- la couverture par fichier / classe / méthode,
- les lignes non couvertes (en rouge).

Un résumé texte est aussi affiché dans le terminal à la fin de l'exécution.

### Coverage sur un seul dossier

```bash
php -d pcov.enabled=1 bin/phpunit tests/Unit/Module/Billing --coverage
```

> `var/coverage/` est dans `.gitignore` - ne pas committer les rapports générés.

---

## Fixtures

Les fixtures sont définies dans `fixtures/Core/AppFixtures.php`. Elles créent les données minimales nécessaires aux tests d'intégration (users, post types, etc.).

`IntegrationTestCase::setUpBeforeClass()` les charge **une seule fois par classe** avec `ORMPurger` → isolation des données entre classes mais pas entre méthodes. Si un test modifie des données, prévoir un `tearDown()` pour nettoyer.

---

## Ce qu'on teste (3-5 assertions par test)

- **Unit** : cas nominaux + cas limites (null, valeur invalide, état intermédiaire)
- **Integration Controller** : code HTTP + structure JSON de la réponse + état BDD si nécessaire
- **Integration Manager** : entité créée/modifiée en BDD + audit log si pertinent

## Ce qu'on ne teste pas

- Getters/setters sans logique (pas de valeur)
- Configuration Symfony (vérifiée au boot)
- Détails d'implémentation internes (accéder aux propriétés privées via Reflection est un signal d'alarme)
