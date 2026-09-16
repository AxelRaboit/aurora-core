<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(SpaceContentAttachmentManagerInterface::class)]
class SpaceContentAttachmentManager implements SpaceContentAttachmentManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SpaceContentAttachmentRepository $attachments,
        protected readonly SpaceAttachmentUploader $uploader,
        protected readonly AuditLogger $auditLogger,
        protected readonly Security $security,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function attachAsStudio(SpaceContentItemInterface $item, DocumentInterface $document): SpaceContentAttachmentInterface
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            throw new FieldException('document', $this->translator->trans('backend.studio.space_content.errors.attachment_needs_account'));
        }

        return $this->attachAs($item, $document, $user, $this->labelOf($user));
    }

    public function attachAs(
        SpaceContentItemInterface $item,
        DocumentInterface $document,
        CoreUserInterface $author,
        string $authorLabel,
    ): SpaceContentAttachmentInterface {
        $this->refuseDuplicate($item, $document);

        $attachment = $this->createAttachment();
        $attachment
            ->setItem($item)
            ->setDocument($document)
            ->setPosition($this->attachments->nextPosition($item))
            ->addedByStudio($author, $authorLabel);

        return $this->save($attachment);
    }

    public function uploadAsStudio(SpaceContentItemInterface $item, UploadedFile $file): SpaceContentAttachmentInterface
    {
        return $this->attachAsStudio($item, $this->uploader->upload($file, $item->getSpace()));
    }

    public function uploadAsClient(
        SpaceContentItemInterface $item,
        SpaceAccessLinkInterface $link,
        UploadedFile $file,
    ): SpaceContentAttachmentInterface {
        if ($item->getSpace()->getId() !== $link->getSpace()->getId()) {
            throw new FieldException('item', $this->translator->trans('backend.studio.space_content.errors.not_in_space'));
        }

        $attachment = $this->createAttachment();
        $attachment
            ->setItem($item)
            ->setDocument($this->uploader->upload($file, $item->getSpace()))
            ->setPosition($this->attachments->nextPosition($item))
            ->addedByClient($link);

        return $this->save($attachment);
    }

    /**
     * Takes a file off a card, and leaves the file alone.
     *
     * **Only the row goes.** The document stays in GED, where it is filed under
     * the client-spaces category and findable. Removing a file from a card is a
     * statement about that card - wrong picture, superseded version - and it
     * must not be a way to destroy an asset from a screen that shows no
     * warning about it. Deleting the document itself is GED's own job, where
     * the consequences are spelled out and a trash catches mistakes.
     *
     * Unlike a message, a file the client sent *can* be removed. A comment is
     * something they said and the record must keep it; a file is material for
     * the work, and a studio that cannot take the wrong photo off a post
     * cannot do the job. What survives either way is the trace: the audit entry
     * names who put it there.
     */
    public function detach(SpaceContentAttachmentInterface $attachment): void
    {
        $this->auditDetached($attachment);

        $this->entityManager->remove($attachment);
        $this->entityManager->flush();
    }

    /**
     * Refuses the same document twice on one card.
     *
     * Two rows pointing at one file render as two thumbnails of the same
     * picture, which reads as a mistake by whoever is looking at the card - and
     * it is one. Caught here rather than by a unique index so the answer is a
     * sentence somebody can read.
     */
    protected function refuseDuplicate(SpaceContentItemInterface $item, DocumentInterface $document): void
    {
        foreach ($this->attachments->findForItem($item) as $existing) {
            if ($existing->getDocument()->getId() === $document->getId()) {
                throw new FieldException('document', $this->translator->trans('backend.studio.space_content.errors.attachment_duplicate'));
            }
        }
    }

    protected function save(SpaceContentAttachmentInterface $attachment): SpaceContentAttachmentInterface
    {
        $this->entityManager->persist($attachment);
        $this->entityManager->flush();

        $this->auditAttached($attachment);

        return $attachment;
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createAttachment(): SpaceContentAttachmentInterface
    {
        return new SpaceContentAttachment();
    }

    protected function labelOf(CoreUserInterface $user): string
    {
        return $user instanceof User ? $user->getName() : $user->getUserIdentifier();
    }

    protected function auditAttached(SpaceContentAttachmentInterface $attachment): void
    {
        $this->auditLogger->log('studio', 'space_content_attachment.attached', 'SpaceContentAttachment', $attachment->getId(), $this->auditPayload($attachment));
    }

    protected function auditDetached(SpaceContentAttachmentInterface $attachment): void
    {
        $this->auditLogger->log('studio', 'space_content_attachment.detached', 'SpaceContentAttachment', $attachment->getId(), $this->auditPayload($attachment));
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditPayload(SpaceContentAttachmentInterface $attachment): array
    {
        return [
            'itemId' => $attachment->getItem()->getId(),
            'itemTitle' => $attachment->getItem()->getTitle(),
            'spaceId' => $attachment->getItem()->getSpace()->getId(),
            'documentId' => $attachment->getDocument()->getId(),
            'documentTitle' => $attachment->getDocument()->getTitle(),
            'author' => $attachment->getAuthorLabel(),
            'fromClient' => $attachment->isFromClient(),
        ];
    }
}
