<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Craft\Service;

use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Aurora\Module\Studio\SpaceNote\Dto\SpaceNoteInput;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;
use Aurora\Module\Studio\SpaceNote\Manager\SpaceNoteManagerInterface;
use Aurora\Module\Studio\SpaceNote\Service\MarkdownToBlocks;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_slice;
use function is_string;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function pathinfo;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Un document Craft, déposé dans l'espace d'un client.
 *
 * **Une copie, pas un lien vivant.** Le document reste chez Craft, la note
 * devient une note comme les autres : on la modifie, on l'épingle, on la
 * supprime, et rien ne revient la changer sous les yeux du client. C'était le
 * point de départ de tout ce chantier - un miroir synchronisé aurait demandé
 * d'arbitrer des conflits pour un gain nul.
 *
 * **L'identifiant du document d'origine est gardé, et n'est montré qu'au
 * studio.** Il sert à retrouver la source, et plus tard à proposer un
 * réimport ; le client, lui, n'a pas de compte Craft et une adresse
 * `craftdocs://` dans sa note serait un lien qui ne s'ouvre que chez
 * quelqu'un d'autre.
 *
 * **Les images sont recopiées.** C'est la partie qu'on ne peut pas sauter :
 * une image servie depuis un espace Craft privé ne s'affiche pas chez le
 * client. Elles passent donc par le même dépôt que celles qu'on glisse dans
 * une note, et se rangent dans le dossier de l'espace. Une image qu'on ne
 * peut pas prendre laisse son bloc en place avec son adresse d'origine : la
 * note arrive entière, avec un trou visible, plutôt qu'amputée en silence.
 */
final readonly class CraftNoteImporter
{
    private const int TIMEOUT_SECONDS = 20;

    public function __construct(
        private CraftClient $craft,
        private MarkdownToBlocks $markdown,
        private SpaceNoteManagerInterface $notes,
        private SpaceAttachmentUploader $uploader,
        private DocumentSerializerInterface $documents,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Null quand Craft n'a rien rendu : l'appelant en fait un message plutôt
     * qu'une note vide portant un titre.
     */
    public function import(
        CustomerSpaceInterface $space,
        string $rootBlockId,
        string $title,
    ): ?SpaceNoteInterface {
        $source = $this->craft->markdown($rootBlockId);

        if (null === $source) {
            return null;
        }

        $blocks = $this->withoutRepeatedTitle($this->markdown->convert($source), $title);
        $blocks = $this->withLocalImages($blocks, $space);

        $note = $this->notes->create($space, new SpaceNoteInput(title: mb_substr($title, 0, 180), body: $blocks));
        $this->notes->markImportedFromCraft($note, $rootBlockId);

        return $note;
    }

    /**
     * Le titre du document, écrit une fois et non deux.
     *
     * Craft enveloppe un document dans une page dont `<pageTitle>` porte son
     * titre, et la conversion en fait un titre de section - ce qui est juste
     * pour une page imbriquée, et redondant pour le document lui-même : la
     * note le porte déjà comme titre. Vu sur le premier import réel, pas sur
     * un exemple.
     *
     * Comparé après normalisation des espaces et de la casse, et seulement en
     * première position : un document qui répète son titre plus bas le fait
     * exprès.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function withoutRepeatedTitle(array $blocks, string $title): array
    {
        if ([] === $blocks || 'header' !== $blocks[0]['type']) {
            return $blocks;
        }

        $heading = mb_strtolower(mb_trim((string) ($blocks[0]['data']['text'] ?? '')));

        if ($heading !== mb_strtolower(mb_trim($title))) {
            return $blocks;
        }

        return array_slice($blocks, 1);
    }

    /**
     * Chaque image distante devient un document de l'espace.
     *
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function withLocalImages(array $blocks, CustomerSpaceInterface $space): array
    {
        foreach ($blocks as $index => $block) {
            if ('image' !== $block['type']) {
                continue;
            }

            /** @var array<string, mixed> $file */
            $file = $block['data']['file'] ?? [];
            $url = $file['url'] ?? null;
            if (!is_string($url)) {
                continue;
            }

            if (!str_starts_with($url, 'https://')) {
                continue;
            }

            $filed = $this->fetch($url, $space);

            if (null === $filed) {
                continue;
            }

            $blocks[$index]['data']['file'] = $filed;
        }

        return $blocks;
    }

    /**
     * Prend une image et la range, ou rend null.
     *
     * `documentId` voyage avec l'adresse, comme pour une image déposée à la
     * main : sans lui, rien ne peut répondre « quelles notes utilisent cette
     * image », et en supprimer une viderait une note en silence.
     *
     * @return array{url: string, documentId: int|null}|null
     */
    private function fetch(string $url, CustomerSpaceInterface $space): ?array
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
            $upload = new UploadedFile($path, '' !== $name ? $name : 'image', null, null, true);
            $document = $this->documents->serialize($this->uploader->upload($upload, $space));

            // `fileUrl`, le nom que le sérialiseur donne à l'adresse publique
            // d'un document - et non `url`, qui n'existe pas. Écrit d'après
            // une lecture trop rapide, le dépôt fonctionnait et le bloc
            // gardait quand même l'adresse de Craft : l'image partait bien
            // dans l'espace, et la note pointait toujours ailleurs. Le repli
            // prévu pour un échec masquait une erreur de clé.
            $address = $document['fileUrl'] ?? null;

            return is_string($address)
                ? ['url' => $address, 'documentId' => isset($document['id']) ? (int) $document['id'] : null]
                : null;
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
