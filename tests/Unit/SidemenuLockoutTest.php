<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * The control that brings the sidemenu back must not live inside the sidemenu.
 *
 * Hiding the menu now removes it completely — `visibility: hidden`, off-screen,
 * out of the tab order — where it used to fold to a rail of icons that always
 * kept something clickable on screen. That rail was an accidental safety net:
 * while it existed, a toggle placed inside the menu still worked. It does not
 * any more. A toggle rendered inside the `<aside>` would disappear along with
 * the thing it reveals, and the only way out would be clearing a database
 * column by hand.
 *
 * So the button lives in the page header. This asserts that, statically,
 * because the failure is silent: the menu hides correctly, the page looks
 * right, and the session is simply stuck that way until someone edits the user
 * row. Nothing in a rendering test would notice.
 *
 * Read from the sources rather than driven in a browser for the reason
 * AuroraGridGutterTest gives: the Playwright suite is not part of `make ft` or
 * CI, so an end-to-end check would not stop the regression from being pushed.
 */
final class SidemenuLockoutTest extends TestCase
{
    private const string TOGGLE = 'AppSidemenuToggle';

    private const string HEADER = '/src/Core/templates/Shared/components/page_header.html.twig';

    private const string ASIDE = '/src/Core/assets/backend/sidemenu/AppSidemenu.vue';

    private const string STYLESHEET = '/src/Core/assets/backend/sidemenu/sidemenu.css';

    public function testTheToggleIsMountedOutsideTheMenu(): void
    {
        self::assertStringContainsString(
            self::TOGGLE,
            $this->read(self::HEADER),
            'The page header must mount the sidemenu toggle: it is the only control left once the menu is hidden.',
        );
    }

    public function testTheMenuDoesNotCarryItsOwnToggle(): void
    {
        $aside = $this->read(self::ASIDE);

        // The mobile drawer's burger is a different control and stays: the
        // drawer is not hidden by `.sidemenu-collapsed`, and its own button
        // sits in the mobile top bar, outside the drawer.
        self::assertFalse(
            str_contains($aside, self::TOGGLE),
            sprintf(
                '%s must not render %s: it would vanish with the menu it reveals, leaving no way to bring it back.',
                self::ASIDE,
                self::TOGGLE,
            ),
        );
    }

    /**
     * Sliding the menu off-screen is not enough on its own.
     *
     * A `translateX(-100%)` aside is invisible but still focusable, so Tab
     * would walk a sighted keyboard user through a menu they cannot see, and a
     * screen reader would still announce every item. `visibility: hidden` is
     * what actually takes it out of both.
     */
    public function testHidingTheMenuAlsoTakesItOutOfTheTabOrder(): void
    {
        $css = $this->read(self::STYLESHEET);

        self::assertSame(
            1,
            preg_match(
                '/\.sidemenu-collapsed\s+#sidemenu\s*\{[^}]*visibility:\s*hidden/',
                $css,
            ),
            'The collapsed rule must set `visibility: hidden`, or the hidden menu keeps answering Tab.',
        );
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2).$relative;
        $contents = file_get_contents($path);

        self::assertNotFalse($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
