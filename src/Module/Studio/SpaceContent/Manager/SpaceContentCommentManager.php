<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceActivityNotifier;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(SpaceContentCommentManagerInterface::class)]
class SpaceContentCommentManager implements SpaceContentCommentManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly Security $security,
        protected readonly TranslatorInterface $translator,
        protected readonly SpaceActivityNotifier $notifier,
    ) {}

    public function postAsStudio(SpaceContentItemInterface $item, string $body): SpaceContentCommentInterface
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            throw new FieldException('body', $this->translator->trans('backend.studio.space_content.errors.comment_needs_account'));
        }

        $comment = $this->createComment();
        $comment
            ->setItem($item)
            ->setBody($body)
            ->writtenByStudio($user, $this->labelOf($user));

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        $this->auditPosted($comment);

        return $comment;
    }

    public function postAsClient(
        SpaceContentItemInterface $item,
        SpaceAccessLinkInterface $link,
        string $body,
    ): SpaceContentCommentInterface {
        if ($item->getSpace()->getId() !== $link->getSpace()->getId()) {
            throw new FieldException('item', $this->translator->trans('backend.studio.space_content.errors.not_in_space'));
        }

        $comment = $this->createComment();
        $comment
            ->setItem($item)
            ->setBody($body)
            ->writtenByClient($link);

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        $this->auditPosted($comment);

        $this->notifier->clientCommented(
            $item->getSpace(),
            $comment->getAuthorLabel(),
            $item->getTitle(),
        );

        return $comment;
    }

    /**
     * Removing a message.
     *
     * Only the studio can reach this, and only for their own side of the
     * thread: what a client wrote is what the studio was asked to act on, and a
     * provider who can delete a client's complaint is a provider whose record
     * of the engagement proves nothing.
     */
    public function delete(SpaceContentCommentInterface $comment): void
    {
        if ($comment->isFromClient()) {
            throw new FieldException('comment', $this->translator->trans('backend.studio.space_content.errors.comment_from_client'));
        }

        $this->auditDeleted($comment);

        $this->entityManager->remove($comment);
        $this->entityManager->flush();
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createComment(): SpaceContentCommentInterface
    {
        return new SpaceContentComment();
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

    protected function auditPosted(SpaceContentCommentInterface $comment): void
    {
        $this->auditLogger->log('studio', 'space_content_comment.posted', 'SpaceContentComment', $comment->getId(), $this->auditPayload($comment));
    }

    protected function auditDeleted(SpaceContentCommentInterface $comment): void
    {
        $this->auditLogger->log('studio', 'space_content_comment.deleted', 'SpaceContentComment', $comment->getId(), $this->auditPayload($comment));
    }

    /**
     * Structured payload logged with every audit entry.
     *
     * The body is not in it. A message is already stored once; copying it into
     * the log puts a client's words in a second place nobody thinks to purge.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(SpaceContentCommentInterface $comment): array
    {
        return [
            'itemId' => $comment->getItem()->getId(),
            'itemTitle' => $comment->getItem()->getTitle(),
            'spaceId' => $comment->getItem()->getSpace()->getId(),
            'author' => $comment->getAuthorLabel(),
            'fromClient' => $comment->isFromClient(),
        ];
    }
}
