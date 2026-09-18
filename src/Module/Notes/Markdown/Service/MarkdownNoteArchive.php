<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use RuntimeException;
use ZipArchive;

use function count;
use function implode;
use function preg_replace;
use function sprintf;

/**
 * Les notes d'une personne, telles qu'elle pourrait les emporter.
 *
 * **Du Markdown, et rien d'autre.** Le module range des notes chiffrées dans
 * une base ; ce qui en sort est une arborescence de fichiers `.md` qu'un
 * éditeur de texte ouvre, qu'Obsidian lit, et qui survivra à Aurora. C'est la
 * contrepartie de l'enfermement qu'un carnet en base représente, et elle ne
 * vaut que si elle est complète : les étiquettes voyagent donc en tête de
 * fichier, dans le préambule que les mêmes outils connaissent.
 *
 * **Une note qui a des enfants devient un fichier et un dossier**, du même
 * nom, comme le fait Obsidian : le contenu de la note reste lisible, et ses
 * enfants sont rangés à côté. L'autre convention, un dossier avec un
 * `index.md` dedans, renomme la note en passant.
 */
final readonly class MarkdownNoteArchive
{
    public function __construct(private MarkdownNoteRepository $notes) {}

    /**
     * Le carnet entier dans un zip, écrit dans un fichier temporaire.
     *
     * Écrit sur disque plutôt que gardé en mémoire : `ZipArchive` ne sait
     * travailler que sur un fichier, et un carnet de plusieurs milliers de
     * notes n'a pas à tenir deux fois en RAM pour être téléchargé.
     *
     * @return string le chemin du zip, à supprimer par l'appelant
     */
    public function zipFor(CoreUserInterface $user): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-notes-');

        $zip = new ZipArchive();

        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException("impossible d'ouvrir l'archive");
        }

        $notes = $this->notes->findAllWithContentForUser($user);
        $children = [];

        foreach ($notes as $note) {
            $children[$note->getParent()?->getId() ?? 0][] = $note;
        }

        // Un carnet vide donnerait un zip sans entrée, que certains outils
        // refusent d'ouvrir. Une ligne suffit à le rendre valide et à dire
        // pourquoi il est vide.
        if ([] === $notes) {
            $zip->addFromString('notes.md', "# Aucune note\n");
        }

        $this->addBranch($zip, $children, 0, '');

        $zip->close();

        return $path;
    }

    /** Une note seule, prête à être enregistrée. */
    public function fileFor(MarkdownNoteInterface $note): string
    {
        return $this->body($note);
    }

    /** Le nom de fichier d'une note, sans le dossier ni l'extension. */
    public function nameOf(MarkdownNoteInterface $note): string
    {
        $title = mb_trim((string) $note->getTitle());

        if ('' === $title) {
            $title = sprintf('note-%d', $note->getId());
        }

        // Ce qu'un système de fichiers refuse, plus les caractères qui font
        // d'un nom un chemin. Le reste des accents et des espaces est gardé :
        // c'est le titre que la personne a écrit.
        return (string) preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#', '-', $title);
    }

    /**
     * @param array<int, list<MarkdownNoteInterface>> $children
     */
    private function addBranch(ZipArchive $zip, array $children, int $parentId, string $prefix): void
    {
        $seen = [];

        foreach ($children[$parentId] ?? [] as $note) {
            $name = $this->uniqueName($this->nameOf($note), $seen);

            $zip->addFromString($prefix.$name.'.md', $this->body($note));

            if ([] !== ($children[$note->getId()] ?? [])) {
                $this->addBranch($zip, $children, (int) $note->getId(), $prefix.$name.'/');
            }
        }
    }

    /**
     * Deux notes peuvent porter le même titre ; deux fichiers d'un dossier,
     * non. Le second prend un suffixe plutôt que d'écraser le premier.
     *
     * @param array<string, int> $seen
     */
    private function uniqueName(string $name, array &$seen): string
    {
        if (!isset($seen[$name])) {
            $seen[$name] = 1;

            return $name;
        }

        return sprintf('%s (%d)', $name, ++$seen[$name]);
    }

    /** Le préambule, puis le texte. */
    private function body(MarkdownNoteInterface $note): string
    {
        $tags = $note->getTags();
        $content = (string) $note->getContent();

        if (0 === count($tags)) {
            return $content;
        }

        return sprintf("---\ntags: [%s]\n---\n\n%s", implode(', ', $tags), $content);
    }
}
