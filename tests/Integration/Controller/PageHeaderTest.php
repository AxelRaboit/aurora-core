<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The lower band must carry a trail, not a label.
 *
 * The first version of the split dropped the last crumb, on the grounds that the
 * band above already names the page. Fourteen of the nineteen pages have exactly
 * two crumbs, so the trail collapsed to one word — the section name, which the
 * side menu was already showing. Nothing failed: the page rendered, the tests
 * passed, and `/backend/platform/users` said only "Plateforme".
 *
 * That is the shape of regression this guards. Slicing the trail, hiding a level
 * behind a breakpoint, or reordering the loop all read as correct in a diff and
 * all end in the same place, so the assertions are made against real rendered
 * HTML and count the levels that survive to the page.
 *
 * They compare the bands to each other rather than to expected strings, so the
 * test needs to know nothing about the app's locale or its translations.
 */
final class PageHeaderTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
    }

    /**
     * The common shape: a section and a page. Both levels have to be there —
     * this is the exact case that shipped broken, showing the section alone.
     */
    public function testTheTrailKeepsTheSectionAndThePage(): void
    {
        $crawler = $this->client->request('GET', $this->urlGenerator->generate('backend_platform_users'));

        self::assertResponseIsSuccessful();

        $name = mb_trim($crawler->filter('header h2')->text());
        self::assertNotSame('', $name, 'The upper band must name the page.');

        $trail = $crawler->filter('header nav[aria-label]');
        self::assertSame(
            1,
            $trail->filter('span.select-none')->count(),
            'Two levels means one separator. None means the trail lost a level and is a label.',
        );
        self::assertStringContainsString(
            $name,
            mb_trim($trail->text()),
            'A trail that stops before the page it is on does not say where you are.',
        );
        self::assertSame(1, $trail->filter('[aria-current="page"]')->count());
    }

    /**
     * Three levels, and the middle one is a link back. This is the path the
     * single band used to truncate, which is what the split was for.
     */
    public function testADeeperPageShowsEveryLevel(): void
    {
        $crawler = $this->client->request('GET', $this->urlGenerator->generate('backend_ged_categories'));

        self::assertResponseIsSuccessful();

        $trail = $crawler->filter('header nav[aria-label]');

        // Three levels, so two separators.
        self::assertSame(2, $trail->filter('span.select-none')->count());
        self::assertGreaterThan(0, $trail->filter('a')->count(), 'A level that has a route stays clickable.');
        self::assertStringContainsString(mb_trim($crawler->filter('header h2')->text()), mb_trim($trail->text()));
    }

    /**
     * One title element, not two. The name appears in both bands by design, but
     * only the upper one is a heading — a second would leave the page with two.
     */
    public function testTheHeaderHasASingleHeading(): void
    {
        $crawler = $this->client->request('GET', $this->urlGenerator->generate('backend_editorial_posts'));

        self::assertSame(1, $crawler->filter('header h2')->count());
        self::assertSame(0, $crawler->filter('header h1')->count());
    }

    /**
     * The one page with no ancestors.
     *
     * One crumb, so a trail of one — which is the shape this test file exists to
     * forbid everywhere else. Here it is not a lost level, it is the whole path,
     * and there is nothing above the profile to link back to.
     */
    public function testAPageWithoutAncestorsStillSaysWhereYouAre(): void
    {
        $crawler = $this->client->request('GET', $this->urlGenerator->generate('backend_general_profile'));

        self::assertResponseIsSuccessful();

        $trail = mb_trim($crawler->filter('header nav[aria-label]')->text());
        self::assertNotSame('', $trail, 'With no trail to draw, the band must still name the page.');
        self::assertSame(mb_trim($crawler->filter('header h2')->text()), $trail);
    }
}
