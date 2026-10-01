<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Studio\Customer\Serializer\CustomerInformationSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentCommentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentAttachmentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentColumnSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentCommentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentItemSerializerInterface;
use Aurora\Module\Studio\SpaceResource\Repository\SpaceResourceRepository;
use Aurora\Module\Studio\SpaceResource\Serializer\SpaceResourceSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const DATE_ATOM;

/**
 * What a client is shown, which is less than what the studio sees.
 *
 * Built here rather than by reusing the board's builder, and the difference is
 * the point: that one hands out the addresses every write posts to, and a page
 * with no writes has no business carrying them. A read-only screen that
 * receives the URLs of six endpoints is one template mistake away from calling
 * one.
 *
 * The steps travel because a card says which one it is on, and the client
 * reading "à valider" beside a post is most of why they opened the page.
 */
final readonly class PublicSpaceViewBuilder
{
    public function __construct(
        private SpaceContentItemRepository $items,
        private SpaceContentColumnRepository $columns,
        private SpaceContentItemSerializerInterface $itemSerializer,
        private SpaceContentColumnSerializerInterface $columnSerializer,
        private SpaceContentCommentRepository $commentRepository,
        private SpaceContentCommentSerializerInterface $commentSerializer,
        private SpaceContentAttachmentRepository $attachmentRepository,
        private SpaceContentAttachmentSerializerInterface $attachmentSerializer,
        private CustomerInformationSerializerInterface $informationSerializer,
        private SpaceResourceRepository $resources,
        private SpaceResourceSerializerInterface $resourceSerializer,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
        private PostRepository $posts,
        private EditorialContext $editorialContext,
        private LocaleContextInterface $localeContext,
    ) {}

    /** @return list<array{id: int, title: ?string, updatedAt: string, url: string}> */
    private function documents(SpaceAccessLinkInterface $link, string $token): array
    {
        if (!$this->editorialContext->isPostsEnabled()) {
            return [];
        }

        $default = $this->localeContext->getDefaultLocale();
        $documents = [];

        foreach ($this->posts->findForCustomerSpace((int) $link->getSpace()->getId(), publishedOnly: true) as $post) {
            $translation = $post->getTranslation($default) ?? ($post->getTranslations()->first() ?: null);

            $documents[] = [
                'id' => (int) $post->getId(),
                'title' => $translation?->getTitle(),
                'description' => $translation?->getDescription(),
                'updatedAt' => $post->getUpdatedAt()->format(DATE_ATOM),
                'url' => $this->urlGenerator->generate('public_space_document', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'postId' => $post->getId(),
                ]),
            ];
        }

        return $documents;
    }

    /**
     * @param string $token the secret half, which only the request that carried
     *                      it can supply - it is not stored and cannot be read
     *                      back off the link
     *
     * @return array<string, mixed>
     */
    public function view(SpaceAccessLinkInterface $link, string $token): array
    {
        $space = $link->getSpace();
        $cards = $this->visibleCards($space);

        return [
            'space' => [
                'name' => $space->getName(),
                'description' => $space->getDescription(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
                'timezone' => $space->getTimezone(),
            ],
            // Les étapes que le client voit, et elles seules. Le tableau du
            // studio garde les siennes ; « Relecture juridique » n'a pas à
            // être une nouvelle pour lui.
            // Sans `visibleToClient` : toutes celles qui arrivent ici le sont,
            // le champ ne pourrait dire que « oui ». Un drapeau qui n'a qu'une
            // valeur n'informe personne et fait croire qu'il en a deux.
            'columns' => array_map(
                function (SpaceContentColumnInterface $column): array {
                    $shape = $this->columnSerializer->serialize($column);
                    unset($shape['visibleToClient']);

                    return $shape;
                },
                $this->visibleColumns($space),
            ),
            'items' => $this->serializeCards($cards),
            'comments' => $this->commentsOn($space, $cards),
            'attachments' => $this->attachmentsOn($link, $token, $cards),
            // La fiche du client, quand elle dit quelque chose.
            //
            // **`null` plutôt qu'une fiche vide**, parce que c'est ce que
            // l'onglet lit pour savoir s'il a lieu d'exister : une société
            // connaît son propre nom, et un onglet qui ne lui apprendrait que
            // celui-là est un onglet qu'on ouvre une fois.
            'information' => $this->informationSerializer->hasContent($space->getCustomer())
                ? $this->informationSerializer->serialize($space->getCustomer())
                : null,
            // Les ressources ouvertes au client, et elles seules. Le filtre est
            // dans la requête : une ressource fermée ne sort pas du serveur,
            // parce que la cacher dans la page en aurait fait une préférence
            // d'affichage et non une décision.
            'resources' => array_map(
                $this->resourceSerializer->serializeForGuest(...),
                $this->resources->findForSpace($space, visibleOnly: true),
            ),
            // Les documents écrits pour ce client et publiés : un brouillon reste
            // à l'équipe tant qu'elle ne l'a pas publié. Chacun s'ouvre par le
            // lien de l'espace lui-même, sans mot de passe de plus.
            'documents' => $this->documents($link, $token),
            'expiresAt' => $link->getExpiresAt(),
            'canApprove' => $link->canApprove(),
            'canComment' => $link->canComment(),
            'canUpload' => $link->canUpload(),
            // La page le dit en haut : ce qu'on regarde n'est pas ce que le
            // client a reçu, et rien de ce qu'on y clique ne part.
            'preview' => $link->isPreview(),
            // The one address this page may post to, and only when it may.
            // A reader who cannot answer is handed no endpoint at all rather
            // than a button that would be refused.
            'answerPath' => $link->canApprove()
                ? $this->pathTemplates->generate('public_space_answer', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'itemId' => '__id__',
                ])
                : null,
            // La validation en lot, jamais la demande de modification : dix
            // approbations disent une seule chose dix fois, dix demandes de
            // reprise sans un mot n'apprennent rien au studio.
            'approveManyPath' => $link->canApprove()
                ? $this->urlGenerator->generate('public_space_approve_many', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ])
                : null,
            'commentPath' => $link->canComment()
                ? $this->pathTemplates->generate('public_space_comment', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'itemId' => '__id__',
                ])
                : null,
            'uploadPath' => $link->canUpload()
                ? $this->pathTemplates->generate('public_space_attachment', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'itemId' => '__id__',
                ])
                : null,
            // Le dossier Drive, s'il y en a un. Les adresses sont posées même
            // quand le dossier est vide : l'écran décide de se montrer sur ce
            // que la liste rend, et non sur ce que le serveur suppose.
            'drivePath' => !$link->canSeeDrive() || null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->urlGenerator->generate('public_space_drive', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ]),
            'driveFilePath' => !$link->canSeeDrive() || null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->pathTemplates->generate('public_space_drive_file', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'fileId' => '__id__',
                ]),
            'driveArchivePath' => !$link->canSeeDrive() || null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->urlGenerator->generate('public_space_drive_archive', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ]),
        ];
    }

    /**
     * The threads of the space, keyed by the card they hang off.
     *
     * The same shape the studio's screens read, because it is the same
     * conversation: a message that rendered differently depending on who asked
     * is how two people end up arguing about what was said.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function comments(SpaceAccessLinkInterface $link): array
    {
        return $this->commentsOn($link->getSpace(), $this->visibleCards($link->getSpace()));
    }

    /**
     * @param array<int, SpaceContentItemInterface> $cards
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function commentsOn(CustomerSpaceInterface $space, array $cards): array
    {
        $byItem = [];

        foreach ($this->commentRepository->findForSpaceByItem($space) as $itemId => $comments) {
            if (!isset($cards[(int) $itemId])) {
                continue;
            }

            $byItem[$itemId] = array_map($this->commentSerializer->serialize(...), $comments);
        }

        return $byItem;
    }

    /**
     * The files of the space, keyed by the card they sit on.
     *
     * The same shape the studio reads, and shown to a reader who may not
     * upload: seeing the visual is the point of being asked to approve, and it
     * has nothing to do with being allowed to add one.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function attachments(SpaceAccessLinkInterface $link, string $token): array
    {
        return $this->attachmentsOn($link, $token, $this->visibleCards($link->getSpace()));
    }

    /**
     * @param array<int, SpaceContentItemInterface> $cards
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function attachmentsOn(SpaceAccessLinkInterface $link, string $token, array $cards): array
    {
        $byItem = [];

        foreach ($this->attachmentRepository->findForSpaceByItem($link->getSpace()) as $itemId => $attachments) {
            if (!isset($cards[(int) $itemId])) {
                continue;
            }

            $byItem[$itemId] = array_map(
                // Addresses that go through the link rather than through GED's
                // public catch-all, so that revoking an access revokes the
                // pictures with it.
                fn ($attachment): array => $this->attachmentSerializer->serializeForGuest($attachment, $link, $token),
                $attachments,
            );
        }

        return $byItem;
    }

    /**
     * What a guest write answers with: the cards and the threads.
     *
     * Both, because a verdict carrying a message changes one of each, and a
     * page that patched its own copy would be the first place the two could
     * disagree.
     *
     * @return array<string, mixed>
     */
    public function threadPayload(SpaceAccessLinkInterface $link, string $token): array
    {
        $cards = $this->visibleCards($link->getSpace());

        return [
            'success' => true,
            'items' => $this->serializeCards($cards),
            'comments' => $this->commentsOn($link->getSpace(), $cards),
            'attachments' => $this->attachmentsOn($link, $token, $cards),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function items(SpaceAccessLinkInterface $link): array
    {
        return $this->serializeCards($this->visibleCards($link->getSpace()));
    }

    /**
     * @param array<int, SpaceContentItemInterface> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function serializeCards(array $cards): array
    {
        return array_values(array_map($this->itemSerializer->serialize(...), $cards));
    }

    /**
     * Les fiches qu'un client a le droit de voir, indexées par identifiant.
     *
     * **Le même tamis pour les trois listes, et c'est tout le sujet.** Les
     * fiches le traversaient, les fils et les pièces jointes non : le fil
     * d'une étape marquée interne et ses fichiers partaient dans la page du
     * client, avec leurs adresses de téléchargement. L'écran n'en montrait
     * rien parce qu'il ne connaissait pas la fiche, ce qui est la pire forme
     * de fuite : invisible à l'usage, entière dans la source.
     *
     * **Les deux filtres, et pas seulement celui des colonnes.** Retirer une
     * étape sans retirer ses fiches laisserait les cartes d'une colonne
     * invisible dans le calendrier du client, qui les lit par leur date et non
     * par leur étape.
     *
     * Lu une fois par page et passé aux trois listes. Chacune le relisait
     * pour son compte, et la page entière relisait le tableau quatre fois, à
     * chaque chargement et après chaque réponse du client.
     *
     * @return array<int, SpaceContentItemInterface> dans l'ordre du tableau
     */
    private function visibleCards(CustomerSpaceInterface $space): array
    {
        $cards = [];

        foreach ($this->items->findForSpace($space) as $item) {
            if ($item->isShownToClient()) {
                $cards[(int) $item->getId()] = $item;
            }
        }

        return $cards;
    }

    /**
     * Les colonnes ouvertes au client.
     *
     * Filtrées ici plutôt que par une requête dédiée : le tableau d'un espace
     * en compte une poignée, et le dépôt sert déjà les mêmes lignes au studio.
     *
     * @return list<SpaceContentColumnInterface>
     */
    private function visibleColumns(CustomerSpaceInterface $space): array
    {
        return array_values(array_filter(
            $this->columns->findForSpace($space),
            static fn (SpaceContentColumnInterface $column): bool => $column->isVisibleToClient(),
        ));
    }
}
