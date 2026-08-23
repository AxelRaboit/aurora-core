<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Platform\User\Enum;

use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use PHPUnit\Framework\TestCase;

/**
 * A role's colour, which is one decision said in two vocabularies.
 *
 * `AppBadge` takes a preset name, a canvas takes a `--chart-cat-*` slot, and the
 * two have to mean the same thing: a reader who learns that orange is an admin
 * on the dashboard has to find orange on the users list. Four screens used to
 * decide this independently and no two agreed.
 */
final class UserRoleColourTest extends TestCase
{
    public function testEveryRoleHasBothHalvesOfItsColour(): void
    {
        foreach (UserRoleEnum::cases() as $role) {
            self::assertNotSame('', $role->badgeColor(), $role->value.' has no badge colour.');
            self::assertGreaterThanOrEqual(1, $role->chartSlot());
            // Eight slots exist; past them the palette has no distinguishable
            // hue left, which is a rule of the palette and not of this enum.
            self::assertLessThanOrEqual(8, $role->chartSlot());
        }
    }

    /**
     * Two roles sharing a colour is the failure this exists to catch: the badge
     * still renders, the chart still draws, and two different things look the
     * same on every screen at once.
     */
    public function testNoTwoRolesShareAColour(): void
    {
        $badges = array_map(static fn (UserRoleEnum $r): string => $r->badgeColor(), UserRoleEnum::cases());
        $slots = array_map(static fn (UserRoleEnum $r): int => $r->chartSlot(), UserRoleEnum::cases());

        self::assertSame($badges, array_values(array_unique($badges)));
        self::assertSame($slots, array_values(array_unique($slots)));
    }

    /**
     * The mapping the dashboard was already showing, and which the rest of the
     * app now follows: blue, orange, green.
     */
    public function testTheMappingIsTheOneOnScreen(): void
    {
        self::assertSame(['badge' => 'sky', 'slot' => 1], UserRoleEnum::User->colour());
        self::assertSame(['badge' => 'amber', 'slot' => 2], UserRoleEnum::Admin->colour());
        self::assertSame(['badge' => 'emerald', 'slot' => 3], UserRoleEnum::Dev->colour());
    }

    /**
     * The badge preset has to be one `AppBadge` knows. A name it does not know
     * falls back to grey, which is a role losing its colour without anything
     * failing.
     */
    public function testEveryBadgeColourIsOneAppBadgeDefines(): void
    {
        $component = (string) file_get_contents(
            dirname(__DIR__, 6).'/src/Core/assets/shared/components/feedback/AppBadge.vue',
        );

        foreach (UserRoleEnum::cases() as $role) {
            self::assertMatchesRegularExpression(
                '/^\s+'.preg_quote($role->badgeColor(), '/').':/m',
                $component,
                sprintf('AppBadge has no `%s` preset, so %s would render grey.', $role->badgeColor(), $role->value),
            );
        }
    }
}
