<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Configuration\Theme;

use Aurora\Module\Configuration\Theme\Entity\Theme;
use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The theme's neutral hover and menu modes, as a visitor receives them.
 *
 * Unlike "custom", which travels in the `<style>` of the head, "neutral" is
 * an attribute on `<html>` that the CSS keys on. Nothing else checked that the
 * layout still writes it: removing it would have left every other test green
 * and every site on the accent colour.
 */
final class ThemeNeutralModesReachThePageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private ?ThemeInterface $theme = null;

    /** @var array<string, mixed> */
    private array $savedConfig = [];

    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->theme instanceof ThemeInterface) {
            $theme = $this->entityManager->find(Theme::class, $this->theme->getId());
            if ($this->created) {
                $this->entityManager->remove($theme);
            } else {
                $theme?->setConfig($this->savedConfig);
            }
            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    public function testNeutralModesAreWrittenOnTheRootElement(): void
    {
        $this->activeThemeWith(['highlight' => 'neutral', 'menu_active' => 'neutral']);

        $this->client->request('GET', '/fr');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-highlight="neutral"', $html);
        self::assertStringContainsString('data-menu-active="neutral"', $html);
    }

    public function testTheAccentModeWritesNothing(): void
    {
        $this->activeThemeWith(['highlight' => 'accent', 'menu_active' => 'accent']);

        $this->client->request('GET', '/fr');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('data-highlight=', $html);
        self::assertStringNotContainsString('data-menu-active=', $html);
    }

    /** @param array<string, mixed> $config */
    private function activeThemeWith(array $config): void
    {
        $theme = static::getContainer()->get(ThemeRepository::class)->findActive();

        if (!$theme instanceof ThemeInterface) {
            $theme = new Theme();
            $theme->setName('Neutre')->setSlug('neutre-test')->setActive(true);
            $this->entityManager->persist($theme);
            $this->created = true;
        } else {
            $this->savedConfig = $theme->getConfig();
        }

        $theme->setConfig([...$this->savedConfig, ...$config]);
        $this->entityManager->flush();
        $this->theme = $theme;
    }
}
