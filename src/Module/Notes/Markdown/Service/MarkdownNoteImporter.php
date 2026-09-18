<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInput;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
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
 * **Rien n'est écrasé.** Une note du même nom existe déjà ? Une seconde est
 * créée à côté. Fusionner demanderait de décider ce qui gagne, sur un écran où
 * personne n'a rien demandé de tel ; ajouter est le seul geste qui ne perd
 * rien, et la corbeille rattrape le doublon.
 *
 * Tout passe par le gestionnaire, jamais par l'entité : une note importée est
 * une note comme une autre, avec son journal et ses positions.
 */
final readonly class MarkdownNoteImporter
{
    public function __construct(private MarkdownNoteManagerInterface $notes) {}

    /**
     * Importe un fichier, `.md` ou `.zip`, sous le parent donné.
     *
     * @return int le nombre de notes créées
     */
    public function import(CoreUserInterface $user, UploadedFile $file, ?MarkdownNoteInterface $parent): int
    {
        $name = $file->getClientOriginalName();

        if (str_ends_with(mb_strtolower($name), '.zip')) {
            return $this->importZip($user, $file, $parent);
        }

        $this->createNote($user, $parent, $this->titleOf($name), (string) file_get_contents($file->getPathname()));

        return 1;
    }

    /**
     * Un zip, dossier par dossier.
     *
     * **Un chemin désigne une note, pas deux.** L'export d'une note qui a des
     * enfants écrit `Clients.md` pour elle et `Clients/` pour eux : ce sont
     * deux entrées de l'archive et une seule note. Elles sont donc rangées
     * sous la même clé, et la première rencontrée crée la note que la seconde
     * complète - sinon un aller-retour rendait deux « Clients », l'une avec le
     * texte et l'autre vide avec les enfants.
     *
     * L'ordre des entrées n'est pas garanti par le format, d'où les deux sens :
     * le fichier peut arriver avant ou après son dossier.
     */
    private function importZip(CoreUserInterface $user, UploadedFile $file, ?MarkdownNoteInterface $parent): int
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($file->getPathname())) {
            return 0;
        }

        /** @var array<string, MarkdownNoteInterface> $byPath */
        $byPath = [];
        $created = 0;

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = (string) $zip->getNameIndex($i);
            // Ce que les archiveurs ajoutent et que personne n'a écrit.
            if (str_ends_with($entry, '/')) {
                continue;
            }
            if (str_starts_with($entry, '__MACOSX/')) {
                continue;
            }
            if (!str_ends_with(mb_strtolower($entry), '.md')) {
                continue;
            }

            $segments = array_values(array_filter(explode('/', $entry), static fn (string $part): bool => '' !== $part));
            $fileName = (string) array_pop($segments);

            $under = $parent;
            $path = '';

            foreach ($segments as $segment) {
                $path .= '/'.$segment;

                // Comptée à la création seulement : un dossier traversé par
                // dix fichiers est une note, pas dix. Le nombre annoncé à la
                // fin doit être celui qu'on retrouve dans l'arborescence.
                if (!isset($byPath[$path])) {
                    $byPath[$path] = $this->createNote($user, $under, $segment, '');
                    ++$created;
                }

                $under = $byPath[$path];
            }

            $title = $this->titleOf($fileName);
            $filePath = $path.'/'.$title;
            $raw = (string) $zip->getFromIndex($i);

            // Le dossier du même nom est déjà passé : c'est la même note, on
            // lui donne son texte plutôt que d'en créer une seconde à côté.
            if (isset($byPath[$filePath])) {
                $this->fill($byPath[$filePath], $title, $raw);

                continue;
            }

            $byPath[$filePath] = $this->createNote($user, $under, $title, $raw);
            ++$created;
        }

        $zip->close();

        return $created;
    }

    /** Le texte d'une note déjà créée comme dossier. */
    private function fill(MarkdownNoteInterface $note, string $title, string $raw): void
    {
        [$tags, $content] = $this->split($raw);

        $this->notes->update($note, new MarkdownNoteInput(
            parentId: $note->getParent()?->getId(),
            title: $title,
            content: $content,
            tags: $tags,
        ));
    }

    private function createNote(
        CoreUserInterface $user,
        ?MarkdownNoteInterface $parent,
        string $title,
        string $raw,
    ): MarkdownNoteInterface {
        [$tags, $content] = $this->split($raw);

        return $this->notes->create($user, new MarkdownNoteInput(
            parentId: $parent?->getId(),
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
