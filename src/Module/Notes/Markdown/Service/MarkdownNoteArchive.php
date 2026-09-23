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
    /**
     * Le dossier où l'archive range les images, à sa racine.
     *
     * Un seul, et pas un par note : une image peut être citée par deux notes,
     * et la copier deux fois doublerait le poids du zip sans rien apporter.
     * Le nom commence par un tiret bas pour qu'il se distingue d'un dossier
     * que la personne aurait créé - `_images` n'est pas un nom qu'on donne à
     * un carnet.
     */
    private const string IMAGE_DIR = '_images';

    public function __construct(
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
        private MarkdownNoteImageService $images,
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

        // Les images déjà écrites dans l'archive, pour n'en ajouter aucune
        // deux fois : deux notes peuvent citer la même.
        $ajoutees = [];

        $this->addBranch($zip, $notesByFolder, $foldersByParent, 0, '', $user, $ajoutees);

        $zip->close();

        return $path;
    }

    /**
     * Une note seule, prête à être enregistrée.
     *
     * Ses images gardent l'adresse du back-office : un `.md` téléchargé seul
     * n'a pas de dossier voisin où les poser, et réécrire le lien vers un
     * fichier qui n'accompagne rien serait pire qu'une adresse qui demande de
     * se connecter. C'est l'archive du carnet qui emporte les images.
     */
    public function fileFor(MarkdownNoteInterface $note): string
    {
        $tags = $note->getTags();
        $content = (string) $note->getContent();

        if (0 === count($tags)) {
            return $content;
        }

        return sprintf("---\ntags: [%s]\n---\n\n%s", implode(', ', $tags), $content);
    }

    /** Le nom de fichier d'une note, sans le dossier ni l'extension. */
    public function nameOf(MarkdownNoteInterface $note): string
    {
        return $this->safeName((string) $note->getTitle(), sprintf('note-%d', $note->getId()));
    }

    /**
     * @param array<int, list<MarkdownNoteInterface>> $notesByFolder
     * @param array<int, list<NoteFolderInterface>>   $foldersByParent
     * @param array<string, true>                     $ajoutees        images déjà dans l'archive
     */
    private function addBranch(
        ZipArchive $zip,
        array $notesByFolder,
        array $foldersByParent,
        int $folderId,
        string $prefix,
        CoreUserInterface $user,
        array &$ajoutees,
    ): void {
        $seenFiles = [];

        foreach ($notesByFolder[$folderId] ?? [] as $note) {
            $name = $this->uniqueName($this->nameOf($note), $seenFiles);
            $zip->addFromString($prefix.$name.'.md', $this->body($note, $prefix, $user, $zip, $ajoutees));
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

            $this->addBranch($zip, $notesByFolder, $foldersByParent, (int) $folder->getId(), $prefix.$name.'/', $user, $ajoutees);
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

    /**
     * Le préambule, puis le texte, images comprises.
     *
     * @param array<string, true> $ajoutees
     */
    private function body(
        MarkdownNoteInterface $note,
        string $prefix,
        CoreUserInterface $user,
        ZipArchive $zip,
        array &$ajoutees,
    ): string {
        $tags = $note->getTags();
        $content = $this->withImages((string) $note->getContent(), $prefix, $user, $zip, $ajoutees);

        if (0 === count($tags)) {
            return $content;
        }

        return sprintf("---\ntags: [%s]\n---\n\n%s", implode(', ', $tags), $content);
    }

    /**
     * Copie dans l'archive les images que la note cite, et remplace leur
     * adresse par un chemin relatif.
     *
     * Sans ça, un carnet exporté sortait avec des images qui pointent vers le
     * back-office : ouvert dans Obsidian ou dans n'importe quel éditeur, le
     * texte arrivait entier et les images étaient des icônes cassées, ou pire,
     * demandaient de se connecter. Une archive est censée se suffire.
     *
     * Le chemin est relatif à la note, donc il remonte d'autant de crans que
     * son dossier est profond : une note à la racine écrit `_images/x.png`,
     * une note deux niveaux plus bas `../../_images/x.png`. C'est ce que tout
     * lecteur de markdown résout, Obsidian compris.
     *
     * Une image absente - supprimée du stockage depuis que la note la cite -
     * laisse son adresse d'origine. Refuser d'exporter le carnet entier pour
     * un fichier manquant serait une punition disproportionnée, et l'adresse
     * telle quelle dit au moins ce qui manquait.
     *
     * @param array<string, true> $ajoutees
     */
    private function withImages(
        string $content,
        string $prefix,
        CoreUserInterface $user,
        ZipArchive $zip,
        array &$ajoutees,
    ): string {
        $filenames = $this->images->extractFilenames($content);

        if ([] === $filenames) {
            return $content;
        }

        $profondeur = mb_substr_count($prefix, '/');
        $remontee = str_repeat('../', $profondeur);

        foreach ($filenames as $filename) {
            if (!isset($ajoutees[$filename])) {
                $octets = $this->images->contents($filename, $user);

                if (null === $octets) {
                    continue;
                }

                $zip->addFromString(self::IMAGE_DIR.'/'.$filename, $octets);
                $ajoutees[$filename] = true;
            }

            $content = str_replace(
                '/backend/notes/markdown/images/'.$filename,
                $remontee.self::IMAGE_DIR.'/'.$filename,
                $content,
            );
        }

        return $content;
    }
}
