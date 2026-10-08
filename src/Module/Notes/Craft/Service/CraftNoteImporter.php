<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function file_exists;
use function file_put_contents;
use function parse_url;
use function pathinfo;
use function preg_replace_callback;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A Craft document, dropped into a notes space.
 *
 * **A copy, not a live link.** The document stays in Craft, the note becomes
 * a note like the others: you edit it, file it, delete it, and nothing comes
 * back to change it on its own. A synchronized mirror would have meant
 * settling conflicts for no gain.
 *
 * **The id of the source document is kept**: it makes it possible to bring
 * the note back to the current version of the document ({@see self::refresh()}),
 * and to find the source without searching by title.
 *
 * **Images are copied.** This is the part that cannot be skipped: an image
 * served from a private Craft space only displays for those who have an
 * account there. They therefore go through the same storage as the ones
 * pasted into a note, in the notes space bucket. An image that cannot be
 * fetched keeps its original address: the note arrives whole, with a visible
 * hole, rather than silently cut down.
 */
final readonly class CraftNoteImporter
{
    private const int TIMEOUT_SECONDS = 20;

    /** An image alone or within a sentence, at an https address. */
    private const string IMAGE_PATTERN = '/!\[([^\]]*)\]\((https:\/\/[^)\s]+)((?:\s+"[^"]*")?)\)/u';

    public function __construct(
        private CraftClient $craftClient,
        private CraftMarkdown $markdown,
        private MarkdownNoteManagerInterface $notes,
        private MarkdownNoteInputFactoryInterface $inputFactory,
        private MarkdownNoteImageService $imageService,
        private UrlGeneratorInterface $urlGenerator,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Null when Craft rendered nothing: the caller turns it into a message
     * rather than an empty note carrying a title.
     *
     * The folder, when there is one, belongs to this space: the controller
     * checked it, as it checked that you can write there.
     */
    public function import(
        NoteSpaceInterface $space,
        ?NoteFolderInterface $folder,
        CoreUserInterface $author,
        string $rootBlockId,
        string $title,
    ): ?MarkdownNoteInterface {
        $source = $this->craftClient->markdown($rootBlockId);

        if (null === $source) {
            return null;
        }

        $note = $this->notes->create($author, $this->inputFactory->fromArray([
            'title' => $title,
            'content' => $this->withLocalImages($this->markdown->clean($source, $title), $space),
            'folderId' => $folder?->getId(),
            'spaceId' => $space->getId(),
        ]));

        $this->notes->markImportedFromCraft($note, $rootBlockId);

        return $note;
    }

    /**
     * The note, brought back to the current version of the document.
     *
     * **It replaces, and does not merge.** A Craft document and a note are two
     * texts that two people may have touched; deciding which one wins line by
     * line would mean settling conflicts. The note is a copy: refreshing it
     * makes the copy again, and the screen says so before doing it. The note's
     * history keeps what is replaced.
     *
     * **What belongs to Aurora survives**: the title, the folder, the tags,
     * the banner and the appearance are not in the Craft document and have no
     * reason to be reset because a text changed elsewhere.
     *
     * False when the note does not come from Craft, or when Craft rendered nothing.
     */
    public function refresh(MarkdownNoteInterface $note): bool
    {
        $documentId = $note->getCraftDocumentId();

        if (null === $documentId) {
            return false;
        }

        $source = $this->craftClient->markdown($documentId);

        if (null === $source) {
            return false;
        }

        $this->notes->update($note, $this->inputFactory->fromArray([
            'folderId' => $note->getFolder()?->getId(),
            'title' => $note->getTitle(),
            'content' => $this->withLocalImages($this->markdown->clean($source, (string) $note->getTitle()), $note->getSpace()),
            'tags' => $note->getTags(),
            'position' => $note->getPosition(),
            'coverUrl' => $note->getCoverUrl(),
            'coverCreditName' => $note->getCoverCreditName(),
            'coverCreditUrl' => $note->getCoverCreditUrl(),
            'coverPosition' => $note->getCoverPosition(),
            'appearance' => $note->getAppearance()->value,
        ]));

        return true;
    }

    /** Each remote image becomes an image of the notes space. */
    private function withLocalImages(string $markdown, NoteSpaceInterface $space): string
    {
        /** @var array<string, string> $filed */
        $filed = [];

        return preg_replace_callback(
            self::IMAGE_PATTERN,
            function (array $match) use ($space, &$filed): string {
                $filed[$match[2]] ??= $this->fetch($match[2], $space) ?? $match[2];

                return '!['.$match[1].']('.$filed[$match[2]].$match[3].')';
            },
            $markdown,
        ) ?? $markdown;
    }

    /**
     * Fetches an image and stores it, or returns null.
     *
     * Through the note images service, which refuses what is not an image or
     * exceeds the allowed size: an address that returns an error page
     * therefore keeps its original address instead of becoming a broken
     * file.
     */
    private function fetch(string $url, NoteSpaceInterface $space): ?string
    {
        $path = null;

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => self::TIMEOUT_SECONDS]);
            $body = $response->getContent();

            $path = (string) tempnam(sys_get_temp_dir(), 'craft-');
            file_put_contents($path, $body);

            $name = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME);

            // `test: true`: the file did not arrive through a form, and
            // without it Symfony refuses to move it.
            $filename = $this->imageService->store(new UploadedFile($path, '' !== $name ? $name : 'image', null, null, true), $space);

            return $this->urlGenerator->generate('suite_notes_markdown_images_serve', ['filename' => $filename]);
        } catch (Throwable $throwable) {
            $this->logger->warning('Craft image could not be filed.', [
                'url' => $url,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        } finally {
            if (null !== $path && file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
