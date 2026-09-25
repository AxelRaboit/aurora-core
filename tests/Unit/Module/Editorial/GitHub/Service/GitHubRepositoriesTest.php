<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\GitHub\Service;

use Aurora\Module\Editorial\GitHub\Service\GitHubRepositories;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A repository's card and its releases, from GitHub's public API.
 */
final class GitHubRepositoriesTest extends TestCase
{
    public function testARepositoryIsReadOnceAndCached(): void
    {
        $calls = 0;
        $service = new GitHubRepositories(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new JsonMockResponse([
                'name' => 'aurora-core',
                'full_name' => 'AxelRaboit/aurora-core',
                'html_url' => 'https://github.com/AxelRaboit/aurora-core',
                'description' => 'The CMS',
                'language' => 'PHP',
                'stargazers_count' => 12,
                'forks_count' => 3,
                'pushed_at' => '2026-09-25T10:00:00Z',
            ]);
        }), new ArrayAdapter(), new NullLogger());

        self::assertSame('AxelRaboit/aurora-core', $service->repository('AxelRaboit/aurora-core')['fullName'] ?? null);
        self::assertSame(12, $service->repository('AxelRaboit/aurora-core')['stars'] ?? null);
        self::assertSame(1, $calls);
    }

    public function testDraftsAreNotReleases(): void
    {
        $service = new GitHubRepositories(new MockHttpClient(new JsonMockResponse([
            ['tag_name' => 'v0.9.250', 'name' => 'v0.9.250', 'published_at' => '2026-09-25T12:00:00Z', 'html_url' => 'https://x', 'body' => "## Corrigé\n\n- Les langues"],
            ['tag_name' => 'v0.9.251', 'draft' => true],
        ])), new ArrayAdapter(), new NullLogger());

        $releases = $service->releases('AxelRaboit/aurora-core');

        self::assertCount(1, $releases ?? []);
        self::assertSame('Corrigé', $releases[0]['summary'] ?? null);
    }

    public function testARefusalIsNothingToShow(): void
    {
        $service = new GitHubRepositories(new MockHttpClient(new MockResponse('', ['http_code' => 403])), new ArrayAdapter(), new NullLogger());

        self::assertNull($service->repository('AxelRaboit/aurora-core'));
    }

    public function testTheSummaryIsTheFirstLineWithoutItsMarkdown(): void
    {
        self::assertSame('Un bloc très haut', GitHubRepositories::summary("### **Un bloc** très haut\n\nSuite"));
        self::assertSame('Voir la page', GitHubRepositories::summary("---\n[Voir la page](https://x)"));
        self::assertSame('', GitHubRepositories::summary(''));
    }
}
