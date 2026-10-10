<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Pipeline\Dto\PipelineStageInputInterface;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStage;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use Aurora\Module\Studio\Pipeline\Enum\PipelineStageRoleEnum;
use Aurora\Module\Studio\Pipeline\Repository\PipelineStageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;

#[AsAlias(PipelineStageManagerInterface::class)]
class PipelineStageManager implements PipelineStageManagerInterface
{
    /**
     * The five stages a pipeline starts with, as translation keys.
     *
     * Three in progress and the two outcomes. Fewer and the board says
     * nothing a list would not; more and every prospect needs a decision
     * about which column it is in, which is the moment people stop moving
     * cards. Keys rather than strings, resolved at creation time, so the
     * stages arrive in the language of whoever first opened the pipeline;
     * they are data from then on.
     *
     * The colours are chosen: none for what has just arrived, yellow and aqua
     * for the two stages where something is awaited, green for won, and none
     * for lost, which is the board's way out rather than a state to flag.
     */
    protected const array DEFAULT_STAGES = [
        ['suite.studio.pipeline.default_stages.new', null, null],
        ['suite.studio.pipeline.default_stages.contacted', 4, null],
        ['suite.studio.pipeline.default_stages.proposal_sent', 3, null],
        ['suite.studio.pipeline.default_stages.won', 6, PipelineStageRoleEnum::Won],
        ['suite.studio.pipeline.default_stages.lost', null, PipelineStageRoleEnum::Lost],
    ];

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly PipelineStageRepository $stageRepository,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function create(PipelineStageInputInterface $input): PipelineStageInterface
    {
        $this->assertRoleIsFree($input->getRole(), null);

        $stage = $this->createStage();
        $stage
            ->setName($input->getName())
            ->setColourSlot($input->getColourSlot())
            ->setRole($input->getRole())
            ->setPosition($this->stageRepository->nextPosition());

        $this->entityManager->persist($stage);
        $this->entityManager->flush();

        $this->auditCreated($stage);

        return $stage;
    }

    public function update(PipelineStageInterface $stage, PipelineStageInputInterface $input): void
    {
        $this->assertRoleIsFree($input->getRole(), $stage->getId());

        // Turning the last stage in progress into an outcome would leave the
        // pipeline nowhere to show a prospect that has no stage yet.
        if (!$stage->getRole() instanceof PipelineStageRoleEnum && $input->getRole() instanceof PipelineStageRoleEnum) {
            $this->assertAnotherStageInProgress($stage);
        }

        $stage
            ->setName($input->getName())
            ->setColourSlot($input->getColourSlot())
            ->setRole($input->getRole());
        $this->entityManager->flush();

        $this->auditUpdated($stage);
    }

    /**
     * A stage holding customers is not deleted, and neither is the last stage
     * in progress.
     *
     * The first rule, because a customer whose stage disappears would land
     * silently in the first column, which is not where anybody put them. The
     * count is in the message: "move them first" is only actionable when the
     * reader knows how many there are. The second, because a prospect with no
     * stage shows in the first stage in progress, and a pipeline made only of
     * outcomes would have nowhere to show it.
     */
    public function delete(PipelineStageInterface $stage): void
    {
        $customers = $this->stageRepository->countCustomers($stage);
        if ($customers > 0) {
            throw new FieldException('stage', $this->translator->trans('suite.studio.pipeline.errors.stage_not_empty', ['{count}' => (string) $customers, '%count%' => $customers]));
        }

        if (!$stage->getRole() instanceof PipelineStageRoleEnum) {
            $this->assertAnotherStageInProgress($stage);
        }

        $this->auditDeleted($stage);

        $this->entityManager->remove($stage);
        $this->entityManager->flush();
    }

    /**
     * Writes positions from the order the page sent: the whole order every
     * time, so two stages can never claim the same place, and anything the
     * page did not name keeps its relative order after the rest.
     *
     * @param list<int> $stageIds
     */
    public function reorder(array $stageIds): void
    {
        $byId = [];
        foreach ($this->stageRepository->findOrdered() as $stage) {
            $byId[(int) $stage->getId()] = $stage;
        }

        $position = 0;
        foreach ($stageIds as $stageId) {
            $stage = $byId[$stageId] ?? null;

            if (null === $stage) {
                continue;
            }

            $stage->setPosition($position);
            ++$position;
            unset($byId[$stageId]);
        }

        foreach ($byId as $stage) {
            $stage->setPosition($position);
            ++$position;
        }

        $this->entityManager->flush();
    }

    /**
     * The stages, seeded on first read.
     *
     * On first read rather than at install only: a project that upgrades
     * never runs the install, and its pipeline must still be usable the first
     * time somebody opens it. The bootstrap calls this too, so a fresh
     * install shows the stages in the default language of the site.
     */
    public function stages(): array
    {
        $stages = $this->stageRepository->findOrdered();

        if ([] !== $stages) {
            return $stages;
        }

        $this->seedDefaults();
        $this->entityManager->flush();

        return $this->stageRepository->findOrdered();
    }

    protected function seedDefaults(): void
    {
        $position = 0;

        foreach (static::DEFAULT_STAGES as [$key, $colourSlot, $role]) {
            $stage = $this->createStage();
            $stage
                ->setName($this->translator->trans($key))
                ->setColourSlot($colourSlot)
                ->setRole($role)
                ->setPosition($position);

            $this->entityManager->persist($stage);
            ++$position;
        }
    }

    /**
     * One stage per outcome: "won" must mean one column, the one a converted
     * client is filed under. Reported under the role field, naming the stage
     * that already holds it.
     */
    protected function assertRoleIsFree(?PipelineStageRoleEnum $role, ?int $stageId): void
    {
        if (!$role instanceof PipelineStageRoleEnum) {
            return;
        }

        $holder = $this->stageRepository->findOneByRole($role);

        if (!$holder instanceof PipelineStageInterface || $holder->getId() === $stageId) {
            return;
        }

        throw new FieldException('role', $this->translator->trans('suite.studio.pipeline.errors.role_taken', ['{name}' => $holder->getName()]));
    }

    protected function assertAnotherStageInProgress(PipelineStageInterface $stage): void
    {
        $inProgress = array_filter(
            $this->stageRepository->findOrdered(),
            static fn (PipelineStageInterface $other): bool => !$other->getRole() instanceof PipelineStageRoleEnum && $other->getId() !== $stage->getId(),
        );

        if ([] === $inProgress) {
            throw new FieldException('stage', $this->translator->trans('suite.studio.pipeline.errors.stage_last_in_progress'));
        }
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createStage(): PipelineStageInterface
    {
        return new PipelineStage();
    }

    protected function auditCreated(PipelineStageInterface $stage): void
    {
        $this->auditLogger->log('studio', 'pipeline_stage.created', 'PipelineStage', $stage->getId(), $this->auditPayload($stage));
    }

    protected function auditUpdated(PipelineStageInterface $stage): void
    {
        $this->auditLogger->log('studio', 'pipeline_stage.updated', 'PipelineStage', $stage->getId(), $this->auditPayload($stage));
    }

    protected function auditDeleted(PipelineStageInterface $stage): void
    {
        $this->auditLogger->log('studio', 'pipeline_stage.deleted', 'PipelineStage', $stage->getId(), $this->auditPayload($stage));
    }

    /**
     * Structured payload logged with every audit entry. Override to add
     * extra fields: `[...parent::auditPayload($stage), 'code' => $stage->getCode()]`.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(PipelineStageInterface $stage): array
    {
        return [
            'name' => $stage->getName(),
            'role' => $stage->getRole()?->value,
        ];
    }
}
