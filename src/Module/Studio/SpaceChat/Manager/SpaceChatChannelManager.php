<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannel;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMember;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMemberInterface;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function mb_trim;

/**
 * Opening, naming and closing the rooms of a space's conversation.
 *
 * **The main room is created on demand and never deleted.** A space that has
 * one gets it back from the repository; a space that has none - seeded before
 * channels existed, or by a fixture - gets one written on the spot. That is
 * cheaper than a migration nobody reruns and it makes every caller able to
 * assume a room exists.
 *
 * **A private conversation is opened, not created.** Two people have one
 * between them, so the pair is looked up first and the second press of the
 * button lands in the same place as the first.
 */
#[AsAlias(SpaceChatChannelManagerInterface::class)]
class SpaceChatChannelManager implements SpaceChatChannelManagerInterface
{
    /** What the room a space is born with is called, before anybody renames it. */
    private const string MAIN_NAME_KEY = 'backend.studio.space_chat.channels.main_name';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SpaceChatChannelRepository $channelRepository,
        protected readonly AuditLogger $auditLogger,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function ensureMain(CustomerSpaceInterface $space): SpaceChatChannelInterface
    {
        $existing = $this->channelRepository->findMain($space);

        if ($existing instanceof SpaceChatChannelInterface) {
            return $existing;
        }

        $channel = $this->createChannel();
        $channel
            ->setSpace($space)
            ->setName($this->translator->trans(self::MAIN_NAME_KEY))
            ->setKind(SpaceChatChannelKindEnum::Main)
            ->setPosition(0)
            // The room the client already had. Closing it would be taking away
            // the conversation the space existed to hold.
            ->setOpenToClient(true);

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    public function create(CustomerSpaceInterface $space, string $name, bool $openToClient = false): SpaceChatChannelInterface
    {
        $channel = $this->createChannel();
        $channel
            ->setSpace($space)
            ->setName($this->cleanName($name))
            ->setKind(SpaceChatChannelKindEnum::Topic)
            ->setPosition($this->channelRepository->nextPosition($space))
            ->setOpenToClient($openToClient);

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $this->auditCreated($channel);

        return $channel;
    }

    public function rename(SpaceChatChannelInterface $channel, string $name): void
    {
        $channel->setName($this->cleanName($name));
        $this->entityManager->flush();

        $this->auditRenamed($channel);
    }

    public function setOpenToClient(SpaceChatChannelInterface $channel, bool $openToClient): void
    {
        if (SpaceChatChannelKindEnum::Main === $channel->getKind() && !$openToClient) {
            throw new FieldException('openToClient', $this->translator->trans('backend.studio.space_chat.errors.main_stays_open'));
        }

        if (SpaceChatChannelKindEnum::Direct === $channel->getKind()) {
            throw new FieldException('openToClient', $this->translator->trans('backend.studio.space_chat.errors.direct_has_no_audience'));
        }

        $channel->setOpenToClient($openToClient);
        $this->entityManager->flush();

        $this->auditUpdated($channel);
    }

    public function delete(SpaceChatChannelInterface $channel): void
    {
        if (SpaceChatChannelKindEnum::Topic !== $channel->getKind()) {
            throw new FieldException('channel', $this->translator->trans('backend.studio.space_chat.errors.channel_not_deletable'));
        }

        $this->auditDeleted($channel);

        $this->entityManager->remove($channel);
        $this->entityManager->flush();
    }

    public function invite(SpaceChatChannelInterface $channel, CoreUserInterface $user): SpaceChatChannelMemberInterface
    {
        foreach ($channel->getMembers() as $member) {
            if ($member->getUser()?->getId() === $user->getId()) {
                return $member;
            }
        }

        $member = $this->createMember();
        $member
            ->setUser($user)
            ->setLabel($this->labelOf($user));

        $channel->addMember($member);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->auditInvited($channel, $member);

        return $member;
    }

    /**
     * Retire une conversation de la liste de quelqu'un, sans la supprimer.
     *
     * Refusée sur un canal : une pièce se quitte ou se supprime, et les deux
     * gestes existent déjà. Ce rangement-là n'a de sens que pour une
     * conversation à deux, où « supprimer » voudrait dire effacer la moitié de
     * ce que l'autre a écrit.
     */
    public function hideDirect(SpaceChatChannelInterface $channel, ?CoreUserInterface $user, ?SpaceAccessLinkInterface $link): void
    {
        if (SpaceChatChannelKindEnum::Direct !== $channel->getKind()) {
            throw new FieldException('channel', $this->translator->trans('backend.studio.space_chat.errors.only_a_direct_hides'));
        }

        $now = new DateTimeImmutable();

        foreach ($channel->getMembers() as $member) {
            $isViewer = ($user instanceof CoreUserInterface && $member->getUser()?->getId() === $user->getId())
                || ($link instanceof SpaceAccessLinkInterface && $member->getLink()?->getId() === $link->getId());

            if ($isViewer) {
                $member->hide($now);
            }
        }

        $this->entityManager->flush();

        $this->auditHidden($channel);
    }

    public function removeMember(SpaceChatChannelMemberInterface $member): void
    {
        $channel = $member->getChannel();

        if (SpaceChatChannelKindEnum::Direct === $channel->getKind()) {
            throw new FieldException('member', $this->translator->trans('backend.studio.space_chat.errors.direct_keeps_both'));
        }

        $this->auditUninvited($channel, $member);

        $channel->removeMember($member);
        $this->entityManager->remove($member);
        $this->entityManager->flush();
    }

    public function openDirect(
        CustomerSpaceInterface $space,
        ?CoreUserInterface $fromUser,
        ?SpaceAccessLinkInterface $fromLink,
        ?CoreUserInterface $withUser,
        ?SpaceAccessLinkInterface $withLink,
    ): SpaceChatChannelInterface {
        $from = ['user' => $fromUser, 'link' => $fromLink];
        $with = ['user' => $withUser, 'link' => $withLink];

        if (!$this->isSomebody($from) || !$this->isSomebody($with)) {
            throw new FieldException('participant', $this->translator->trans('backend.studio.space_chat.errors.direct_needs_two'));
        }

        // Talking to oneself is not a conversation, and the room it would open
        // could never be closed.
        if ($fromUser instanceof CoreUserInterface && $fromUser->getId() === $withUser?->getId()) {
            throw new FieldException('participant', $this->translator->trans('backend.studio.space_chat.errors.direct_needs_two'));
        }

        if ($fromLink instanceof SpaceAccessLinkInterface && $fromLink->getId() === $withLink?->getId()) {
            throw new FieldException('participant', $this->translator->trans('backend.studio.space_chat.errors.direct_needs_two'));
        }

        $existing = $this->channelRepository->findDirectBetween($space, $from, $with);

        if ($existing instanceof SpaceChatChannelInterface) {
            // Rouvrir, c'est remettre dans sa liste ce qu'on en avait retiré :
            // la conversation revient avec tout ce qui s'y est dit, ce qui est
            // exactement ce qu'on attend en rappelant quelqu'un.
            foreach ($existing->getMembers() as $member) {
                $member->reveal();
            }

            $this->entityManager->flush();

            return $existing;
        }

        $channel = $this->createChannel();
        $channel
            ->setSpace($space)
            ->setKind(SpaceChatChannelKindEnum::Direct)
            ->setPosition($this->channelRepository->nextPosition($space))
            ->setOpenToClient(false)
            // Both names, because a private conversation is listed from either
            // side and each side wants to read the other's.
            ->setName(sprintf('%s / %s', $this->labelFor($from), $this->labelFor($with)));

        foreach ([$from, $with] as $side) {
            $member = $this->createMember();

            if ($side['user'] instanceof CoreUserInterface) {
                $member->setUser($side['user']);
            } else {
                $member->setLink($side['link']);
            }

            $member->setLabel($this->labelFor($side));
            $channel->addMember($member);
            $this->entityManager->persist($member);
        }

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $this->auditOpened($channel);

        return $channel;
    }

    protected function createChannel(): SpaceChatChannelInterface
    {
        return new SpaceChatChannel();
    }

    protected function createMember(): SpaceChatChannelMemberInterface
    {
        return new SpaceChatChannelMember();
    }

    protected function auditCreated(SpaceChatChannelInterface $channel): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.created', 'SpaceChatChannel', $channel->getId(), $this->auditPayload($channel));
    }

