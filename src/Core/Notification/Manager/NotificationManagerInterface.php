<?php

declare(strict_types=1);

namespace Aurora\Core\Notification\Manager;

use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;

interface NotificationManagerInterface
{
    /**
     * @param string|null          $url  **A path, not an absolute address.** The link stays
     *                                   inside the application, and the browser resolves it
     *                                   against whatever host the person is on. Written
     *                                   absolute, it carries the host of the routing context
     *                                   at the time the notification is written - and many are
     *                                   written by the worker, with no HTTP request, so that
     *                                   context falls back to `localhost`: locally, clicking
     *                                   one led to a refused connection. An email is the
     *                                   opposite case and needs the absolute address.
     * @param array<string, mixed> $data
     */
    public function notify(
        CoreUserInterface $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        array $data = [],
    ): NotificationInterface;

    public function markRead(NotificationInterface $notification): void;

    public function markAllReadForUser(User $user): int;

    public function delete(NotificationInterface $notification): void;

    public function deleteAllForUser(User $user): int;
}
