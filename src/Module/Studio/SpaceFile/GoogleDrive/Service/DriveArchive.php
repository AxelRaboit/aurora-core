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
 * The whole shared folder, in a single file.
 *
 * **The missing action.** A client with thirty visuals shared with them
 * downloaded them one by one, thirty clicks and thirty round trips. The bundle
 * answers the only question they really ask: "I take everything".
 *
 * **Written to disk, not to memory.** `ZipArchive` can only work on a file,
 * and a folder of videos does not have to fit in RAM to be taken away. Each
 * file comes down from Google in chunks into a temporary file, enters the
 * archive, and is deleted.
 *
 * **Without recompressing.** These are photos, PDFs, videos: already
 * compressed. Running them through a deflater again would cost minutes of
 * CPU for a few percent, on a server that also serves pages.
 *
 * Two things a bundle cannot take, and which it states rather than making
 * them vanish: what exceeds the manageable size, refused before starting;
 * and Google documents, which have no bytes to download and are named in a
 * file placed at the root of the archive.
 */
final readonly class DriveArchive
{
    /**
     * What a bundle may weigh.
     *
     * **The limit comes from time, not from disk.** The archive is written in
     * full before the first byte leaves: while it is being built, the web
     * server waits without receiving anything, and it ends up giving up.
     * Apache cuts off at three hundred seconds.
     *
     * Measured on the server on 20/09/2026, against a real shared folder:
     * **1.41 MB per second** and **0.64 second per file**, that second being
     * the round trip to Google, whether the file weighs three kilobytes or
     * three megabytes. Two hundred files therefore already cost one hundred
     * and twenty-seven seconds before the first byte is transferred.
     *
     * The worst accepted case - two hundred files and one hundred and fifty
     * megabytes - takes two hundred and thirty-four seconds, which leaves a
     * fifth as margin. The previous limit, five hundred megabytes, could not
     * succeed: three hundred and fifty-five seconds of transfer on its own,
     * even for a single file. It promised an archive the web server cut off.
     *
     * Beyond that, file-by-file download stays open and costs nobody anything.
     */
    public const int MAX_BYTES = 150 * 1024 * 1024;

    public function __construct(
        private DriveClient $driveClient,
    ) {}

    /**
     * The announced weight of the bundle, from the list already in hand.
     *
     * Google documents have no size and do not count: they will not be
     * downloaded either.
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
     * The bundle, written to a temporary file.
     *
     * @param list<array{id: string, name: string, path: string, mimeType: string, size: int|null, modifiedAt: string|null, thumbnail: string|null}> $files
     *
     * @return string the zip's path, to be deleted by the caller
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
                // A Google document, or a file removed from sharing between
                // the list and the download. Named rather than hidden: a
                // bundle incomplete without saying so is worse than an
                // incomplete bundle.
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

        // An empty folder would give an archive with no entry, which some
        // tools refuse to open.
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
     * A file's path in the archive, never the same one twice.
     *
     * Drive accepts two files with the same name in a folder; so does a zip,
     * but extracting then overwrites one of them. The second gets a suffix.
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
     * What a file system refuses, and what would make an archive entry escape
     * the folder it is extracted into.
     *
     * The separators of `path` are kept: they are the folders.
     */
    private function sanitise(string $name): string
    {
        $clean = (string) preg_replace('#[\\\\:*?"<>|\x00-\x1F]+#', '-', $name);

        return (string) preg_replace('#\.\.+#', '.', $clean);
    }

    /**
     * The file, brought down in chunks into a temporary file.
     *
     * Null when Google does not serve it: a native document has no bytes, and
     * a file removed from sharing in the meantime no longer has any.
     */
    private function downloadToFile(GoogleServiceAccount $account, string $fileId): ?string
    {
        $upstream = $this->driveClient->download($account, $fileId);

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
            foreach ($this->driveClient->stream($upstream) as $chunk) {
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
