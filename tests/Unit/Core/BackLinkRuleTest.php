<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function sort;
use function str_contains;
use function str_ends_with;

/**
 * A way back is drawn by AppBackLink, or by its Twig twin, and nothing else.
 *
 * The audit of 07/10/2026 found the one gesture drawn five ways - the
 * component, two Twig copies, a link of its own in the client's presentation,
 * a dead link in a shared note - and the copies had drifted: off the gutter,
 * 30 pixels instead of 38, an arrow here, a chevron there. Fixing the
 * component fixed none of the copies.
 *
 * So any file drawing a left chevron or arrow must be one of the two, or a
 * control that is not a way back - previous, next, a page, a slide - listed
 * below with its reason. A new screen that wants a way back uses the
 * component; a new previous/next control is added here, which says it was
 * looked at.
 */
final class BackLinkRuleTest extends TestCase
{
    /** The way back itself. */
    private const array BACK_LINK = [
        'src/Core/assets/shared/components/nav/AppBackLink.vue',
        'src/Core/templates/Shared/components/back_link.html.twig',
    ];

    /** Left arrows that are not a way up from a screen. */
    private const array NOT_A_WAY_BACK = [
        'src/Core/assets/shared/components/form/select/AppChoiceRow.vue' => 'scrolls the choices',
        'src/Core/assets/shared/components/nav/AppPagination.vue' => 'previous page of a list',
        'src/Core/templates/Shared/components/app_pagination.html.twig' => 'previous page of a list',
        'src/Core/assets/shared/components/overlay/AppLightbox.vue' => 'previous picture',
        'src/Core/assets/suite/sidemenu/AppTopbarNav.vue' => "the browser's own back, in an installed app",
        'src/Core/assets/suite/sidemenu/AppSidemenuHead.vue' => 'from a module back to all modules, inside the menu',
        'src/Core/templates/Shared/components/icon.html.twig' => 'the icon set',
        'src/Module/Studio/Calendar/assets/suite/calendar/StudioCalendarApp.vue' => 'previous month',
        'src/Module/Studio/SpaceAccess/assets/frontend/space/PublicSpaceApp.vue' => 'previous month',
        'src/Module/Studio/SpaceContent/assets/suite/content/views/SpaceCalendarView.vue' => 'previous month',
        'src/Module/Studio/Deliverable/assets/suite/slides/DeckPresenterApp.vue' => 'previous slide',
        'src/Module/Studio/Deliverable/assets/suite/slides/components/DeckPlayer.vue' => 'previous slide',
        'src/Module/Studio/templates/public/deliverable.html.twig' => 'previous slide',
        'src/Module/Editorial/assets/suite/posts/components/PostGalleryPanel.vue' => 'moves a picture earlier',
        'src/Module/Editorial/assets/suite/posts/components/PostBannerPanel.vue' => 'previous slide of the banner',
        'src/Module/Planning/assets/suite/planning/PlanningApp.vue' => 'previous period',
        'src/Module/Planning/assets/suite/share/PlanningShareApp.vue' => 'previous period',
        'src/Module/Notes/assets/suite/markdown/NoteReadApp.vue' => 'previous note',
        'src/Module/Notes/assets/suite/markdown/NoteTreePanel.vue' => 'collapses the tree',
        'src/Module/Notes/assets/suite/markdown/components/NoteLibrary.vue' => 'moves a card earlier',
        'src/Module/Notes/assets/suite/markdown/components/NoteJournalCalendar.vue' => 'previous month',
        'src/Module/Notes/assets/suite/markdown/components/NotePresentation.vue' => 'previous slide',
    ];

    public function testOnlyTheBackLinkDrawsAWayBack(): void
    {
        $root = dirname(__DIR__, 3);
        $strays = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = mb_substr($path, mb_strlen($root) + 1);

            if (str_ends_with($path, '.test.js') || str_contains($relative, '/node_modules/')) {
                continue;
            }

            $isVue = str_ends_with($path, '.vue');
            $isTwig = str_ends_with($path, '.twig');

            if (!$isVue && !$isTwig) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $drawsLeft = $isVue
                ? 1 === preg_match('/\b(ChevronLeft|ArrowLeft)\b/', $source)
                : 1 === preg_match('/m15 18-6-6 6-6|m12 19-7-7 7-7|chevron-left|arrow-left/', $source);

            if (!$drawsLeft || in_array($relative, self::BACK_LINK, true) || isset(self::NOT_A_WAY_BACK[$relative])) {
                continue;
            }

            $strays[] = $relative;
        }

        sort($strays);

        self::assertSame(
            [],
            $strays,
            "Une flèche gauche hors du composant de retour :\n".implode("\n", $strays)
            ."\nUn retour passe par AppBackLink.vue ou @Shared/components/back_link.html.twig ;"
            .' un précédent/suivant s\'ajoute à NOT_A_WAY_BACK avec sa raison.',
        );
    }
}
