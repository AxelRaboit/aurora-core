<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
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
    ) {}

    public function attachAsStudio(CustomerSpaceInterface $space, DocumentInterface $document): SpaceFileInterface
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            throw new FieldException('document', $this->translator->trans('backend.studio.space_files.errors.needs_account'));
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
     * Refuse deux fois le même document sur un espace.
     *
     * Deux lignes vers un seul fichier se lisent comme une erreur de celui qui
     * regarde, et c'en est une. Attrapé ici plutôt que par un index unique,
     * pour que la réponse soit une phrase.
     */
    protected function refuseDuplicate(CustomerSpaceInterface $space, DocumentInterface $document): void
    {
        if ($this->files->has($space, $document)) {
            throw new FieldException('document', $this->translator->trans('backend.studio.space_files.errors.duplicate'));
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
        ];
    }
}
