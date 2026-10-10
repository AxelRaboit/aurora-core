<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Telling a colleague they were put on something.
 *
 * Being added to a space's team is what subscribes somebody to its news
 * ({@see SpaceActivityNotifier}), and being invited into a room is what puts
 * it in their list; neither used to say so. The person learnt it from the
 * first bell about a client they did not know they had.
 *
 * Nobody is told of their own gesture: a lead adding themselves, or opening
 * a room with themselves in it, knows. Each bell is in its reader's language,
 * and none can fail the change it reports.
 */
readonly class SpaceTeamNotifier
{
    public const string TYPE_ADDED = 'studio.space.team_added';
    public const string TYPE_CHANNEL = 'studio.space.channel_invited';

    public function __construct(
        private NotificationManagerInterface $notificationManager,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Security $security,
    ) {}

    public function addedToSpace(CustomerSpaceInterface $space, CoreUserInterface $user): void
    {
        $this->tell($user, self::TYPE_ADDED, 'suite.studio.space_notifications.team_added', ['%space%' => $space->getName()], $space->getName(), [
            'id' => $space->getId(),
        ]);
    }

    public function invitedToChannel(SpaceChatChannelInterface $channel, CoreUserInterface $user): void
    {
        $space = $channel->getSpace();

        $this->tell($user, self::TYPE_CHANNEL, 'suite.studio.space_notifications.channel_invited', [
            '%channel%' => $channel->getName(),
            '%space%' => $space->getName(),
        ], $space->getName(), ['id' => $space->getId(), 'view' => 'chat', 'channel' => $channel->getId()]);
    }

    /**
     * @param array<string, string>     $parameters
     * @param array<string, int|string> $query      where in the space the bell opens
     */
    private function tell(CoreUserInterface $user, string $type, string $titleKey, array $parameters, string $body, array $query): void
    {
        $author = $this->security->getUser();
        if ($author instanceof CoreUserInterface && $author->getId() === $user->getId()) {
            return;
        }

        if ($user instanceof User && !$user->isActive()) {
            return;
        }

        try {
            $this->notificationManager->notify(
                $user,
                $type,
                $this->translator->trans($titleKey, $parameters, null, $user instanceof User ? $user->getLocale()->value : null),
                $body,
                $this->urlGenerator->generate('workspace_space_content', $query),
            );
        } catch (Throwable) {
        }
    }
}
