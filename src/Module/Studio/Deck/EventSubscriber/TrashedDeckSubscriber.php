<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\EventSubscriber;

use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A deck in the trash is no longer there for any screen.
 *
 * Every route that takes `Deck $deck` (the editor, the presenter, the print
 * view, the slides, the share panel...) receives the deck the id names, trashed
 * or not. Checking in each of them is a rule somebody has to remember, and the
 * one that forgets serves a deck its owners believe is gone. Done once, on the
 * resolved arguments: a trashed deck answers like an unknown id, everywhere.
 *
 * The trash's own routes (restore, destroy) take the bare id and look the deck
 * up with the repository's `findTrashed()`, so they never pass through here.
 */
final readonly class TrashedDeckSubscriber implements EventSubscriberInterface
{
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments'];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof DeckInterface && $argument->isTrashed()) {
                throw new NotFoundHttpException();
            }
        }
    }
}
