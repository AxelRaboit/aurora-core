<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Configuration\Theme\Entity\Theme;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ThemesControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
    }

    private function defaultThemeId(): int
    {
        $theme = static::getContainer()->get(ThemeRepository::class)->findBySlug('default');
        self::assertNotNull($theme);

        return $theme->getId();
    }

    private function jsonRequest(string $method, string $route, array $routeParameters = [], array $payload = []): array
    {
        $this->client->request(
            $method,
            $this->urlGenerator->generate($route, $routeParameters),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            [] !== $payload ? json_encode($payload) : '',
        );
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    public function testIndexReturnsOk(): void
    {
        $this->client->request(HttpMethodEnum::Get->value, $this->urlGenerator->generate('suite_configuration_themes'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateTheme(): void
    {
        [$status, $body] = $this->jsonRequest(HttpMethodEnum::Post->value, 'suite_configuration_themes_create', [], [
            'name' => 'Test Theme',
            'slug' => 'test-theme-create',
            'description' => '',
        ]);

        self::assertSame(200, $status, json_encode($body));
        self::assertTrue($body['success']);
        self::assertSame('test-theme-create', $body['theme']['slug']);

        // Clean up
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $theme = static::getContainer()->get(ThemeRepository::class)->findBySlug('test-theme-create');
        if (null !== $theme) {
            $entityManager->remove($theme);
            $entityManager->flush();
        }
    }

    public function testCreateDuplicateSlugFails(): void
    {
        $slug = 'test-duplicate-slug-'.uniqid();

        $this->jsonRequest(HttpMethodEnum::Post->value, 'suite_configuration_themes_create', [], [
            'name' => 'First Theme',
            'slug' => $slug,
            'description' => '',
        ]);

        [$status, $body] = $this->jsonRequest(HttpMethodEnum::Post->value, 'suite_configuration_themes_create', [], [
            'name' => 'Second Theme',
            'slug' => $slug,
            'description' => '',
        ]);

        self::assertFalse($body['success']);
        self::assertNotEmpty($body['errors']['slug']);

        // Clean up
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $theme = static::getContainer()->get(ThemeRepository::class)->findBySlug($slug);
        if (null !== $theme) {
            $entityManager->remove($theme);
            $entityManager->flush();
        }
    }

    public function testActivateTheme(): void
    {
        $defaultThemeId = $this->defaultThemeId();

        [$status, $body] = $this->jsonRequest(HttpMethodEnum::Post->value, 'suite_configuration_themes_activate', ['id' => $defaultThemeId]);

        self::assertSame(200, $status);
        self::assertTrue($body['success']);
    }

    public function testUpdateTheme(): void
    {
        $slug = 'test-update-'.uniqid();

        [, $createBody] = $this->jsonRequest(HttpMethodEnum::Post->value, 'suite_configuration_themes_create', [], [
            'name' => 'Update Me',
            'slug' => $slug,
            'description' => '',
        ]);

        self::assertTrue($createBody['success'], 'Create step failed: '.json_encode($createBody));
        $themeId = $createBody['theme']['id'];

        [$status, $body] = $this->jsonRequest(HttpMethodEnum::Post->value, 'suite_configuration_themes_update', ['id' => $themeId], [
            'name' => 'Updated Name',
            'description' => 'new desc',
            'config' => [],
        ]);

        self::assertSame(200, $status);
        self::assertTrue($body['success']);
        self::assertSame('Updated Name', $body['theme']['name']);

        // Clean up
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $theme = $entityManager->find(Theme::class, $themeId);
        if (null !== $theme) {
            $entityManager->remove($theme);
            $entityManager->flush();
        }
    }

    public function testDeleteNonDefaultTheme(): void
    {
        $slug = 'test-delete-'.uniqid();

        [, $createBody] = $this->jsonRequest(HttpMethodEnum::Post->value, 'suite_configuration_themes_create', [], [
            'name' => 'Delete Me',
            'slug' => $slug,
            'description' => '',
        ]);

        self::assertTrue($createBody['success'], 'Create step failed: '.json_encode($createBody));
        $themeId = $createBody['theme']['id'];

        $this->client->request(HttpMethodEnum::Post->value, $this->urlGenerator->generate('suite_configuration_themes_delete', ['id' => $themeId]), [], [], ['CONTENT_TYPE' => 'application/json']);
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['success']);
    }

    public function testCannotDeleteDefaultTheme(): void
    {
        $defaultThemeId = $this->defaultThemeId();

        $this->client->request(HttpMethodEnum::Post->value, $this->urlGenerator->generate('suite_configuration_themes_delete', ['id' => $defaultThemeId]), [], [], ['CONTENT_TYPE' => 'application/json']);
        $response = $this->client->getResponse();

        self::assertSame(400, $response->getStatusCode());
    }
}
