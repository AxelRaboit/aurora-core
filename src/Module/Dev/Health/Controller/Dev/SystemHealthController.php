<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Controller\Dev;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Dev\Health\Queue\QueueInspector;
use Aurora\Module\Dev\Health\Report\SystemHealthReport;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The « État du système » block of the developer overview.
 *
 * Read after the page is on screen, by its own request: the report calls the
 * live hub, the storage and the site's certificate, and the overview opens
 * without waiting for them. The two writes - retry a failed message, delete
 * one - carry the `admin` CSRF token the administration screens already
 * receive.
 */
#[Route('/dev/dashboard/health', name: 'dev_dashboard_health')]
#[IsGranted(UserRoleEnum::Dev->value)]
final class SystemHealthController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly SystemHealthReport $report,
        private readonly QueueInspector $queueInspector,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function show(): JsonResponse
    {
        return $this->jsonSuccess(['health' => $this->report->build()]);
    }

    #[Route('/failed/{id}/retry', name: '_failed_retry', requirements: ['id' => '[A-Za-z0-9._-]+'], methods: [HttpMethodEnum::Post->value])]
    public function retry(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin', (string) ($this->decodeJson($request)['_token'] ?? ''))) {
            return $this->jsonFailure('suite.health.errors.csrf', HttpStatusEnum::Forbidden->value);
        }

        if (!$this->queueInspector->retry($id)) {
            return $this->jsonFailure('suite.health.errors.failure_not_found', HttpStatusEnum::NotFound->value);
        }

        $this->auditLogger->log('core', 'failed_message.retried', 'FailedMessage', null, ['id' => $id]);

        return $this->jsonSuccess(['health' => $this->report->build()]);
    }

    #[Route('/failed/{id}/delete', name: '_failed_delete', requirements: ['id' => '[A-Za-z0-9._-]+'], methods: [HttpMethodEnum::Post->value])]
    public function delete(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin', (string) ($this->decodeJson($request)['_token'] ?? ''))) {
            return $this->jsonFailure('suite.health.errors.csrf', HttpStatusEnum::Forbidden->value);
        }

        if (!$this->queueInspector->delete($id)) {
            return $this->jsonFailure('suite.health.errors.failure_not_found', HttpStatusEnum::NotFound->value);
        }

        $this->auditLogger->log('core', 'failed_message.deleted', 'FailedMessage', null, ['id' => $id]);

        return $this->jsonSuccess(['health' => $this->report->build()]);
    }
}
