<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Dto\NoteFolderInput;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Manager\NoteFolderManagerInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInput;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

use function array_filter;
use function array_map;
use function array_pop;
use function array_values;
use function explode;
use function mb_substr;
use function mb_trim;
use function pathinfo;
use function preg_match;
use function str_ends_with;
use function str_starts_with;

/**
 * Des fichiers Markdown, remis en carnet.
 *
 * **Le retour du voyage que fait {@see MarkdownNoteArchive}.** Un zip exporté
 * puis réimporté doit redonner la même arborescence, les mêmes titres et les
 * mêmes étiquettes : c'est la seule preuve qu'une exportation est autre chose
 * qu'un tas de fichiers.
 *
 * **Un répertoire est un dossier, un `.md` est une note.** Il n'y a plus de
 * cas particulier à rattraper : le format de l'archive dit lequel des deux
 * est lequel, là où l'ancienne convention faisait d'un même nom un fichier et
 * un répertoire pour une seule note.
 *
 * **Rien n'est écrasé.** Une note du même nom existe déjà ? Une seconde est
 * créée à côté. Fusionner demanderait de décider ce qui gagne, sur un écran
 * où personne n'a rien demandé de tel ; ajouter est le seul geste qui ne perd
 * rien, et la corbeille rattrape le doublon.
 *
 * Tout passe par les gestionnaires, jamais par les entités : une note
 * importée est une note comme une autre, avec son journal et ses positions.
 */