    protected function auditRenamed(SpaceChatChannelInterface $channel): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.renamed', 'SpaceChatChannel', $channel->getId(), $this->auditPayload($channel));
    }

    protected function auditUpdated(SpaceChatChannelInterface $channel): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.updated', 'SpaceChatChannel', $channel->getId(), $this->auditPayload($channel));
    }

    protected function auditDeleted(SpaceChatChannelInterface $channel): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.deleted', 'SpaceChatChannel', $channel->getId(), $this->auditPayload($channel));
    }

    protected function auditOpened(SpaceChatChannelInterface $channel): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.opened', 'SpaceChatChannel', $channel->getId(), $this->auditPayload($channel));
    }

    protected function auditHidden(SpaceChatChannelInterface $channel): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.hidden', 'SpaceChatChannel', $channel->getId(), $this->auditPayload($channel));
    }

    protected function auditInvited(SpaceChatChannelInterface $channel, SpaceChatChannelMemberInterface $member): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.invited', 'SpaceChatChannel', $channel->getId(), [
            ...$this->auditPayload($channel),
            'member' => $member->getLabel(),
        ]);
    }

    protected function auditUninvited(SpaceChatChannelInterface $channel, SpaceChatChannelMemberInterface $member): void
    {
        $this->auditLogger->log('studio', 'space_chat_channel.uninvited', 'SpaceChatChannel', $channel->getId(), [
            ...$this->auditPayload($channel),
            'member' => $member->getLabel(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function auditPayload(SpaceChatChannelInterface $channel): array
    {
        return [
            'space' => $channel->getSpace()->getId(),
            'name' => $channel->getName(),
            'kind' => $channel->getKind()->value,
            'openToClient' => $channel->isOpenToClient(),
        ];
    }

    /** @param array{user?: CoreUserInterface|null, link?: SpaceAccessLinkInterface|null} $side */
    private function isSomebody(array $side): bool
    {
        return ($side['user'] ?? null) instanceof CoreUserInterface
            || ($side['link'] ?? null) instanceof SpaceAccessLinkInterface;
    }

    /** @param array{user?: CoreUserInterface|null, link?: SpaceAccessLinkInterface|null} $side */
    private function labelFor(array $side): string
    {
        $user = $side['user'] ?? null;

        if ($user instanceof CoreUserInterface) {
            return $this->labelOf($user);
        }

        $link = $side['link'] ?? null;

        // The address the link was sent to, which is the only name a client
        // has: there is no account behind a link.
        return $link instanceof SpaceAccessLinkInterface ? $link->getRecipientEmail() : '';
    }

    private function labelOf(CoreUserInterface $user): string
    {
        $name = $user instanceof User ? mb_trim($user->getName()) : '';

        return '' !== $name ? $name : $user->getUserIdentifier();
    }

    private function cleanName(string $name): string
    {
        $clean = mb_trim($name);

        if ('' === $clean) {
            throw new FieldException('name', $this->translator->trans('backend.studio.space_chat.errors.channel_name_required'));
        }

        return mb_substr($clean, 0, 120);
    }
}
