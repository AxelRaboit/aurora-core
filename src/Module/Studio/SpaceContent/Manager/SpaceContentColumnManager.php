<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentColumnInputInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(SpaceContentColumnManagerInterface::class)]
class SpaceContentColumnManager implements SpaceContentColumnManagerInterface
{
    /**
     * The five steps a piece of content goes through, as translation keys.
     *
     * Five and not three: "à valider" and "programmé" are the two the whole
     * feature exists for, and a board that starts at "à faire / en cours /
     * fait" teaches a task tracker instead of an editorial process.
     *
     * Keys rather than strings, resolved at creation time, so the columns
     * arrive in the language of the person who made the space. They are data
     * from that moment on: renaming one here never renames one already created,
     * which is correct - somebody may have called it something else.
     *
     * The colours are chosen, not walked in order. Ideas carry none, because a
     * step that holds everything not started yet is the board's background
     * rather than a state worth flagging; then yellow for what is waiting on
     * somebody, aqua for what is settled, green for what is out. The two that
     * matter on a calendar - "à valider" and "programmé" - are the two a reader
     * has to tell apart at a glance.
     */
    protected const array DEFAULT_COLUMNS = [
        ['backend.studio.space_content.default_columns.idea', null],
        ['backend.studio.space_content.default_columns.writing', 1],
        ['backend.studio.space_content.default_columns.review', 4],
        ['backend.studio.space_content.default_columns.scheduled', 3],
        ['backend.studio.space_content.default_columns.published', 6],
    ];

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly SpaceContentColumnRepository $columnRepository,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function create(CustomerSpaceInterface $space, SpaceContentColumnInputInterface $input): SpaceContentColumnInterface
    {
        $column = $this->createColumn();
        $column
            ->setSpace($space)
            ->setName($input->getName())
            ->setColourSlot($input->getColourSlot())
            ->setVisibleToClient($input->isVisibleToClient())
            ->setPosition($this->columnRepository->nextPosition($space));

        $this->entityManager->persist($column);
        $this->entityManager->flush();

        $this->auditCreated($column);

        return $column;
    }

    public function update(SpaceContentColumnInterface $column, SpaceContentColumnInputInterface $input): void
    {
        $column
            ->setName($input->getName())
            ->setColourSlot($input->getColourSlot())
            ->setVisibleToClient($input->isVisibleToClient());
        $this->entityManager->flush();

        $this->auditUpdated($column);
    }

    /**
     * A column holding cards is not deleted.
     *
     * Said here and not by the database, which was the other option and the
     * wrong one: the foreign key fires on a cascade from the space too, where
     * the deletion is legitimate, so a restriction there would refuse both. The
     * count is in the message because "move them first" is only actionable when
     * the reader knows how many there are.
     */
    public function delete(SpaceContentColumnInterface $column): void
    {
        $items = $this->columnRepository->countItems($column);
        if ($items > 0) {
            throw new FieldException('column', $this->translator->trans('backend.studio.space_content.errors.column_not_empty', ['{count}' => (string) $items]));
        }

        if (1 === count($this->columnRepository->findForSpace($column->getSpace()))) {
            throw new FieldException('column', $this->translator->trans('backend.studio.space_content.errors.column_last'));
        }

        $this->auditDeleted($column);

        $this->entityManager->remove($column);
        $this->entityManager->flush();
    }

    /**
     * Writes positions from the order the page sent.
     *
     * The whole order every time rather than "this one moved to index 3": the
     * page knows the list it is showing, and rewriting all of it cannot leave
     * two columns claiming the same place. Ids that are not this space's are
     * skipped, which is what makes the endpoint safe to call with a stale list.
     *
     * @param list<int> $columnIds
     */
    public function reorder(CustomerSpaceInterface $space, array $columnIds): void
    {
        $byId = [];
        foreach ($this->columnRepository->findForSpace($space) as $column) {
            $byId[(int) $column->getId()] = $column;
        }

        $position = 0;
        foreach ($columnIds as $columnId) {
            $column = $byId[$columnId] ?? null;

            if (null === $column) {
                continue;
            }

            $column->setPosition($position);
            ++$position;
            unset($byId[$columnId]);
        }

        // Anything the page did not name keeps its relative order, after the
        // rest. A column created in another tab must not jump to the front.
        foreach ($byId as $column) {
            $column->setPosition($position);
            ++$position;
        }

        $this->entityManager->flush();
    }

    public function seedDefaults(CustomerSpaceInterface $space): void
    {
        $position = 0;

        foreach (static::DEFAULT_COLUMNS as [$key, $colourSlot]) {
            $column = $this->createColumn();
            $column
                ->setSpace($space)
                ->setName($this->translator->trans($key))
                ->setColourSlot($colourSlot)
                ->setPosition($position);

            $this->entityManager->persist($column);
            ++$position;
        }
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createColumn(): SpaceContentColumnInterface
    {
        return new SpaceContentColumn();
    }

    protected function auditCreated(SpaceContentColumnInterface $column): void
    {
        $this->auditLogger->log('studio', 'space_content_column.created', 'SpaceContentColumn', $column->getId(), $this->auditPayload($column));
    }

    protected function auditUpdated(SpaceContentColumnInterface $column): void
    {
        $this->auditLogger->log('studio', 'space_content_column.updated', 'SpaceContentColumn', $column->getId(), $this->auditPayload($column));
    }

    protected function auditDeleted(SpaceContentColumnInterface $column): void
    {
        $this->auditLogger->log('studio', 'space_content_column.deleted', 'SpaceContentColumn', $column->getId(), $this->auditPayload($column));
    }

    /** @return array<string, mixed> */
    protected function auditPayload(SpaceContentColumnInterface $column): array
    {
        return [
            'name' => $column->getName(),
            'colourSlot' => $column->getColourSlot(),
            'visibleToClient' => $column->isVisibleToClient(),
            'spaceId' => $column->getSpace()->getId(),
            'spaceName' => $column->getSpace()->getName(),
        ];
    }
}