final readonly class MarkdownNoteImporter
{
    /**
     * Les extensions qu'une archive peut porter comme image.
     *
     * La même liste que celle du service d'images, moins le détail : c'est
     * lui qui tranche pour de bon, en lisant le type réel du fichier. Ici on
     * ne fait que décider quelles entrées du zip valent la peine d'être
     * ouvertes, pour ne pas tenter d'importer un PDF de deux cents pages.
     *
     * @var list<string>
     */
    private const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public function __construct(
        private MarkdownNoteManagerInterface $notes,
        private NoteFolderManagerInterface $folders,
        private MarkdownNoteImageService $images,
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    /**
     * Importe un fichier, `.md` ou `.zip`, dans le dossier donné.
     *
     * @return int le nombre de notes et de dossiers créés
     */
    public function import(CoreUserInterface $user, UploadedFile $file, ?NoteFolderInterface $folder): int
    {
        $name = $file->getClientOriginalName();

        if (str_ends_with(mb_strtolower($name), '.zip')) {
            return $this->importZip($user, $file, $folder);
        }

        $this->createNote($user, $folder, $this->titleOf($name), (string) file_get_contents($file->getPathname()));

        return 1;
    }

    /**
     * Un zip, dossier par dossier.
     *
     * Les répertoires de l'archive sont créés au fil des chemins rencontrés,
     * une fois chacun : un dossier traversé par dix fichiers est un dossier,
     * pas dix. L'ordre des entrées n'étant pas garanti par le format, un
     * répertoire déclaré vide et un répertoire déduit d'un chemin aboutissent
     * au même dossier.
     */
    private function importZip(CoreUserInterface $user, UploadedFile $file, ?NoteFolderInterface $folder): int
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($file->getPathname())) {
            return 0;
        }

        /** @var array<string, NoteFolderInterface> $byPath */
        $byPath = [];
        $created = 0;

        // Les images d'abord, parce qu'une note qui en cite une a besoin de
        // sa nouvelle adresse au moment où on l'écrit.
        $imported = $this->importImages($zip, $user);

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = (string) $zip->getNameIndex($i);

            if (str_starts_with($entry, '__MACOSX/')) {
                continue;
            }

            $isDirectory = str_ends_with($entry, '/');

            if (!$isDirectory && !str_ends_with(mb_strtolower($entry), '.md')) {
                continue;
            }

            $segments = array_values(array_filter(explode('/', $entry), static fn (string $part): bool => '' !== $part));

            if ([] === $segments) {
                continue;
            }

            $fileName = $isDirectory ? null : array_pop($segments);

            $under = $folder;
            $path = '';

            foreach ($segments as $segment) {
                $path .= '/'.$segment;

                if (!isset($byPath[$path])) {
                    $byPath[$path] = $this->folders->create($user, new NoteFolderInput(
                        name: $segment,
                        parentId: $under?->getId(),
                    ));
                    ++$created;
                }

                $under = $byPath[$path];
            }

            if (null === $fileName) {
                continue;
            }

            $this->createNote($user, $under, $this->titleOf($fileName), $this->relink((string) $zip->getFromIndex($i), $imported));
            ++$created;
        }

        $zip->close();

        return $created;
    }

    /**
     * Reprend les images que l'archive transporte, et rend la table qui dit
     * quel nom de fichier est devenu quelle adresse.
     *
     * Indexées par leur **nom de base** et non par leur chemin : nos archives
     * les rangent dans `_images/`, Obsidian dans un dossier de pièces jointes
     * que chacun nomme comme il veut, et une note y renvoie par un chemin
     * relatif qui dépend de sa profondeur. Le nom de base est ce que les deux
     * ont en commun. Deux images homonymes dans deux dossiers différents se
     * marcheraient dessus ; c'est le prix, et il est plus faible que celui de
     * ne rien importer du tout.
     *
     * Une image refusée par le service - trop grosse, ou d'un type qu'on
     * n'accepte pas, un SVG par exemple - est simplement sautée. L'import du
     * carnet continue, et la note gardera un lien mort plutôt que de ne pas
     * exister.
     *
     * @return array<string, string> nom de base dans l'archive => adresse à écrire
     */
    private function importImages(ZipArchive $zip, CoreUserInterface $user): array
    {
        $imported = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = (string) $zip->getNameIndex($i);
            if (str_starts_with($entry, '__MACOSX/')) {
                continue;
            }

            if (str_ends_with($entry, '/')) {
                continue;
            }

            $base = basename($entry);
            $extension = mb_strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }

            if (isset($imported[$base])) {
                continue;
            }

            $octets = $zip->getFromIndex($i);
            if (false === $octets) {
                continue;
            }

            if ('' === $octets) {
                continue;
            }

            // Par un fichier temporaire : le service valide le type réel en
            // lisant le fichier, ce qu'on ne peut pas lui demander sur une
            // chaîne en mémoire. Le cinquième argument met l'objet en mode
            // test, sans quoi Symfony refuse un fichier que PHP n'a pas
            // reçu lui-même d'un formulaire.
            $temporaire = (string) tempnam(sys_get_temp_dir(), 'aurora-note-image-');
            $this->filesystem->dumpFile($temporaire, $octets);

            try {
                $filename = $this->images->store(
                    new UploadedFile($temporaire, $base, null, null, true),
                    $user,
                );
                $imported[$base] = '/backend/notes/markdown/images/'.$filename;
            } catch (FileException) {
                // Sautée, pour la raison dite plus haut.
            } finally {
                $this->filesystem->remove($temporaire);
            }
        }

        return $imported;
    }

    /**
     * Remplace, dans le texte d'une note, les chemins vers les images de
     * l'archive par les adresses qu'elles ont prises ici.
     *
     * Ce que l'export a écrit dans l'autre sens : `_images/x.png` redevient
     * une adresse du back-office. Le chemin est comparé par son nom de base,
     * donc `../../_images/x.png` comme `attachments/x.png` retombent sur la
     * même entrée.
     *
     * Une adresse absolue est laissée telle quelle : elle ne désigne pas un
     * fichier de l'archive.
     *
     * @param array<string, string> $imported
     */
    private function relink(string $content, array $imported): string
    {
        if ([] === $imported) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)([^)]*)\)/',
            static function (array $m) use ($imported): string {
                $cible = $m[2];

                if (str_starts_with($cible, 'http://') || str_starts_with($cible, 'https://') || str_starts_with($cible, 'data:')) {
                    return $m[0];
                }

                $base = basename(explode('#', explode('?', $cible)[0])[0]);

                if (!isset($imported[$base])) {
                    return $m[0];
                }

                return sprintf('![%s](%s%s)', $m[1], $imported[$base], $m[3]);
            },
            $content,
        );
    }

    private function createNote(
        CoreUserInterface $user,
        ?NoteFolderInterface $folder,
        string $title,
        string $raw,
    ): void {
        [$tags, $content] = $this->split($raw);

        $this->notes->create($user, new MarkdownNoteInput(
            folderId: $folder?->getId(),
            title: $title,
            content: $content,
            tags: $tags,
        ));
    }

    /**
     * Sépare le préambule du texte.
     *
     * Seul `tags:` est lu : c'est la seule chose que l'export écrit, et lire
     * des clés qu'on ne produit pas serait promettre un dialecte qu'on ne
     * tient pas. Un préambule d'un autre outil est donc laissé dans le texte,
     * où il reste visible plutôt que perdu en silence.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function split(string $raw): array
    {
        if (!str_starts_with($raw, "---\n")) {
            return [[], $raw];
        }

        $end = mb_strpos($raw, "\n---", 4);

        if (false === $end) {
            return [[], $raw];
        }

        $front = mb_substr($raw, 4, $end - 4);
        $rest = mb_ltrim(mb_substr($raw, $end + 4), "\n");

        if (1 !== preg_match('/^tags:\s*\[(.*)\]\s*$/m', $front, $found)) {
            return [[], $raw];
        }

        $tags = array_values(array_filter(array_map(
            trim(...),
            explode(',', $found[1]),
        ), static fn (string $tag): bool => '' !== $tag));

        return [$tags, $rest];
    }

    /** Le nom du fichier, sans son extension, comme titre. */
    private function titleOf(string $fileName): string
    {
        $title = pathinfo($fileName, PATHINFO_FILENAME);

        return '' === mb_trim($title) ? 'Sans titre' : $title;
    }
}
