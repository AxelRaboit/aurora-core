<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Core\Notification\Repository\NotificationRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Message\SpaceActivityDigestMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Telling the studio that their client did something.
 *
 * **This is the half the client spaces were missing.** A client could answer, ask
 * for a rewrite, send a file and, now, write a message, and nobody was told any
 * of it: you found out by opening the space and looking. Everything else was
 * built - the thread, the verdict, the uploads - and the loop still only closed
 * if somebody remembered to check.
 *
 * **It only reports the client's side, deliberately.** The studio already knows
 * what the studio did, and a colleague's message is a different problem with a
 * different answer. What is announced here is the thing that happens while
 * nobody is looking.
 *
 * **Repeated news is one piece of news.** A client writing four messages in a
 * row is one thing to go and look at, so a notification of the same kind about
 * the same space is skipped while an unread one is still sitting there. A
 * verdict is the exception and is never folded away: answering twice means
 * changing one's mind, and that is exactly what somebody needs to see.
 *
 * Nothing here can fail a write. A client's message is stored whether or not
 * their agency's notification could be, and the order says so: the caller
 * notifies after it has flushed.
 */
final readonly class SpaceActivityNotifier
{
    /**
     * How long the email waits before deciding it is needed.
     *
     * Five minutes: long enough that somebody working in the application
     * answers before their mailbox hears about it, short enough that somebody
     * who has gone home is told the same afternoon. The value is the only
     * tuning knob this has, and it trades one against the other.
     */
    private const int EMAIL_DELAY_MS = 300000;

    public function __construct(
        private NotificationManagerInterface $notifications,
        private NotificationRepository $repository,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private MessageBusInterface $bus,
    ) {}

    public function clientWroteInChat(CustomerSpaceInterface $space, string $author): void
    {
        $this->announce($space, 'studio.space.chat', 'backend.studio.space_notifications.chat', [
            '%who%' => $author,
        ]);
    }

    public function clientCommented(CustomerSpaceInterface $space, string $author, string $itemTitle): void
    {
        $this->announce($space, 'studio.space.comment', 'backend.studio.space_notifications.comment', [
            '%who%' => $author,
            '%item%' => $itemTitle,
        ]);
    }

    public function clientUploaded(CustomerSpaceInterface $space, string $author, string $itemTitle): void
    {
        $this->announce($space, 'studio.space.upload', 'backend.studio.space_notifications.upload', [
            '%who%' => $author,
            '%item%' => $itemTitle,
        ]);
    }

    /**
     * A verdict, which is never folded into an earlier one.
     *
     * Two answers on the same card are a client changing their mind, and the
     * second is the one that counts. Folding it away would leave the studio
     * looking at "a reprise was asked for" when the card has since been
     * approved.
     */
    public function clientAnswered(
        CustomerSpaceInterface $space,
        string $author,
        string $itemTitle,
        bool $approved,
    ): void {
        $this->announce(
            $space,
            'studio.space.answer',
            $approved
                ? 'backend.studio.space_notifications.approved'
                : 'backend.studio.space_notifications.changes_requested',
            ['%who%' => $author, '%item%' => $itemTitle],
            coalesce: false,
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function announce(
        CustomerSpaceInterface $space,
        string $type,
        string $titleKey,
        array $parameters,
        bool $coalesce = true,
    ): void {
        // **Un chemin, pas une adresse absolue.** Ce lien reste dans
        // l'application : le navigateur le résout contre l'hôte où se trouve la
        // personne, quel qu'il soit. Gravé en absolu, il porte l'hôte du contexte
        // de routage au moment où la notification est écrite - et celles-ci sont
        // écrites par le worker, sans requête HTTP, donc le contexte retombe sur
        // `localhost`. En local, cliquer dessus menait à un refus de connexion.
        $url = $this->urlGenerator->generate(
            'workspace_space_content',
            ['id' => $space->getId()]
        );

        $title = $this->translator->trans($titleKey, $parameters);

        foreach ($this->recipients($space) as $recipient) {
            if ($coalesce && $this->repository->hasUnread($recipient, $type, $url)) {
                continue;
            }

            try {
                $this->notifications->notify($recipient, $type, $title, $space->getName(), $url, [
                    'spaceId' => $space->getId(),
                ]);

                // And a look back in a few minutes, to decide whether this also
                // deserves an email. Queued rather than sent: somebody at their
                // desk will have read it by then, and the mail that is never
                // sent is the one that keeps the others worth reading.
                $this->bus->dispatch(
                    new SpaceActivityDigestMessage((int) $recipient->getId(), (int) $space->getId()),
                    [new DelayStamp(self::EMAIL_DELAY_MS)],
                );
            } catch (Throwable) {
                // Never the reason a client's message is lost. The caller has
                // already committed it; this is an announcement about something
                // that has happened, not part of it happening.
            }
        }
    }

    /**
     * Who is told.
     *
     * The space's members, and nobody else. A space with no member notifies
     * nobody, which is a real state - the screens allow it - and the honest
     * behaviour: the alternative is telling every administrator about every
     * client of every colleague, which is how a notification bell stops being
     * read at all. Adding yourself to a space is what subscribes you to it.
     *
     * @return list<CoreUserInterface>
     */
    private function recipients(CustomerSpaceInterface $space): array
    {
        $users = [];

        foreach ($space->getMembers() as $member) {
            $user = $member->getUser();
            $id = $user->getUserIdentifier();

            // Keyed while collecting: one person on a space twice, under two
            // roles, is one person to tell.
            $users[$id] = $user;
        }

        return array_values($users);
    }
}
