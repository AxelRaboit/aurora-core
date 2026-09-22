<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
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
 * **Un dossier est un dossier, une note est un fichier.** L'ancienne
 * convention, celle d'Obsidian, écrivait une note qui avait des enfants en
 * deux entrées du même nom, un `.md` et un répertoire, faute de savoir dire
 * autrement qu'un objet était les deux à la fois. Les dossiers existent
 * maintenant, et l'archive dit simplement ce qu'elle contient.
 */
final readonly class MarkdownNoteArchive
{
    public function __construct(
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
    ) {}

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

        /** @var array<int, list<MarkdownNoteInterface>> $notesByFolder */
        $notesByFolder = [];
        foreach ($notes as $note) {
            $notesByFolder[$note->getFolder()?->getId() ?? 0][] = $note;
        }

        /** @var array<int, list<NoteFolderInterface>> $foldersByParent */
        $foldersByParent = [];
        foreach ($this->folders->findAllForUser($user) as $folder) {
            $foldersByParent[$folder->getParent()?->getId() ?? 0][] = $folder;
        }

        // Un carnet vide donnerait un zip sans entrée, que certains outils
        // refusent d'ouvrir. Une ligne suffit à le rendre valide et à dire
        // pourquoi il est vide.
        if ([] === $notes && [] === $foldersByParent) {
            $zip->addFromString('notes.md', "# Aucune note\n");
        }

        $this->addBranch($zip, $notesByFolder, $foldersByParent, 0, '');

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
        return $this->safeName((string) $note->getTitle(), sprintf('note-%d', $note->getId()));
    }

    /**
     * @param array<int, list<MarkdownNoteInterface>> $notesByFolder
     * @param array<int, list<NoteFolderInterface>>   $foldersByParent
     */
    private function addBranch(
        ZipArchive $zip,
        array $notesByFolder,
        array $foldersByParent,
        int $folderId,
        string $prefix,
    ): void {
        $seenFiles = [];

        foreach ($notesByFolder[$folderId] ?? [] as $note) {
            $name = $this->uniqueName($this->nameOf($note), $seenFiles);
            $zip->addFromString($prefix.$name.'.md', $this->body($note));
        }

        $seenFolders = [];

        foreach ($foldersByParent[$folderId] ?? [] as $folder) {
            $name = $this->uniqueName(
                $this->safeName((string) $folder->getName(), sprintf('dossier-%d', $folder->getId())),
                $seenFolders,
            );

            // Un dossier vide disparaîtrait de l'archive, puisque rien n'y
            // écrit de fichier. Déclaré explicitement, il survit à
            // l'aller-retour comme le reste du rangement.
            $zip->addEmptyDir($prefix.$name);

            $this->addBranch($zip, $notesByFolder, $foldersByParent, (int) $folder->getId(), $prefix.$name.'/');
        }
    }

    /**
     * Ce qu'un système de fichiers refuse, plus les caractères qui font d'un
     * nom un chemin. Le reste des accents et des espaces est gardé : c'est le
     * titre que la personne a écrit.
     */
    private function safeName(string $raw, string $fallback): string
    {
        $name = mb_trim($raw);

        if ('' === $name) {
            $name = $fallback;
        }

        return (string) preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#', '-', $name);
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
