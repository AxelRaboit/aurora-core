<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Hosting;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;
use function is_string;
use function str_starts_with;

/**
 * Sets the scope of every request to the notes engine.
 *
 * - With the host parameter ({@see NoteSpaceScope::HOST_PARAMETER}), the
 *   request works in that one hosted space, if its host lets the person in;
 *   otherwise 404, the same answer as for a space that does not exist.
 * - An image, asked by somebody without the module: the hosted spaces only.
 *   An image's address is written in the note's text and carries no host
 *   parameter, and the people who read a client space's notes are not all
 *   given the module.
 * - Anything else of the engine: the Notes module, without the hosted spaces
 *   in its lists.
 *
 * After the firewall, which restores the user, and on the main request only.
 */
final readonly class NoteSpaceScopeSubscriber implements EventSubscriberInterface
{
    /** The routes of the notes engine, whoever shows it. */
    private const string ROUTE_PREFIX = 'suite_notes_';

    /** The two routes that serve a note's images, by their file name alone or with their note. */
    private const array IMAGE_ROUTES = ['suite_notes_markdown_images_serve', 'suite_notes_markdown_images_read'];

    public function __construct(
        private NoteSpaceScope $scope,
        private NoteSpaceHosts $hosts,
        private Security $security,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');

        if (!str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof CoreUserInterface) {
            // Nobody signed in: the firewall's access control answers.
            return;
        }

        $parameter = $request->query->get(NoteSpaceScope::HOST_PARAMETER);
        if (is_string($parameter) && '' !== $parameter) {
            $hosted = $this->hosts->enter($parameter, $user);

            if (!$hosted instanceof HostedNoteSpace) {
                throw new NotFoundHttpException();
            }

            $this->scope->confineTo($hosted);

            return;
        }

        if (in_array($route, self::IMAGE_ROUTES, true) && !$this->security->isGranted(HostedNoteSpaceVoter::PRIVILEGE)) {
            $this->scope->restrictToHosts();

            return;
        }

        $this->scope->restrictToModule();
    }
}
