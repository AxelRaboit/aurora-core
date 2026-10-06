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
 * Un document Craft, déposé dans un espace de notes.
 *
 * **Une copie, pas un lien vivant.** Le document reste chez Craft, la note
 * devient une note comme les autres : on la modifie, on la range, on la
 * supprime, et rien ne revient la changer toute seule. Un miroir synchronisé
 * aurait demandé d'arbitrer des conflits pour un gain nul.
 *
 * **L'identifiant du document d'origine est gardé** : il permet de remettre la
 * note sur la version actuelle du document ({@see self::refresh()}), et de
 * retrouver la source sans la chercher au titre.
 *
 * **Les images sont recopiées.** C'est la partie qu'on ne peut pas sauter :
 * une image servie depuis un espace Craft privé ne s'affiche que chez qui y a
 * un compte. Elles passent donc par le même rangement que celles qu'on colle
 * dans une note, dans le compartiment de l'espace de notes. Une image qu'on ne
 * peut pas prendre garde son adresse d'origine : la note arrive entière, avec
 * un trou visible, plutôt qu'amputée en silence.
 */
final readonly class CraftNoteImporter
{
    private const int TIMEOUT_SECONDS = 20;

    /** Une image seule ou dans une phrase, à une adresse https. */
    private const string IMAGE_PATTERN = '/!\[([^\]]*)\]\((https:\/\/[^)\s]+)((?:\s+"[^"]*")?)\)/u';

    public function __construct(
        private CraftClient $craft,
        private CraftMarkdown $markdown,
        private MarkdownNoteManagerInterface $notes,
        private MarkdownNoteInputFactoryInterface $inputFactory,
        private MarkdownNoteImageService $images,
        private UrlGeneratorInterface $urlGenerator,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Null quand Craft n'a rien rendu : l'appelant en fait un message plutôt
     * qu'une note vide portant un titre.
     *
     * Le dossier, quand il y en a un, est de cet espace : le contrôleur l'a
     * vérifié, comme il a vérifié qu'on y écrit.
     */
    public function import(
        NoteSpaceInterface $space,
        ?NoteFolderInterface $folder,
        CoreUserInterface $author,
        string $rootBlockId,
        string $title,
    ): ?MarkdownNoteInterface {
        $source = $this->craft->markdown($rootBlockId);

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
     * La note, remise sur la version actuelle du document.
     *
     * **Elle remplace, et ne fusionne pas.** Un document Craft et une note
     * sont deux textes que deux personnes peuvent avoir touchés ; décider
     * lequel gagne ligne à ligne demanderait d'arbitrer des conflits. La note
     * est une copie : la rafraîchir refait la copie, et l'écran le dit avant
     * de le faire. L'historique de la note garde ce qui est remplacé.
     *
     * **Ce qui appartient à Aurora survit** : le titre, le dossier, les
     * étiquettes, le bandeau et l'apparence ne sont pas dans le document Craft
     * et n'ont aucune raison d'être remis à zéro parce qu'un texte a changé
     * ailleurs.
     *
     * Faux quand la note ne vient pas de Craft, ou quand Craft n'a rien rendu.
     */
    public function refresh(MarkdownNoteInterface $note): bool
    {
        $documentId = $note->getCraftDocumentId();

        if (null === $documentId) {
            return false;
        }

        $source = $this->craft->markdown($documentId);

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

    /** Chaque image distante devient une image de l'espace de notes. */
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
     * Prend une image et la range, ou rend null.
     *
     * Par le service des images de note, qui refuse ce qui n'est pas une
     * image ou dépasse la taille permise : une adresse qui rend une page
     * d'erreur garde donc son adresse d'origine au lieu de devenir un
     * fichier cassé.
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

            // `test: true` : le fichier n'est pas arrivé par un formulaire,
            // et sans cela Symfony refuse de le déplacer.
            $filename = $this->images->store(new UploadedFile($path, '' !== $name ? $name : 'image', null, null, true), $space);

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
