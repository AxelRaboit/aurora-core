<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Recurrence\RecurrenceScopeEnum;
use Aurora\Tests\Unit\Module\Editorial\Post\Grid\GridContractMirrorTest;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function explode;
use function is_string;
use function mb_trim;
use function preg_match;
use function sort;
use function sprintf;

/**
 * The words the modals send, against the words the server takes.
 *
 * Two short lists live in `.vue` files because a radio group cannot ask the
 * server what to draw: the scope question offers three answers, and an invitation
 * offers three. Both are vocabularies the server owns, and
 * `convention_mirrored_contract_php_js` is explicit that a `Mirrors X` comment is
 * not a constraint - a test that reads the file is.
 *
 * What goes wrong without one is quiet on both sides. A word added to the enum and
 * not the modal is a capability nobody can reach; a word added to the modal and
 * not the enum is a button whose save is refused, and `fromRequest` falls back to
 * `Single` rather than complaining - so an edit meant for a whole series would be
 * written to one occurrence, which is exactly the loss the question exists to
 * prevent.
 *
 * Read statically, like {@see GridContractMirrorTest},
 * because the alternative - mounting the component - would not catch the enum side
 * at all.
 */
final class PlanningContractMirrorTest extends TestCase
{
    /**
     * The scope modal offers every series scope, and nothing else.
     *
     * `Single` is deliberately absent: it means "not a series", which is the case
     * where the modal never opens. Offering it would be a fourth radio saying
     * "treat this recurring event as though it were not one".
     *
     * Compared in order, because the enum's order is the order the modal reads in
     * - this occurrence, then the ones after it, then all of them, narrowest
     * first. A reordering on one side alone would be a list that no longer
     * escalates.
     */
    public function testTheScopeModalOffersExactlyTheSeriesScopes(): void
    {
        $expected = [];

        foreach (RecurrenceScopeEnum::cases() as $case) {
            if ($case->isSeriesScope()) {
                $expected[] = $case->value;
            }
        }

        self::assertSame(
            $expected,
            $this->jsList('RecurrenceScopeModal.vue', 'OPTIONS'),
            'RecurrenceScopeEnum and RecurrenceScopeModal.vue disagree about which '
            .'scopes exist. A scope the modal sends and the enum does not know is '
            .'read as Single, which writes a series edit to one occurrence.',
        );
    }

    /**
     * The event modal offers every status somebody can choose, and nothing else.
     *
     * `NeedsAction` is absent because it is where an invitation starts, not an
     * answer anybody gives - a button for it would mean "un-answer this", which
     * the endpoint does not support.
     *
     * Sorted rather than compared in order, and that is the point: the buttons run
     * yes, maybe, no, which is how the question is asked out loud and not the order
     * the enum happens to declare. Pinning the enum's order here would fail the day
     * somebody puts `Tentative` next to `Accepted`, which changes nothing about the
     * contract.
     */
    public function testTheEventModalOffersExactlyTheAnswersAnAttendeeCanGive(): void
    {
        $expected = [];

        foreach (PlanningAttendeeStatusEnum::cases() as $case) {
            if (PlanningAttendeeStatusEnum::NeedsAction !== $case) {
                $expected[] = $case->value;
            }
        }

        $offered = $this->jsList('EventModal.vue', 'ANSWERS');

        sort($expected);
        sort($offered);

        self::assertSame(
            $expected,
            $offered,
            'PlanningAttendeeStatusEnum and EventModal.vue disagree about which '
            .'answers an attendee can give.',
        );
    }

    /**
     * One flat list of quoted strings, read out of a component's script.
     *
     * @return list<string>
     */
    private function jsList(string $component, string $constant): array
    {
        $source = $this->source($component);

        $pattern = sprintf('/const %s = \[([^\]]+)\]/', $constant);

        self::assertSame(1, preg_match($pattern, $source, $matches), sprintf(
            '%s is not declared in %s under that name any more',
            $constant,
            $component,
        ));

        return array_map(
            static fn (string $value): string => mb_trim($value, " \t\n\"'"),
            explode(',', $matches[1]),
        );
    }

    private function source(string $component): string
    {
        $path = dirname(__DIR__, 4)
            .'/src/Module/Planning/assets/backend/planning/components/'.$component;

        $source = file_get_contents($path);

        self::assertTrue(is_string($source), sprintf('%s not found at %s', $component, $path));

        return $source;
    }
}
