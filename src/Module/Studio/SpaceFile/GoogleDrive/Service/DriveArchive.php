<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\ResponseInterface;
use ZipArchive;

use function fclose;
use function fopen;
use function fwrite;
use function implode;
use function is_int;
use function preg_replace;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Tout le dossier partagé, en un seul fichier.
 *
 * **Le geste qui manquait.** Un client à qui on partage trente visuels les
 * téléchargeait un par un, trente clics et trente allers-retours. Le lot
 * répond à la seule question qu'il se pose vraiment : « je récupère tout ».
 *
 * **Écrit sur disque, pas en mémoire.** `ZipArchive` ne sait travailler que
 * sur un fichier, et un dossier de vidéos n'a pas à tenir en RAM pour être
 * emporté. Chaque fichier descend de chez Google par morceaux dans un fichier
 * temporaire, entre dans l'archive, et s'efface.
 *
 * **Sans recompresser.** Ce sont des photos, des PDF, des vidéos : déjà
 * compressés. Les repasser dans un déflateur coûterait des minutes de
 * processeur pour quelques pour cent, sur un serveur qui sert aussi des pages.
 *
 * Deux choses qu'un lot ne peut pas emporter, et qu'il dit plutôt que de les
 * faire disparaître : ce qui dépasse la taille tenable, refusé avant de
 * commencer ; et les documents Google, qui n'ont pas d'octets à télécharger et
 * sont nommés dans un fichier posé à la racine de l'archive.
 */
final readonly class DriveArchive
{
    /**
     * Ce qu'un lot peut peser.
     *
     * **La borne vient du temps, pas du disque.** L'archive est écrite en
     * entier avant que le premier octet ne parte : tant qu'elle se construit,
     * le serveur web attend sans rien recevoir, et il finit par abandonner.
     * Apache coupe à trois cents secondes.
     *
     * Mesuré sur le serveur le 20/09/2026, contre un vrai dossier partagé :
     * **1,41 Mo par seconde** et **0,64 seconde par fichier**, cette seconde
     * étant l'aller-retour vers Google, que le fichier pèse trois kilo-octets
     * ou trois mégaoctets. Deux cents fichiers coûtent donc déjà cent
     * vingt-sept secondes avant le premier octet transféré.
     *
     * Le pire cas admis - deux cents fichiers et cent cinquante mégaoctets -
     * demande deux cent trente-quatre secondes, ce qui laisse un cinquième de
     * marge. La borne précédente, cinq cents mégaoctets, ne pouvait pas
     * aboutir : trois cent cinquante-cinq secondes de transfert à elle seule,
     * même pour un fichier unique. Elle promettait une archive que le serveur
     * web coupait.
     *
     * Au-delà, le fichier par fichier reste ouvert et ne coûte rien à
     * personne.
     */
    public const int MAX_BYTES = 150 * 1024 * 1024;

    public function __construct(
        private DriveClient $drive,
    ) {}

    /**
     * Le poids annoncé du lot, d'après la liste déjà en main.
     *
     * Les documents Google n'ont pas de taille et ne comptent pas : ils ne
     * seront pas téléchargés non plus.
     *
     * @param list<array{id: string, name: string, path: string, mimeType: string, size: int|null, modifiedAt: string|null, thumbnail: string|null}> $files
     */
    public function weightOf(array $files): int
    {
        $total = 0;

        foreach ($files as $file) {
            if (is_int($file['size'])) {
                $total += $file['size'];
            }
        }

        return $total;
    }

    /**
     * Le lot, écrit dans un fichier temporaire.
     *
     * @param list<array{id: string, name: string, path: string, mimeType: string, size: int|null, modifiedAt: string|null, thumbnail: string|null}> $files
     *
     * @return string le chemin du zip, à supprimer par l'appelant
     */
    public function zipFor(GoogleServiceAccount $account, array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-drive-');

        $zip = new ZipArchive();

        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException("impossible d'ouvrir l'archive");
        }

        /** @var list<string> $temporary */
        $temporary = [];
        /** @var list<string> $missed */
        $missed = [];
        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($files as $file) {
            $entry = $this->uniqueEntry($file, $seen);
            $downloaded = $this->downloadToFile($account, $file['id']);

            if (null === $downloaded) {
                // Un document Google, ou un fichier retiré du partage entre
                // la liste et le téléchargement. Nommé plutôt qu'escamoté :
                // un lot incomplet sans le dire est pire qu'un lot incomplet.
                $missed[] = $entry;

                continue;
            }

            $temporary[] = $downloaded;

            $zip->addFile($downloaded, $entry);
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }

        if ([] !== $missed) {
            $zip->addFromString('FICHIERS-NON-INCLUS.txt', $this->explain($missed));
        }

        // Un dossier vide donnerait une archive sans entrée, que certains
        // outils refusent d'ouvrir.
        if (0 === $zip->numFiles) {
            $zip->addFromString('LISEZ-MOI.txt', "Le dossier partagé ne contient aucun fichier téléchargeable.\n");
        }

        $zip->close();

        foreach ($temporary as $file) {
            unlink($file);
        }

        return $path;
    }

    /**
     * Le chemin d'un fichier dans l'archive, jamais deux fois le même.
     *
     * Drive accepte deux fichiers du même nom dans un dossier ; un zip aussi,
     * mais l'extraction en écrase alors un. Le second prend un suffixe.
     *
     * @param array{name: string, path: string, ...} $file
     * @param array<string, int>                     $seen
     */
    private function uniqueEntry(array $file, array &$seen): string
    {
        $folder = '' === $file['path'] ? '' : $this->sanitise($file['path']).'/';
        $candidate = $folder.$this->sanitise($file['name']);
        $count = $seen[$candidate] ?? 0;
        $seen[$candidate] = $count + 1;

        if (0 === $count) {
            return $candidate;
        }

        return sprintf('%s (%d)', $candidate, $count + 1);
    }

    /**
     * Ce qu'un système de fichiers refuse, et ce qui ferait sortir une entrée
     * de l'archive du dossier où on l'extrait.
     *
     * Les séparateurs de `path` sont gardés : ce sont les dossiers.
     */
    private function sanitise(string $name): string
    {
        $clean = (string) preg_replace('#[\\\\:*?"<>|\x00-\x1F]+#', '-', $name);

        return (string) preg_replace('#\.\.+#', '.', $clean);
    }

    /**
     * Le fichier, descendu par morceaux dans un temporaire.
     *
     * Null quand Google ne le sert pas : un document natif n'a pas d'octets,
     * et un fichier retiré du partage entre-temps n'en a plus.
     */
    private function downloadToFile(GoogleServiceAccount $account, string $fileId): ?string
    {
        $upstream = $this->drive->download($account, $fileId);

        if (!$upstream instanceof ResponseInterface) {
            return null;
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-drive-file-');
        $handle = fopen($path, 'wb');

        if (false === $handle) {
            unlink($path);

            return null;
        }

        try {
            foreach ($this->drive->stream($upstream) as $chunk) {
                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /** @param list<string> $missed */
    private function explain(array $missed): string
    {
        return "Ces fichiers n'ont pas pu être inclus dans l'archive.\n\n"
            ."Les documents Google (Docs, Sheets, Slides) n'ont pas de fichier à\n"
            ."télécharger : ils s'ouvrent dans Drive. Les autres ont pu être retirés\n"
            ."du dossier partagé entre-temps.\n\n"
            .implode("\n", $missed)."\n";
    }
}
