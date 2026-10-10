<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerInteraction\Repository\CustomerInteractionRepository;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use Aurora\Module\Studio\Pipeline\Manager\PipelineStageManagerInterface;
use Aurora\Module\Studio\Pipeline\Serializer\PipelineStageSerializerInterface;
use Aurora\Module\Studio\Pipeline\Service\PipelineOutcomeWindow;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_keys;
use function array_map;

use const DATE_ATOM;

/**
 * The prospect board: its stages, and which customer stands where.
 *
 * **Ids rather than customers.** The page already holds every customer for
 * the list; the board says where each one is drawn, and the page fills the
 * cards from the rows it has. One source of truth for a customer's sheet,
 * two ways of laying it out.
 */
final readonly class PipelineViewBuilder
{
    public function __construct(
        private PipelineStageManagerInterface $stageManager,
        private PipelineStageSerializerInterface $stageSerializer,
        private CustomerRepository $customerRepository,
        private CustomerInteractionRepository $interactionRepository,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplateGenerator,
        private PipelineOutcomeWindow $outcomeWindow,
    ) {}

    /**
     * The stages alone, for the screens that only name them (a customer's
     * page, the list's column).
     *
     * @return list<array<string, mixed>>
     */
    public function stages(): array
    {
        return array_map($this->stageSerializer->serialize(...), $this->stageManager->stages());
    }

    /**
     * The board.
     *
     * A customer with no stage is drawn in the first stage in progress, which
     * is where the pipeline considers it to be.
     *
     * @return array{stages: list<array<string, mixed>>, columns: list<array{stageId: ?int, customerIds: list<int>}>, lastInteractions: array<int, string>, outcomeWindowDays: int}
     */
    public function board(): array
    {
        $stages = $this->stageManager->stages();

        $firstInProgress = null;
        $columns = [];
        foreach ($stages as $stage) {
            $columns[(int) $stage->getId()] = [];

            if (null === $firstInProgress && null === $stage->getRole()) {
                $firstInProgress = $stage;
            }
        }

        // How far back won and lost deals are shown: Settings > Studio.
        $outcomeDays = $this->outcomeWindow->days();
        $since = new DateTimeImmutable('-'.$outcomeDays.' days');

        foreach ($this->customerRepository->findForPipeline($since) as $customer) {
            $stage = $customer->getPipelineStage() ?? $firstInProgress;
            if (!$stage instanceof PipelineStageInterface) {
                continue;
            }

            if (!isset($columns[(int) $stage->getId()])) {
                continue;
            }

            $columns[(int) $stage->getId()][] = (int) $customer->getId();
        }

        $customerIds = [];
        foreach ($columns as $ids) {
            foreach ($ids as $id) {
                $customerIds[] = $id;
            }
        }

        $latest = [];
        foreach ($this->interactionRepository->latestByCustomer($customerIds) as $customerId => $occurredAt) {
            $latest[$customerId] = new DateTimeImmutable($occurredAt)->format(DATE_ATOM);
        }

        return [
            'stages' => array_map($this->stageSerializer->serialize(...), $stages),
            'columns' => array_map(
                static fn (int $stageId, array $ids): array => ['stageId' => $stageId, 'customerIds' => $ids],
                array_keys($columns),
                $columns,
            ),
            'lastInteractions' => $latest,
            'outcomeWindowDays' => $outcomeDays,
        ];
    }

    /**
     * Where the board's gestures are sent.
     *
     * @return array<string, string>
     */
    public function paths(): array
    {
        return [
            'movePath' => $this->urlGenerator->generate('suite_studio_customers_pipeline_move'),
            'stageCreatePath' => $this->urlGenerator->generate('suite_studio_customers_pipeline_stage_create'),
            'stageUpdatePath' => $this->pathTemplateGenerator->generate('suite_studio_customers_pipeline_stage_update', ['stageId' => '__id__']),
            'stageDeletePath' => $this->pathTemplateGenerator->generate('suite_studio_customers_pipeline_stage_delete', ['stageId' => '__id__']),
            'stageReorderPath' => $this->urlGenerator->generate('suite_studio_customers_pipeline_stage_reorder'),
        ];
    }
}
