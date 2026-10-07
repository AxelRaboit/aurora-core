<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Menu\Service;

use Aurora\Module\Editorial\Menu\Service\MenuActiveTrail;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Navigation that highlights the wrong entry is worse than navigation that
 * highlights none: it tells the reader they are somewhere they are not.
 */
final class MenuActiveTrailTest extends TestCase
{
    /** @param array<string, string> $routeParameters what the router matched */
    private function trail(?string $uri = '/fr/page/a-propos', array $routeParameters = []): MenuActiveTrail
    {
        $stack = new RequestStack();

        if (null !== $uri) {
            $request = Request::create('https://example.test'.$uri);

            if ([] !== $routeParameters) {
                $request->attributes->set('_route_params', $routeParameters);
            }

            $stack->push($request);
        }

        return new MenuActiveTrail($stack);
    }

    public function testTheEntryForThePageIsCurrent(): void
    {
        $trail = $this->trail();

        self::assertTrue($trail->isCurrent($trail->currentPath(), '/fr/page/a-propos'));
        self::assertFalse($trail->isCurrent($trail->currentPath(), '/fr/services'));
    }

    /** Two spellings of one address, so one of them must not miss. */
    public function testATrailingSlashIsTheSameAddress(): void
    {
        $trail = $this->trail('/fr/services/');

        self::assertTrue($trail->isCurrent($trail->currentPath(), '/fr/services'));
    }

    /** A filter or a campaign tag is not a different page. */
    public function testAQueryStringDoesNotChangeThePage(): void
    {
        $trail = $this->trail('/fr/projets?page=2');

        self::assertTrue($trail->isCurrent($trail->currentPath(), '/fr/projets'));
    }

    /**
     * The point of the second question: a reader who follows a link into a
     * section should still see which section they are in.
     */
    public function testASectionStaysLitFromInsideIt(): void
    {
        $trail = $this->trail('/fr/projets/onyx');
        $current = $trail->currentPath();

        self::assertFalse($trail->isCurrent($current, '/fr/projets'));
        self::assertTrue($trail->isAncestorOf($current, '/fr/projets'));
    }

    /**
     * `/fr/projets` must not claim `/fr/projets-secrets`. Comparing the raw
     * prefix would, which is the classic way this feature goes wrong.
     */
    public function testASiblingWhoseNameStartsTheSameIsNotInside(): void
    {
        $trail = $this->trail('/fr/projets-secrets');

        self::assertFalse($trail->isAncestorOf($trail->currentPath(), '/fr/projets'));
    }

    /** An address on another site is never the page we are on. */
    public function testALinkToAnotherSiteIsNeverCurrent(): void
    {
        $trail = $this->trail('/fr/page/a-propos');

        self::assertFalse($trail->isCurrent($trail->currentPath(), 'https://github.com/fr/page/a-propos'));
        self::assertFalse($trail->isAncestorOf($trail->currentPath(), 'https://github.com/fr'));
    }

    /** An absolute link home is still home. */
    public function testAnAbsoluteLinkToThisSiteStillCounts(): void
    {
        $trail = $this->trail('/fr/services');

        self::assertTrue($trail->isCurrent($trail->currentPath(), 'https://example.test/fr/services'));
    }

    /** An entry that resolved to nothing cannot be anywhere. */
    public function testAnEntryWithoutADestinationIsNeither(): void
    {
        $trail = $this->trail();
        $current = $trail->currentPath();

        self::assertFalse($trail->isCurrent($current, null));
        self::assertFalse($trail->isAncestorOf($current, null));
    }

    /** Rendered from the console, a menu marks nothing rather than guessing. */
    public function testWithoutARequestNothingIsCurrent(): void
    {
        $trail = $this->trail(null);

        self::assertNull($trail->currentPath());
        self::assertFalse($trail->isCurrent(null, '/fr/services'));
        self::assertFalse($trail->isAncestorOf(null, '/fr/services'));
    }

    /**
     * The case the paths cannot answer: a hub page at /fr/page/aurora and the
     * publications it introduces at /fr/aurora/…, which share no prefix. The
     * entry that heads the section reads the section instead.
     */
    public function testTheSectionOfThePageIsReadFromTheRoute(): void
    {
        $trail = $this->trail('/fr/aurora/en-tete-de-page', [
            'locale' => 'fr',
            'postTypeSlug' => 'aurora',
            'slug' => 'en-tete-de-page',
        ]);

        self::assertSame('aurora', $trail->currentPostTypeSlug());
        // And the paths still disagree, which is why the section is needed.
        self::assertFalse($trail->isAncestorOf($trail->currentPath(), '/fr/page/aurora'));
    }

    /** A page that belongs to no type says so rather than guessing one. */
    public function testAPageOutsideATypeHasNoSection(): void
    {
        self::assertNull($this->trail('/fr')->currentPostTypeSlug());
        self::assertNull($this->trail(null)->currentPostTypeSlug());
    }

    /** The root is above everything, so it is never anyone's ancestor here. */
    public function testTheRootIsNotAnAncestor(): void
    {
        $trail = $this->trail('/fr/services');

        self::assertFalse($trail->isAncestorOf($trail->currentPath(), '/'));
    }
}
