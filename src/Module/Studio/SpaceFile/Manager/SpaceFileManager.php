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
 * Les fichiers de l'espace lui-même, déposés ou choisis.
 *
 * Le même téléverseur que les pièces jointes d'une fiche, donc le même dossier
 * et le même brouillon : un fichier d'espace n'est pas rangé ailleurs parce
 * qu'il n'est accroché à rien.
 *
 * Un fichier déposé ou choisi par le studio naît caché au client, comme tout ce
 * qu'un espace peut lui montrer ; le montrer est un geste à part,
 * {@see setVisibleToClient()}, sous le droit de partager l'espace.
 */
#[AsAlias(SpaceFileManagerInterface::class)]
class SpaceFileManager implements SpaceFileManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SpaceFileRepository $files,
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
     * Retire le fichier de l'espace, et laisse le document tranquille.
     *
     * La même règle que sur une fiche : la ligne dit un rattachement, pas une
     * possession. Le document reste dans la médiathèque, où sa suppression est
     * un écran qui prévient et une corbeille qui rattrape.
     */
    public function remove(SpaceFileInterface $file): void
    {
        $this->auditRemoved($file);

        $this->entityManager->remove($file);
        $this->entityManager->flush();
    }

    /**
     * Montre le fichier au client, ou le lui cache.
     *
     * **Un fichier que le client a envoyé reste visible.** Le lui cacher
     * retirerait de sa page ce qu'il vient d'y déposer, et il croirait l'envoi
     * perdu : refusé avec une phrase plutôt qu'ignoré.
     */
    public function setVisibleToClient(SpaceFileInterface $file, bool $visible): void
    {
        if (!$visible && $file->isFromClient()) {
            throw new FieldException('visibleToClient', $this->translator->trans('suite.studio.space_files.errors.client_file_stays_visible'));
        }

        $file->setVisibleToClient($visible);
        $this->entityManager->flush();

        // Deux branches et deux littéraux : le contrôle de dérive du journal
        // lit les actions dans le code, et une valeur calculée lui échappe.
        if ($visible) {
            $this->auditLogger->log('studio', 'space_file.shown', 'SpaceFile', $file->getId(), $this->auditPayload($file));

            return;
        }

        $this->auditLogger->log('studio', 'space_file.hidden', 'SpaceFile', $file->getId(), $this->auditPayload($file));
    }

    /**
     * Refuse deux fois le même document sur un espace.
     *
     * Deux lignes vers un seul fichier se lisent comme une erreur de celui qui
     * regarde, et c'en est une. Attrapé ici plutôt que par un index unique,
     * pour que la réponse soit une phrase.
     */
    protected function refuseDuplicate(CustomerSpaceInterface $space, DocumentInterface $document): void
    {
        if ($this->files->has($space, $document)) {
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
     * Instancie l'entité concrète. À surcharger pour rendre une classe
     * substituée côté client - `resolve_target_entities` ne touche que les
     * relations Doctrine, pas les `new` directs.
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
     * Ce que porte chaque entrée du journal.
     *
     * L'action, elle, est écrite en toutes lettres dans chaque hook plutôt que
     * passée en paramètre : le contrôle de dérive des libellés lit le code à la
     * recherche de `log('module', 'action')`, et une action passée en variable
     * est un angle mort qu'il refuse d'avoir.
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
