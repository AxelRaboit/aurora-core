<?php

declare(strict_types=1);

namespace Aurora\Module\General\Trash\Service;

use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\General\Dashboard\Service\StatsService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Every trash in the application, gathered in one list.
 *
 * Mirrors {@see StatsService}: the
 * General shell owns no domain knowledge, each module contributes its own
 * source, and a module that is switched off contributes nothing rather than
 * showing rows nobody can act on.
 */
final readonly class TrashOverviewService
{
    /**
     * How many rows each trash shows at once.
     *
     * Not pagination: a trash is a waiting room, and one that holds more than
     * this has a purge to run rather than a page to leaf through. The count
     * above the list stays the real total, so nothing is hidden silently.
     */
    public const int ROWS_PER_TRASH = 100;

    /**
     * @param iterable<TrashSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('aurora.trash_source')]
        private iterable $sources,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    /**
     * @param list<string> $enabledModules module ids to include, matched against each
     *                                     source's getModuleKey()
     *
     * @return list<TrashSummary>
     */
    public function getSummaries(array $enabledModules): array
    {
        $summaries = [];
        foreach ($this->sources as $source) {
            if (!in_array($source->getModuleKey(), $enabledModules, true)) {
                continue;
            }

            $privilege = $source->getRequiredPrivilege();
            if (null !== $privilege && !$this->authorizationChecker->isGranted($privilege)) {
                continue;
            }

            $summaries[] = $source->getSummary(self::ROWS_PER_TRASH);
        }

        // What is waiting first, and the fullest of those at the top: the page
        // exists to say what there is to deal with, and an empty trash has
        // nothing to say.
        usort($summaries, static fn (TrashSummary $a, TrashSummary $b): int => [0 === $b->count ? 0 : 1, $b->count] <=> [0 === $a->count ? 0 : 1, $a->count]);

        return $summaries;
    }
}
