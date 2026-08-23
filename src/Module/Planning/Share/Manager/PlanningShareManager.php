<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Share\Manager;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Share\Entity\PlanningShare;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The sharing list of one calendar.
 *
 * A manager and not controller code, which is where this lived first:
 * `convention_thin_controller` puts orchestration, entity hydration and
 * side-effects out of a controller, and a diff over a collection with a flush at
 * the end is all three.
 */
#[AsAlias(PlanningShareManagerInterface::class)]
class PlanningShareManager implements PlanningShareManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly UserRepository $users,
    ) {}

    /**
     * Brings the list in line with what was asked for, whole.
     *
     * Diffed rather than cleared and rebuilt: a change of level is then an update
     * rather than a delete and an insert, which the unique index would refuse in
     * that order anyway.
     *
     * @param array<int, bool> $wanted user id => may write
     */
    public function setShares(PlanningInterface $planning, array $wanted): void
    {
        foreach ($planning->getShares() as $existing) {
            $id = (int) $existing->getUser()->getId();

            if (!array_key_exists($id, $wanted)) {
                $planning->removeShare($existing);
                $this->entityManager->remove($existing);

                continue;
            }

            $existing->setCanWrite($wanted[$id]);
            unset($wanted[$id]);
        }

        foreach ($wanted as $id => $canWrite) {
            $person = $this->users->find($id);
            // An id that names nobody is dropped rather than refused: it means a
            // stale list, and failing the whole change over it would be the wrong
            // trade.
            if (!$person instanceof CoreUserInterface) {
                continue;
            }

            $share = new PlanningShare();
            $share->setUser($person);
            $share->setCanWrite($canWrite);
            $planning->addShare($share);
            $this->entityManager->persist($share);
        }

        $this->entityManager->flush();
    }
}
