<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\Customer\View\CustomersViewBuilder;
use Aurora\Module\Studio\Pipeline\Dto\PipelineStageInputFactoryInterface;
use Aurora\Module\Studio\Pipeline\Dto\PipelineStageInputInterface;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStage;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use Aurora\Module\Studio\Pipeline\Manager\PipelineManagerInterface;
use Aurora\Module\Studio\Pipeline\Manager\PipelineStageManagerInterface;
use Aurora\Module\Studio\Pipeline\View\PipelineViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_numeric;

/**
 * The prospect pipeline: moving customers between stages, and the stages
 * themselves.
 *
 * Under the customers' address and their rights, because the pipeline is a
 * way of looking at customers rather than a list of its own: whoever may edit
 * a customer may move it, and arranging the stages is part of the same right.
 *
 * Every gesture answers with the whole board and the whole list, which the
 * page swaps in: a move changes a row (its stage, its follow-up when lost),
 * and the board is the server's word on where each card is.
 */
#[Route('/suite/studio/customers/pipeline', name: 'suite_studio_customers_pipeline')]
#[IsGranted('studio.customers.view')]
class PipelineController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly PipelineManagerInterface $pipelineManager,
        protected readonly PipelineStageManagerInterface $stageManager,
        protected readonly PipelineStageInputFactoryInterface $stageInputFactory,
        protected readonly PipelineViewBuilder $pipelineViewBuilder,
        protected readonly CustomersViewBuilder $customersViewBuilder,
        protected readonly PayloadValidator $payloadValidator,
    ) {}

    /**
     * A card dropped in a stage: `{stageId, customerIds, lostReason?}`, the
     * stage's whole new order.
     */
    #[Route('/move', name: '_move', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function move(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $stageId = $data['stageId'] ?? null;
        $stage = is_numeric($stageId) ? $this->stageById((int) $stageId) : null;

        if (!$stage instanceof PipelineStageInterface) {
            return $this->jsonNotFound();
        }

        try {
            $this->pipelineManager->move($stage, $this->ids($data['customerIds'] ?? []), Str::trimOrNullFromArray($data, 'lostReason'));
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->payload());
    }

    #[Route('/stages/create', name: '_stage_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function createStage(Request $request): JsonResponse
    {
        return $this->withStageInput($request, function (PipelineStageInputInterface $input): void {
            $this->stageManager->create($input);
        });
    }

    #[Route('/stages/{stageId}/update', name: '_stage_update', requirements: ['stageId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function updateStage(#[MapEntity(id: 'stageId')] PipelineStage $stage, Request $request): JsonResponse
    {
        return $this->withStageInput($request, function (PipelineStageInputInterface $input) use ($stage): void {
            $this->stageManager->update($stage, $input);
        });
    }

    #[Route('/stages/{stageId}/delete', name: '_stage_delete', requirements: ['stageId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function deleteStage(#[MapEntity(id: 'stageId')] PipelineStage $stage): JsonResponse
    {
        try {
            $this->stageManager->delete($stage);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->payload());
    }

    /** `{stageIds}`: the stages' whole new order, left to right. */
    #[Route('/stages/reorder', name: '_stage_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.customers.edit')]
    public function reorderStages(Request $request): JsonResponse
    {
        $this->stageManager->reorder($this->ids($this->decodeJson($request)['stageIds'] ?? []));

        return $this->jsonSuccess($this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'customers' => $this->customersViewBuilder->customers(),
            'pipeline' => $this->pipelineViewBuilder->board(),
        ];
    }

    private function stageById(int $stageId): ?PipelineStageInterface
    {
        foreach ($this->stageManager->stages() as $stage) {
            if ($stage->getId() === $stageId) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * The integers of a list the page sent, and nothing else: a stale or
     * tampered list must not reach the Manager as strings or nulls.
     *
     * @return list<int>
     */
    private function ids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /** @param callable(PipelineStageInputInterface):void $save */
    private function withStageInput(Request $request, callable $save): JsonResponse
    {
        $input = $this->stageInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $save($input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->payload());
    }
}
