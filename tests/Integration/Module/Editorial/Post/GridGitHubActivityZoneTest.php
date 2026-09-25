<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\GitHub\Service\GitHubContributions;
use Aurora\Module\Editorial\GitHub\Setting\GitHubSettings;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;

/**
 * The GitHub activity zone, from the settings to the markup.
 *
 * The edges worth holding: a zone placed while the integration is off draws
 * nothing, and a grid keeps each row a day of the week even when the first
 * week starts on a Wednesday.
 */
final class GridGitHubActivityZoneTest extends IntegrationTestCase
{
    private const string PAGE = <<<'HTML'
        <td data-date="2026-09-16" id="contribution-day-component-3-0" data-level="1"></td>
        <td data-date="2026-09-20" id="contribution-day-component-0-1" data-level="4"></td>
        <tool-tip for="contribution-day-component-3-0">2 contributions on September 16th.</tool-tip>
        <tool-tip for="contribution-day-component-0-1">1,204 contributions on September 20th.</tool-tip>
        HTML;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        static::getContainer()->set(GitHubContributions::class, new GitHubContributions(
            new MockHttpClient(function (string $method, string $url): MockResponse {
                $this->requested[] = $url;

                return new MockResponse(self::PAGE);
            }),
            new ArrayAdapter(),
            new NullLogger(),
        ));
    }

    public function testAZonePlacedWhileTheIntegrationIsOffDrawsNothing(): void
    {
        $this->configure(enabled: false, logins: ['AxelRaboit']);

        self::assertSame('', mb_trim($this->render()));
        self::assertSame([], $this->requested, 'nothing is asked of GitHub before somebody turns it on');
    }

    public function testOneCardPerAccountWithItsTotal(): void
    {
        $this->configure(enabled: true, logins: ['AxelRaboit', 'axelr7x']);

        $html = $this->render();

        self::assertStringContainsString('@AxelRaboit', $html);
        self::assertStringContainsString('@axelr7x', $html);
        self::assertStringContainsString('href="https://github.com/AxelRaboit"', $html);
        self::assertMatchesRegularExpression('/1\D?206 contributions/u', $html);
        self::assertSame(
            ['https://github.com/users/AxelRaboit/contributions', 'https://github.com/users/axelr7x/contributions'],
            $this->requested,
        );
    }

    /**
     * The first week starts on a Wednesday: the three days before it stay
     * empty cells, so Wednesday is still the fourth row.
     */
    public function testAnIncompleteWeekKeepsItsDaysInPlace(): void
    {
        $this->configure(enabled: true, logins: ['AxelRaboit']);

        $grid = $this->grid();
        $column = $grid['zones'][0]['githubActivity']['accounts'][0]['columns'][0];

        self::assertNull($column[0]);
        self::assertNull($column[2]);
        self::assertSame(1, $column[3]['level'] ?? null);
        self::assertStringContainsString('2 contributions le 16 septembre 2026', (string) ($column[3]['label'] ?? ''));
    }

    /** @param list<string> $logins */
    private function configure(bool $enabled, array $logins): void
    {
        static::getContainer()->get(GitHubSettings::class)->save(enabled: $enabled, logins: $logins);
    }

    /** @return array<string, mixed> */
    private function grid(): array
    {
        $grid = static::getContainer()->get(GridViewBuilder::class)->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'githubActivity']]],
            ['zones' => []],
            'fr',
        );

        self::assertNotNull($grid);

        return $grid;
    }

    private function render(): string
    {
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $this->grid()['zones'][0], 'locale' => 'fr'],
        );
    }
}
