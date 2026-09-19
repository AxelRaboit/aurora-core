<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function fclose;
use function fopen;
use function fwrite;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Un fichier du Drive, rangé dans la médiathèque de l'espace.
 *
 * **Une copie, et c'est voulu.** Le reste de l'intégration ne recopie rien :
 * un dossier partagé est une étagère vivante, et ce qu'on y retire disparaît
 * de l'espace. Une pièce jointe sur une carte est l'inverse, une décision
 * prise à un moment : le brief qu'on épingle sur une fiche doit rester celui
 * dont on a parlé, pas un lien qui se vide le jour où le client fait le
 * ménage dans son Drive.
 *
 * **Et c'est ce qui la rend attachable partout sans rien inventer.** Une fois
 * dans la médiathèque, le fichier est un document comme un autre : les fiches
 * savent déjà en accrocher, les notes savent déjà en afficher, et le
 * calendrier montre les mêmes fiches. Un troisième genre de pièce jointe,
 * qui aurait pointé vers Google, aurait demandé à chacun de ces écrans de le
 * connaître.
 *
 * Le nom vient de Google, comme pour un téléchargement : c'est le seul endroit
 * où il existe, et le laisser venir d'ailleurs ferait écrire un nom de fichier
 * à partir de ce qu'un navigateur envoie.
 */
final readonly class DriveImporter
{
    public function __construct(
        private DriveClient $drive,
        private SpaceAttachmentUploader $uploader,
        private LoggerInterface $logger,
    ) {}

    /**
     * Range le fichier et rend le document, ou null.
     *
     * Null quand Google ne le sert pas : un document natif n'a pas d'octets à
     * télécharger, et un fichier retiré du partage n'en a plus. L'appelant en
     * fait un 404 plutôt qu'une erreur au milieu d'un écran.
     */
    public function import(
        GoogleServiceAccount $account,
        string $fileId,
        CustomerSpaceInterface $space,
    ): ?DocumentInterface {
        $metadata = $this->drive->metadata($account, $fileId);
        $upstream = $this->drive->download($account, $fileId);

        if (null === $metadata || !$upstream instanceof ResponseInterface) {
            return null;
        }

        $path = $this->streamToFile($upstream);

        if (null === $path) {
            return null;
        }

        try {
            // `test: true` : le fichier n'est pas arrivé par un formulaire, et
            // sans cela Symfony refuse de le déplacer.
            return $this->uploader->upload(
                new UploadedFile($path, $metadata['name'], $metadata['mimeType'], null, true),
                $space,
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive file could not be filed.', [
                'fileId' => $fileId,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** Par morceaux : un dossier partagé contient des vidéos. */
    private function streamToFile(ResponseInterface $upstream): ?string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-drive-import-');
        $handle = fopen($path, 'wb');

        if (false === $handle) {
            unlink($path);

            return null;
        }

        try {
            foreach ($this->drive->stream($upstream) as $chunk) {
                fwrite($handle, $chunk);
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive file could not be read.', ['exception' => $throwable->getMessage()]);
            fclose($handle);
            unlink($path);

            return null;
        }

        fclose($handle);

        return $path;
    }
}
