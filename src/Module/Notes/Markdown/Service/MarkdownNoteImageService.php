<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\StoredFileName;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function in_array;
use function sprintf;

/**
 * Les images collées dans une note, rangées là où va tout le reste.
 *
 * Elles allaient dans `var/uploads/notes-markdown/` par un `Filesystem` posé
 * en direct, sans passer par la couche de stockage. C'était le seul module à
 * faire ça : la médiathèque, les photos de profil et les contrats écrivent
 * tous par `StorageManager`, et le disque actif en production est R2 depuis le
 * 12 septembre. Les images de note restaient donc sur le disque du serveur,
 * seules de leur espèce.
 *
 * Le plus parlant : `StorageAreaEnum` déclarait déjà la zone `notes-markdown`,
 * ajoutée en 0.9.188 pour qu'un garde d'accès puisse revendiquer le préfixe.
 * La zone existait, l'adaptateur existait, l'écriture n'était pas branchée.
 *
 * **La clé porte le propriétaire** : `notes-markdown/{idUtilisateur}/{uuid}.ext`.
 * C'est elle qui tient la règle d'accès, et elle la tient mieux que l'ancien
 * calcul de chemin : le contrôleur la construit avec l'identifiant de la
 * personne connectée, donc demander l'image d'un autre revient à demander une
 * clé qui n'existe pas. Il n'y a plus de `realpath` à comparer, plus de
 * remontée possible par `..`, plus de racine à faire respecter.
 *
 * Toujours pas d'entité Doctrine, pour la raison d'avant : une image de note
 * n'a ni alt, ni dimensions, ni empreinte à retenir. Le jour où il faudra des
 * quotas ou de la déduplication, ce sera une autre discussion.
 */
