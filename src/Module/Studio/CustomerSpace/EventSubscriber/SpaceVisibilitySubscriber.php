<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\EventSubscriber;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A space one is not a member of does not exist.
 *
 * **Set on the arguments rather than in each controller**, and that is the
 * whole point. About ten controllers receive a space today, the
 * conversation, the notes, the files, the Drive, the client access; adding an
 * eleventh is something done without thinking, and the check line is
 * exactly what gets forgotten then. Here, the check arrives with the
 * argument.
 *
 * **A 404 and not a 403.** An explicit refusal would tell someone probing
 * that space number twelve exists and belongs to another customer. From the
 * point of view of a teammate who is not a member, there is nothing at this
 * address.
 *
 * **The trash goes through here too.** A space in the trash is seen by
 * nobody ({@see SpaceVisibility::canSee()}), and a content item in the trash,
 * with its thread and files, answers like an unknown record: every route that
 * receives a content item, a message or a file of a content item refuses it
 * without having to think about it. The trash acts on them by their id, never
 * as an argument.
 *
 * Public routes do not go through here: they resolve a link and never
 * receive a space as an argument. Their check is the link.
 */
final readonly class SpaceVisibilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SpaceVisibility $visibility,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments'];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof CustomerSpaceInterface && !$this->visibility->canSee($argument)) {
                throw new NotFoundHttpException();
            }

            $item = match (true) {
                $argument instanceof SpaceContentItemInterface => $argument,
                $argument instanceof SpaceContentCommentInterface, $argument instanceof SpaceContentAttachmentInterface => $argument->getItem(),
                default => null,
            };

            if ($item instanceof SpaceContentItemInterface && $item->isTrashed()) {
                throw new NotFoundHttpException();
            }
        }
    }
}
