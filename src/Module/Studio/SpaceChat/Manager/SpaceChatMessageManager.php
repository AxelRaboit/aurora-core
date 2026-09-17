<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceActivityNotifier;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;
use Aurora\Module\Studio\SpaceChat\Serializer\SpaceChatMessageSerializerInterface;
use Aurora\Module\Studio\SpaceChat\Service\SpaceChatHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writing in a space's conversation.
 *
 * **Stored first, pushed second, and never the other way round.** The hub is
 * asked only once the row is committed, and it cannot fail the write - see
 * {@see SpaceChatHub}. That order is what makes the live layer optional
 * rather than load-bearing.
 */
#[AsAlias(SpaceChatMessageManagerInterface::class)]
class SpaceChatMessageManager implements SpaceChatMessageManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly Security $security,
        protected readonly TranslatorInterface $translator,
        protected readonly SpaceChatHub $hub,
        protected readonly SpaceChatMessageSerializerInterface $serializer,
        protected readonly SpaceActivityNotifier $notifier,
    ) {}

    public function postAsStudio(CustomerSpaceInterface $space, string $body): SpaceChatMessageInterface
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            throw new FieldException('body', $this->translator->trans('backend.studio.space_chat.errors.needs_account'));
        }

        $message = $this->createMessage();
        $message
            ->setSpace($space)
            ->setBody($body)
            ->writtenByStudio($user, $this->labelOf($user));

        return $this->save($message);
    }

    public function postAsClient(
        CustomerSpaceInterface $space,
        SpaceAccessLinkInterface $link,
        string $body,
    ): SpaceChatMessageInterface {
        if ($space->getId() !== $link->getSpace()->getId()) {
            throw new FieldException('space', $this->translator->trans('backend.studio.space_chat.errors.not_in_space'));
        }

        $message = $this->createMessage();
        $message
            ->setSpace($space)
            ->setBody($body)
            ->writtenByClient($link);

        $this->save($message);

        // After the flush, and only for the client's side: the studio knows
        // what the studio wrote.
        $this->notifier->clientWroteInChat($space, $message->getAuthorLabel());

        return $message;
    }

    /**
     * Removing a message.
     *
     * Only the studio can reach this, and only for their own side: what a
     * client wrote is what the studio was asked to act on, and a provider who
     * can delete a customer's complaint has a record of the engagement that
     * proves nothing. The same rule the card threads apply, for the same
     * reason.
     */
    public function delete(SpaceChatMessageInterface $message): void
    {
        if ($message->isFromClient()) {
            throw new FieldException('message', $this->translator->trans('backend.studio.space_chat.errors.from_client'));
        }

        $space = $message->getSpace();
        $id = $message->getId();

        $this->auditDeleted($message);

        $this->entityManager->remove($message);
        $this->entityManager->flush();

        // Pushed as well as stored: a page that kept drawing a message the
        // server no longer has would show it until somebody reloaded, which is
        // exactly the moment a deleted message is most confusing.
        $this->hub->publish($space, ['id' => $id, 'deleted' => true]);
    }

    protected function save(SpaceChatMessageInterface $message): SpaceChatMessageInterface
    {
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->auditPosted($message);

        $this->hub->publish($message->getSpace(), $this->serializer->serialize($message));

        return $message;
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createMessage(): SpaceChatMessageInterface
    {
        return new SpaceChatMessage();
    }

    /**
     * What to sign a studio message with.
     *
     * `getName()` belongs to the concrete `User`, not to `CoreUserInterface`, so
     * a client project's substituted account falls back on the identifier -
     * which is an address, and still a name somebody recognises.
     */
    protected function labelOf(CoreUserInterface $user): string
    {
        return $user instanceof User ? $user->getName() : $user->getUserIdentifier();
    }

    protected function auditPosted(SpaceChatMessageInterface $message): void
    {
        $this->auditLogger->log('studio', 'space_chat_message.posted', 'SpaceChatMessage', $message->getId(), $this->auditPayload($message));
    }

    protected function auditDeleted(SpaceChatMessageInterface $message): void
    {
        $this->auditLogger->log('studio', 'space_chat_message.deleted', 'SpaceChatMessage', $message->getId(), $this->auditPayload($message));
    }

    /**
     * Structured payload logged with every audit entry.
     *
     * The body is not in it. A message is already stored once; copying it into
     * the log puts a client's words in a second place nobody thinks to purge.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(SpaceChatMessageInterface $message): array
    {
        return [
            'spaceId' => $message->getSpace()->getId(),
            'spaceName' => $message->getSpace()->getName(),
            'author' => $message->getAuthorLabel(),
            'fromClient' => $message->isFromClient(),
        ];
    }
}
