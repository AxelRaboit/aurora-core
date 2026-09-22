<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Dto\NoteFolderInput;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Manager\NoteFolderManagerInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInput;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
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
    public function __construct(
        private MarkdownNoteManagerInterface $notes,
        private NoteFolderManagerInterface $folders,
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

            $this->createNote($user, $under, $this->titleOf($fileName), (string) $zip->getFromIndex($i));
            ++$created;
        }

        $zip->close();

        return $created;
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
