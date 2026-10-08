<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceActivityNotifier;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The files of the space itself, dropped or picked.
 *
 * The same uploader as a record's attachments, so the same folder and the
 * same draft: a space file is not stored elsewhere because it is attached to
 * nothing.
 *
 * A file dropped or picked by the studio is born hidden from the client, like
 * everything a space can show them; showing it is a separate action,
 * {@see setVisibleToClient()}, under the right to share the space.
 */
#[AsAlias(SpaceFileManagerInterface::class)]
class SpaceFileManager implements SpaceFileManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SpaceFileRepository $spaceFileRepository,
        protected readonly SpaceAttachmentUploader $uploader,
        protected readonly AuditLogger $auditLogger,
        protected readonly Security $security,
        protected readonly TranslatorInterface $translator,
        // Optional and last, so a client project extending this Manager with
        // its own constructor keeps booting: without it a file the client
        // sends is stored and audited, and nobody is told.
        protected readonly ?SpaceActivityNotifier $notifier = null,
    ) {}

    public function attachAsStudio(CustomerSpaceInterface $space, DocumentInterface $document): SpaceFileInterface
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            throw new FieldException('document', $this->translator->trans('suite.studio.space_files.errors.needs_account'));
        }

        $this->refuseDuplicate($space, $document);

        $file = $this->createFile();
        $file->setSpace($space)->setDocument($document)->addedByStudio($user, $this->labelOf($user));

        return $this->save($file);
    }

    public function uploadAsStudio(CustomerSpaceInterface $space, UploadedFile $file): SpaceFileInterface
    {
        return $this->attachAsStudio($space, $this->uploader->upload($file, $space));
    }

    /**
     * A file from the client, onto the space itself.
     *
     * Filed by the same uploader as the studio's, so in the space's own GED
     * folder and as a draft: the client reads it back through their link's
     * route, never through the public catch-all. Signed as the client's and
     * shown to them from the start (see {@see SpaceFileInterface::isShownToClient()}),
     * then announced to the team, after the flush, like the client's other
     * gestures: a notification that fails never loses the file.
     */
    public function uploadAsClient(SpaceAccessLinkInterface $link, UploadedFile $file): SpaceFileInterface
    {
        $space = $link->getSpace();

        $spaceFile = $this->createFile();
        $spaceFile->setSpace($space)->setDocument($this->uploader->upload($file, $space))->addedByClient($link);

        $this->entityManager->persist($spaceFile);
        $this->entityManager->flush();

        $this->auditSentByClient($spaceFile);

        $this->notifier?->clientSentFile($space, $spaceFile->getAuthorLabel(), $spaceFile->getDocument()->getTitle());

        return $spaceFile;
    }

    /**
     * Removes the file from the space, and leaves the document alone.
     *
     * The same rule as on a record: the row states an attachment, not
     * ownership. The document stays in the media library, where deleting it is
     * a screen that warns and a trash that catches.
     */
    public function remove(SpaceFileInterface $file): void
    {
        $this->auditRemoved($file);

        $this->entityManager->remove($file);
        $this->entityManager->flush();
    }

    /**
     * Shows the file to the client, or hides it from them.
     *
     * **A file the client sent stays visible.** Hiding it would remove from
     * their page what they just dropped there, and they would think the upload
     * was lost: refused with a sentence rather than ignored.
     */
    public function setVisibleToClient(SpaceFileInterface $file, bool $visible): void
    {
        if (!$visible && $file->isFromClient()) {
            throw new FieldException('visibleToClient', $this->translator->trans('suite.studio.space_files.errors.client_file_stays_visible'));
        }

        $file->setVisibleToClient($visible);
        $this->entityManager->flush();

        // Two branches and two literals: the audit log drift check reads the
        // actions in the code, and a computed value escapes it.
        if ($visible) {
            $this->auditLogger->log('studio', 'space_file.shown', 'SpaceFile', $file->getId(), $this->auditPayload($file));

            return;
        }

        $this->auditLogger->log('studio', 'space_file.hidden', 'SpaceFile', $file->getId(), $this->auditPayload($file));
    }

    /**
     * Refuses the same document twice on a space.
     *
     * Two rows pointing to a single file read as a mistake by whoever looks,
     * and it is one. Caught here rather than by a unique index, so the
     * response is a sentence.
     */
    protected function refuseDuplicate(CustomerSpaceInterface $space, DocumentInterface $document): void
    {
        if ($this->spaceFileRepository->has($space, $document)) {
            throw new FieldException('document', $this->translator->trans('suite.studio.space_files.errors.duplicate'));
        }
    }

    protected function save(SpaceFileInterface $file): SpaceFileInterface
    {
        $this->entityManager->persist($file);
        $this->entityManager->flush();

        $this->auditAdded($file);

        return $file;
    }

    /**
     * Instantiates the concrete entity. Override it to return a class
     * substituted on the client side - `resolve_target_entities` only touches
     * Doctrine relations, not direct `new` calls.
     */
    protected function createFile(): SpaceFileInterface
    {
        return new SpaceFile();
    }

    protected function labelOf(CoreUserInterface $user): string
    {
        return $user instanceof User ? $user->getName() : $user->getUserIdentifier();
    }

    protected function auditAdded(SpaceFileInterface $file): void
    {
        $this->auditLogger->log('studio', 'space_file.added', 'SpaceFile', $file->getId(), $this->auditPayload($file));
    }

    protected function auditSentByClient(SpaceFileInterface $file): void
    {
        $this->auditLogger->log('studio', 'space_file.sent_by_client', 'SpaceFile', $file->getId(), $this->auditPayload($file));
    }

    protected function auditRemoved(SpaceFileInterface $file): void
    {
        $this->auditLogger->log('studio', 'space_file.removed', 'SpaceFile', $file->getId(), $this->auditPayload($file));
    }

    /**
     * What each audit log entry carries.
     *
     * The action itself is spelled out in each hook rather than passed as a
     * parameter: the label drift check reads the code looking for
     * `log('module', 'action')`, and an action passed as a variable is a blind
     * spot it refuses to have.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(SpaceFileInterface $file): array
    {
        return [
            'spaceId' => $file->getSpace()->getId(),
            'spaceName' => $file->getSpace()->getName(),
            'documentId' => $file->getDocument()->getId(),
            'documentTitle' => $file->getDocument()->getTitle(),
            'author' => $file->getAuthorLabel(),
            'fromClient' => $file->isFromClient(),
            'visibleToClient' => $file->isVisibleToClient(),
        ];
    }
}