final readonly class MarkdownNoteImageService
{
    /** Hard cap on a single uploaded file. */
    public const int MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Domain allowlist of MIME types accepted by the markdown notes
     * editor. Subset of {@see MimeTypeEnum} - we intentionally exclude
     * SVG (XSS via embedded scripts) and PDF (not an inline image).
     *
     * @var list<MimeTypeEnum>
     */
    private const array ALLOWED_MIME_TYPES = [
        MimeTypeEnum::Png,
        MimeTypeEnum::Jpeg,
        MimeTypeEnum::Webp,
        MimeTypeEnum::Gif,
    ];

    /**
     * Regex matching the controller's serve URL inside markdown content.
     * Capture group 1 is the bare filename (uuid.ext). Used by the
     * manager's orphan-cleanup hook to diff old vs new note content.
     */
    public const string FILENAME_PATTERN = '#/backend/notes/markdown/images/([A-Za-z0-9._-]+)#';

    /**
     * Un nom de fichier tel que ce service en fabrique : un uuid, un point,
     * une extension. Tout le reste est refusé avant de devenir une clé.
     *
     * La route qui sert une image accepte `[A-Za-z0-9._-]+`, ce qui laisse
     * passer `..`. Sur une clé d'objet, deux points ne sont qu'un segment de
     * plus ; sur le disque local, ils remonteraient d'un cran. L'adaptateur
     * local canonicalise et compare à sa racine, donc il tiendrait, mais on ne
     * fait pas reposer une règle d'accès sur la vigilance de la couche d'en
     * dessous.
     *
     * **Deux formes, parce qu'il y a eu deux époques.** Les images d'avant la
     * 0.9.230 portent un uuid v4 écrit en toutes lettres, avec ses tirets ;
     * celles d'après portent les trente-deux caractères que `StoredFileName`
     * fabrique, seize octets du générateur du système et rien d'autre. Refuser
     * la première forme rendrait illisible tout ce qui a été collé avant, et
     * la commande d'adoption ne peut pas renommer sans réécrire les notes qui
     * citent ces fichiers.
     */
    private const string FILENAME_SHAPE = '/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.[a-z0-9]{1,5}$/';

    public function __construct(
        private StorageManager $storageManager,
    ) {}

    /**
     * Écrit le fichier téléversé sous la clé de la personne, renommé en uuid
     * pour que le nom choisi par le navigateur ne touche jamais le stockage.
     * Rend le nom nu (uuid.ext), qui est ce que le markdown porte.
     *
     * @throws FileException when validation fails (bad MIME, too big)
     */
    public function store(UploadedFile $file, CoreUserInterface $user): string
    {
        $size = $file->getSize();
        if (false !== $size && $size > self::MAX_FILE_SIZE) {
            throw new FileException(sprintf('Image exceeds max size of %d bytes.', self::MAX_FILE_SIZE));
        }

        $mime = $file->getMimeType() ?? '';
        $imageMime = MimeTypeEnum::tryFrom($mime);
        if (null === $imageMime || !in_array($imageMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new FileException(sprintf('Unsupported MIME type "%s".', $mime));
        }

        $filename = StoredFileName::withExtension($imageMime->extension());

        // Le fichier est déjà quelque part sur cette machine, PHP l'y a mis :
        // passer son chemin plutôt que son contenu fait une copie au lieu de
        // deux. C'est ce que fait la photo de profil, pour la même raison.
        $this->storageManager->active()->writeFromLocalFile(
            $this->keyFor($filename, $user),
            $file->getPathname(),
        );

        return $filename;
    }

    /**
     * La clé de stockage d'une image, ou null si le nom n'a pas la forme que
     * ce service produit.
     *
     * Null plutôt qu'une exception : l'appelant en fait un 404, ce qui est la
     * bonne réponse aussi bien pour un nom malformé que pour une image qui
     * n'existe pas. Distinguer les deux dirait à qui demande si le fichier
     * existe chez quelqu'un d'autre.
     */
    public function keyOrNull(string $filename, CoreUserInterface $user): ?string
    {
        if (1 !== preg_match(self::FILENAME_SHAPE, $filename)) {
            return null;
        }

        return $this->keyFor($filename, $user);
    }

    /**
     * Supprime une image, sur tous les disques.
     *
     * Sur tous, et pas seulement sur l'actif : une image écrite avant une
     * bascule de disque vit encore sur l'ancien, et ne la supprimer que sur le
     * nouveau la laisserait là pour toujours, invisible et facturée. C'est la
     * même raison qui fait boucler la photo de profil sur les disques.
     *
     * Silencieux sur un fichier absent, pour que le nettoyage reste rejouable.
     */
    public function delete(string $filename, CoreUserInterface $user): void
    {
        $key = $this->keyOrNull($filename, $user);

        if (null === $key) {
            return;
        }

        foreach (StorageDiskEnum::cases() as $disk) {
            $this->storageManager->forDisk($disk)->delete($key);
        }
    }

    /**
     * Le contenu d'une image, pour qui a besoin des octets plutôt que d'une
     * réponse HTTP - l'export zip, qui les range à côté du markdown.
     *
     * Null quand l'image n'existe pas : une note peut citer une image
     * supprimée entre-temps, et un export qui lèverait pour ça refuserait de
     * sortir un carnet entier à cause d'un fichier manquant.
     */
    public function contents(string $filename, CoreUserInterface $user): ?string
    {
        $key = $this->keyOrNull($filename, $user);

        if (null === $key) {
            return null;
        }

        foreach ($this->storageManager->all() as $adapter) {
            if ($adapter->exists($key)) {
                return $adapter->read($key);
            }
        }

        return null;
    }

    /**
     * Extract every image filename referenced by a markdown blob. Used
     * by the orphan-cleanup hook to compute set differences between
     * an old and a new content version.
     *
     * @return list<string>
     */
    public function extractFilenames(?string $content): array
    {
        if (null === $content || '' === $content) {
            return [];
        }

        if (0 === preg_match_all(self::FILENAME_PATTERN, $content, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    private function keyFor(string $filename, CoreUserInterface $user): string
    {
        return sprintf('%s/%s/%s', StorageAreaEnum::NotesMarkdown->value, (string) $user->getId(), $filename);
    }
}
