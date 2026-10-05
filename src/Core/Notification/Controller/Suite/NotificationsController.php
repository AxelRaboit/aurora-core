<?php

declare(strict_types=1);

namespace Aurora\Core\Notification\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Core\Notification\Repository\NotificationRepository;
use Aurora\Core\Notification\Serializer\NotificationSerializer;
use Aurora\Module\Platform\User\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/notifications', name: 'suite_notifications')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class NotificationsController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationSerializer $serializer,
        private readonly NotificationManagerInterface $notificationManager,
    ) {}

    #[Route('', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        $user = $this->requireUser();
        $entries = $this->notificationRepository->findRecentForUser($user);

        return $this->jsonSuccess([
            'entries' => array_map($this->serializer->serialize(...), $entries),
            'unreadCount' => $this->notificationRepository->unreadCountForUser($user),
        ]);
    }

    #[Route('/{id}/read', name: '_mark_read', methods: [HttpMethodEnum::Post->value])]
    public function markRead(#[MapEntity(id: 'id')] NotificationInterface $notification): JsonResponse
    {
        $user = $this->requireUser();
        if ($notification->getRecipient()->getId() !== $user->getId()) {
            throw new AccessDeniedHttpException();
        }

        $this->notificationManager->markRead($notification);

        return $this->jsonSuccess();
    }

    #[Route('/read-all', name: '_mark_all_read', methods: [HttpMethodEnum::Post->value])]
    public function markAllRead(): JsonResponse
    {
        $user = $this->requireUser();
        $count = $this->notificationManager->markAllReadForUser($user);

        return $this->jsonSuccess(['count' => $count]);
    }

    #[Route('/{id}', name: '_delete', methods: [HttpMethodEnum::Delete->value])]
    public function delete(#[MapEntity(id: 'id')] NotificationInterface $notification): JsonResponse
    {
        $user = $this->requireUser();
        if ($notification->getRecipient()->getId() !== $user->getId()) {
            throw new AccessDeniedHttpException();
        }

        $this->notificationManager->delete($notification);

        return $this->jsonSuccess();
    }

    #[Route('', name: '_delete_all', methods: [HttpMethodEnum::Delete->value])]
    public function deleteAll(): JsonResponse
    {
        $user = $this->requireUser();
        $this->notificationManager->deleteAllForUser($user);

        return $this->jsonSuccess();
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }
}
