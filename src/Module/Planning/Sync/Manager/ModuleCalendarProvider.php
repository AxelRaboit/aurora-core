<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Sync\Manager;

use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The calendar a module's dates land in, made on first use.
 *
 * Created lazily rather than seeded by a migration, because a module that never
 * announces a date should not leave an empty calendar in everybody's sidebar -
 * and the set of modules that announce dates is not knowable when the migration
 * runs.
 *
 * Shared and ownerless. Ownerless is deliberate: nobody made it, so nobody should
 * find it under "my calendars" as though they had. Shared is what makes it
 * visible at all - `findVisibleTo` returns calendars you own or that are shared,
 * and a private ownerless calendar is visible to no one, which is a trap this
 * module has already fallen into once in a fixture.
 */
final readonly class ModuleCalendarProvider
{
    public function __construct(
        private PlanningRepository $plannings,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param string $sourceType the module's own key, e.g. `editorial.post`
     * @param string $name       what the module calls it, already translated
     */
    public function forSource(string $sourceType, string $name): PlanningInterface
    {
        $existing = $this->plannings->findOneBy(['sourceType' => $sourceType]);
        if (null !== $existing) {
            // Renamed if the module now calls it something else - a translation
            // changing should not leave the old wording on screen for ever.
            if ('' !== $name && $name !== $existing->getName()) {
                $existing->setName($name);
            }

            return $existing;
        }

        $planning = new Planning();
        $planning->setName('' !== $name ? $name : $sourceType);
        $planning->setSourceType($sourceType);
        $planning->setVisibility(PlanningVisibilityEnum::Shared);
        $planning->setColourSlot($this->nextFreeColourSlot());

        $this->entityManager->persist($planning);

        return $planning;
    }

    /**
     * The first palette slot no calendar is using.
     *
     * So a module's calendar does not arrive the same colour as one of yours.
     * Walks the palette and starts over once all eight are taken: sharing a
     * colour beats a ninth nobody can tell from the first.
     */
    private function nextFreeColourSlot(): int
    {
        $taken = array_column(
            $this->plannings->createQueryBuilder('p')
                ->select('DISTINCT p.colourSlot AS slot')
                ->getQuery()
                ->getArrayResult(),
            'slot',
        );

        for ($slot = 1; $slot <= Planning::MAX_COLOUR_SLOT; ++$slot) {
            if (!in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        return Planning::DEFAULT_COLOUR_SLOT;
    }
}
