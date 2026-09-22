<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicy;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\View\PublicSpaceViewBuilder;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatMessageManagerInterface;
use Aurora\Module\Studio\SpaceChat\Repository\SpaceChatChannelRepository;
use Aurora\Module\Studio\SpaceChat\Service\SpaceChatHub;
use Aurora\Module\Studio\SpaceChat\View\SpaceChatViewBuilder;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentAttachmentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentCommentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveArchive;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveFileServer;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettings;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Aurora\Module\Studio\SpaceFile\View\SpaceFilesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function ctype_digit;
use function date;
use function is_array;
use function is_int;
use function is_string;

/**
 * A client's own view of their space, opened by a secret address.
 *
 * **One write, and it arrives with both of its prerequisites.** A guest who may
 * answer needs a column that says so - `canApprove`, enforced here and nowhere
 * else - and a rate limit, because a token that leaks has nobody to block. Those
 * are the two things the calendar's share links are documented as deliberately
 * missing before write access is opened, and neither is optional.
 *
 * The answer never moves the card. It is an opinion recorded against a wording,
 * and the person who acts on it is the one who reads it.
 *
 * One page for every refusal: unknown selector, wrong secret, revoked, expired.
 * Telling a stranger which of those it was tells them which guesses landed.
 */
#[Route('/spaces', name: 'public_space')]
final class PublicSpaceController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly SpaceAccessLinkManagerInterface $links,
        private readonly SpaceContentItemManagerInterface $items,
        private readonly SpaceContentCommentManagerInterface $comments,
        private readonly SpaceContentItemRepository $itemRepository,
        private readonly PublicSpaceViewBuilder $viewBuilder,
        // Autowired by parameter name: `$spaceGuestWriteLimiter` resolves to
        // the `space_guest_write` limiter declared in config, the way the
        // contract controller reaches its own.
        private readonly RateLimiterFactoryInterface $spaceGuestWriteLimiter,
        private readonly SpaceContentAttachmentManagerInterface $attachments,
        private readonly SpaceFilesViewBuilder $filesViewBuilder,
        private readonly SpaceFileRepository $spaceFiles,
        // A limiter of its own rather than the one above. A verdict is a row; a
        // file is megabytes through the whole pipeline - storage, thumbnailing,
        // a poster frame for a video - and forty of those an hour from one
        // address is not the same offer as forty clicks.
        private readonly RateLimiterFactoryInterface $spaceGuestUploadLimiter,
        private readonly RateLimiterFactoryInterface $spaceGuestArchiveLimiter,
        private readonly SpaceContentAttachmentRepository $attachmentRepository,
        private readonly UploadPolicyProvider $uploadPolicies,
        private readonly StoredFileResponder $responder,
        private readonly SpaceChatMessageManagerInterface $chat,
        private readonly SpaceChatChannelRepository $chatChannels,
        private readonly SpaceChatViewBuilder $chatViewBuilder,
        private readonly SpaceChatHub $chatHub,
        private readonly DriveSettings $driveSettings,
        private readonly DriveClient $drive,
        private readonly DriveFileServer $driveRelay,
        private readonly DriveArchive $driveArchives,
    ) {}

    /**
     * The alphabets are constrained in the route, so a path carrying anything
     * else never reaches a query.
     */
    #[Route(
        '/{selector}/{token}',
        name: '_show',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $selector, string $token, Request $request): Response
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface) {
            return $this->privately($this->render('@Studio/public/unavailable.html.twig', [
                // The page the contracts already use, with the one sentence
                // that has to name what the reader was expecting.
                'messageKey' => 'studio.public.space.unavailable_message',
            ]));
        }

        $this->links->markOpened($link);

        $response = $this->privately($this->render('@Studio/public/space.html.twig', [
            ...$this->viewBuilder->view($link, $token),
            ...$this->chatViewBuilder->publicView($link, $token),
            ...$this->filesViewBuilder->publicView($link, $token),
        ]));

        // **The one place a guest is authorised at the hub.** Everything else
        // on this page is authorised by the address; a push connection cannot
        // be, because the hub has never heard of this link. So whoever just
        // proved they hold a usable one leaves with a short-lived JWT, scoped
        // to this space's topic and to subscribing only, in a cookie the
        // browser sends nowhere but the hub. Revoking the link stops this page
        // being served, and the cookie runs out on its own.
        $cookie = $this->chatHub->subscriptionCookie($request, $this->chatChannels->findForLink($link->getSpace(), $link));

        if ($cookie instanceof Cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * Records what the client answered about one piece of content.
     *
     * Rate limited on the IP, which is the outer wall: an attacker rotates
     * addresses, so the walls that hold are the link's own right and the fact
     * that a revoked or expired one resolves to nothing at all.
     *
     * A link without `canApprove` gets the same 404 a stranger gets. Saying
     * "you may read but not answer" would be true and would also tell somebody
     * holding a leaked address exactly what they have.
     */
    #[Route(
        '/{selector}/{token}/content/{itemId}/answer',
        name: '_answer',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'itemId' => '\d+'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function answer(string $selector, string $token, int $itemId, Request $request): JsonResponse
    {
        if (!$this->spaceGuestWriteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.space.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $this->assertFromThisPage($request);

        $link = $this->links->resolveUsable($selector, $token);

        // **`isPreview()` avant le droit, et sur les six écritures.** Un
        // aperçu recopie les droits du lien qu'il montre pour que l'écran soit
        // le même ; il ne doit pas pour autant pouvoir répondre. Sans cette
        // ligne, un clic distrait sur « Validé » enregistrerait une réponse au
        // nom du client, et rien dans l'espace ne dirait qu'elle vient d'un
        // aperçu.
        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview() || !$link->canApprove()) {
            throw $this->createNotFoundException();
        }

        $item = $this->itemRepository->find($itemId);

        if (!$item instanceof SpaceContentItemInterface) {
            throw $this->createNotFoundException();
        }

        $payload = $this->decodeJson($request);

        $approval = SpaceContentApprovalEnum::tryFrom(Str::trimFromArray($payload, 'approval'));

        // `Pending` is what nobody answering looks like, so it is not something
        // anybody answers: a client who changes their mind says the other
        // thing, they do not un-say this one.
        if (!$approval instanceof SpaceContentApprovalEnum || !$approval->isAnswered()) {
            return $this->jsonInvalidInput(['approval' => 'studio.public.space.errors.approval_invalid']);
        }

        try {
            $this->items->answer($item, $link, $approval);
        } catch (FieldException) {
            // The card belongs to another space. Answered like a stranger, for
            // the reason above.
            throw $this->createNotFoundException();
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess($this->viewBuilder->threadPayload($link, $token));
    }

    /**
     * Valider plusieurs cartes d'un geste.
     *
     * **La validation en lot existe, la demande de modification non.** Approuver
     * dix contenus d'un coup dit une seule chose, dix fois ; demander une
     * modification sans dire laquelle n'apprend rien au studio et l'oblige à
     * rappeler le client pour comprendre. Une demande de modification reste donc
     * attachée à une carte et à son commentaire, sur la route au singulier.
     *
     * Le même limiteur que la réponse unitaire, et une seule consommation pour
     * l'appel : c'est un geste de l'utilisateur, pas dix, et le facturer dix
     * fois fermerait la porte à celui qui range sa semaine.
     *
     * Les cartes d'un autre espace sont ignorées en silence plutôt que
     * refusées : la boucle ne s'arrête pas sur une, et un identifiant fabriqué
     * n'apprend rien de plus qu'un 404.
     */
    #[Route(
        '/{selector}/{token}/content/approve',
        name: '_approve_many',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function approveMany(string $selector, string $token, Request $request): JsonResponse
    {
        if (!$this->spaceGuestWriteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.space.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $this->assertFromThisPage($request);

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview() || !$link->canApprove()) {
            throw $this->createNotFoundException();
        }

        $payload = $this->decodeJson($request);
        $ids = is_array($payload['ids'] ?? null) ? $payload['ids'] : [];

        if ([] === $ids) {
            return $this->jsonInvalidInput(['ids' => 'studio.public.space.errors.nothing_selected']);
        }

        $approved = 0;

        foreach ($ids as $id) {
            if (!is_int($id) && (!is_string($id) || !ctype_digit($id))) {
                continue;
            }

            $item = $this->itemRepository->find((int) $id);

            if (!$item instanceof SpaceContentItemInterface) {
                continue;
            }

            try {
                $this->items->answer($item, $link, SpaceContentApprovalEnum::Approved);
                ++$approved;
            } catch (FieldException) {
                continue;
            }
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess(['approved' => $approved] + $this->viewBuilder->threadPayload($link, $token));
    }

    /**
     * A message from the client on one card's thread.
     *
     * Same limiter as the verdict, and the same 404 for a link that may not:
     * both are guest writes on the same secret address, and the wall that holds
     * is the link's own right rather than the count.
     */
    #[Route(
        '/{selector}/{token}/content/{itemId}/comments',
        name: '_comment',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'itemId' => '\d+'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function comment(string $selector, string $token, int $itemId, Request $request): JsonResponse
    {
        if (!$this->spaceGuestWriteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.space.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $this->assertFromThisPage($request);

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview() || !$link->canComment()) {
            throw $this->createNotFoundException();
        }

        $item = $this->itemRepository->find($itemId);

        if (!$item instanceof SpaceContentItemInterface) {
            throw $this->createNotFoundException();
        }

        $body = Str::trimFromArray($this->decodeJson($request), 'body');

        if ('' === $body) {
            return $this->jsonInvalidInput(['body' => 'studio.public.space.errors.comment_required']);
        }

        try {
            $this->comments->postAsClient($item, $link, $body);
        } catch (FieldException) {
            // The card belongs to another space.
            throw $this->createNotFoundException();
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess($this->viewBuilder->threadPayload($link, $token));
    }

    /**
     * A file from the client, onto one of their cards.
     *
     * **Three walls, and they are not interchangeable.** The limiter is the
     * outer one and counts by address. The link's own `canUpload` is the
     * middle one, and a link without it gets the same 404 a stranger gets -
     * saying "you may read but not send" would tell somebody holding a leaked
     * address exactly what they hold. {@see UploadPolicy::forSpaceGuests()} is the
     * inner one and is the only one that looks at the file: what a browser
     * calls it is written by whoever is uploading, so the type is sniffed from
     * the bytes.
     *
     * The refusal from the policy is a sentence, not a 404, and deliberately
     * so: by that point the caller has proved they hold a usable link, and
     * "that kind of file is not accepted" is something the client needs to
     * read in order to send the right one.
     */
    #[Route(
        '/{selector}/{token}/content/{itemId}/attachments',
        name: '_attachment',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'itemId' => '\d+'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function attach(string $selector, string $token, int $itemId, Request $request): JsonResponse
    {
        if (!$this->spaceGuestUploadLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.space.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $this->assertFromThisPage($request);

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview() || !$link->canUpload()) {
            throw $this->createNotFoundException();
        }

        $item = $this->itemRepository->find($itemId);

        if (!$item instanceof SpaceContentItemInterface) {
            throw $this->createNotFoundException();
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->jsonInvalidInput(['file' => 'studio.public.space.errors.upload_required']);
        }

        $refusal = $this->uploadPolicies->forSpaceGuests()->refusalFor($file);

        if ($refusal instanceof UploadRefusalEnum) {
            // The reason is mapped to this surface's own words: the same rule
            // speaks to a colleague in the back office and to a customer here.
            return $this->jsonInvalidInput(['file' => match ($refusal) {
                UploadRefusalEnum::TooLarge => 'studio.public.space.errors.upload_too_large',
                UploadRefusalEnum::TypeRefused => 'studio.public.space.errors.upload_type_refused',
                UploadRefusalEnum::Broken => 'studio.public.space.errors.upload_failed',
            }]);
        }

        try {
            $this->attachments->uploadAsClient($item, $link, $file);
        } catch (FieldException) {
            // The card belongs to another space.
            throw $this->createNotFoundException();
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess($this->viewBuilder->threadPayload($link, $token));
    }

    /**
     * The space's own conversation, as it stands.
     *
     * **A read, and the reason the live layer is allowed to be absent.** A page
     * with no push connection - no hub configured, or a browser that dropped
     * the one it had - asks here instead. No rate limiter, deliberately and
     * unlike every write on this controller: a page that reconnects by polling
     * would be throttled for behaving exactly as designed, and the wall that
     * holds here is the same one that holds on the page itself, which is the
     * link.
     */
    #[Route(
        '/{selector}/{token}/chat/{channelId}/messages',
        name: '_chat_messages',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'channelId' => '\d+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function chatMessages(string $selector, string $token, int $channelId): JsonResponse
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        $channel = $this->readableChannel($link, $channelId);

        return $this->jsonSuccess($this->chatViewBuilder->payload($channel));
    }

    /** Ce qui précède ce que la page du client tient déjà. */
    #[Route(
        '/{selector}/{token}/chat/{channelId}/older/{beforeId}',
        name: '_chat_older',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'channelId' => '\d+', 'beforeId' => '\d+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function chatOlder(string $selector, string $token, int $channelId, int $beforeId): JsonResponse
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        return $this->jsonSuccess(
            $this->chatViewBuilder->olderPayload($this->readableChannel($link, $channelId), $beforeId),
        );
    }

    /**
     * `chatHide` est partie avec les conversations privées.
     *
     * Elle ne savait ranger qu'un canal direct, et un lien n'en voit plus
     * aucun : elle répondait donc 404 à tout coup. C'était par ailleurs la
     * seule écriture d'invité sans limite de débit, ce qui se remarque quand
     * on la retire plutôt que quand on la garde.
     */

    /**
     * Il n'y a plus de conversation privée sans compte.
     *
     * **Une route est partie d'ici, et c'est une décision de fond.** Un
     * invité pouvait ouvrir une conversation avec n'importe quel membre de
     * l'équipe, et recevait pour cela l'annuaire nominatif de l'espace. Le
     * droit qui l'autorisait était « peut commenter » : cocher une case pour
     * permettre une remarque sous une publication ouvrait en réalité une
     * messagerie vers les salariés et donnait leurs noms.
     *
     * Ce qu'un client a à dire passe donc par un canal, que le studio ouvre
     * quand il le décide. Le studio garde ses conversations privées entre
     * collaborateurs : seul le côté invité est fermé.
     *
     * La garde qui compte n'est pas cette absence mais
     * {@see SpaceChatChannelRepository::findForLink()}, qui ne rend plus aucun
     * canal direct à un lien - y compris ceux ouverts avant ce changement.
     */

    /**
     * The room behind an id, or a 404.
     *
     * **Not found rather than forbidden, like everything else a link reaches.**
     * Answering "this room exists but is not yours" would tell whoever holds a
     * leaked address how many rooms the studio keeps and roughly what they are
     * for. A room the client does not read is a room that does not exist.
     */
    private function readableChannel(SpaceAccessLinkInterface $link, int $channelId): SpaceChatChannelInterface
    {
        foreach ($this->chatChannels->findForLink($link->getSpace(), $link) as $channel) {
            if ($channel->getId() === $channelId) {
                return $channel;
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * A message from the client in the space's conversation.
     *
     * **The right to write here is the right to comment**, and not a fourth
     * column of its own: a client who may answer their agency on a post is a
     * client who may answer their agency. A link without it gets the same 404 a
     * stranger gets, for the reason the other writes give - saying "you may
     * read but not write" tells somebody holding a leaked address what they
     * hold.
     *
     * Same limiter as the other guest writes. A message is a row, like a
     * verdict and unlike a file.
     */
    #[Route(
        '/{selector}/{token}/chat/{channelId}',
        name: '_chat_post',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'channelId' => '\d+'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function chatPost(string $selector, string $token, int $channelId, Request $request): JsonResponse
    {
        if (!$this->spaceGuestWriteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.space.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $this->assertFromThisPage($request);

        $link = $this->links->resolveUsable($selector, $token);

        // `canChat` et non `canComment` : commenter une fiche et parler dans
        // la discussion de l'espace sont deux conversations, et un seul droit
        // les commandait toutes les deux.
        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview() || !$link->canChat()) {
            throw $this->createNotFoundException();
        }

        $body = Str::trimFromArray($this->decodeJson($request), 'body');

        if ('' === $body) {
            return $this->jsonInvalidInput(['body' => 'studio.public.space.errors.comment_required']);
        }

        $channel = $this->readableChannel($link, $channelId);

        try {
            $this->chat->postAsClient($channel, $link, $body);
        } catch (FieldException) {
            // The link opens another space, or a room it does not read.
            // Answered like a stranger, for the reason the other writes give.
            throw $this->createNotFoundException();
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess($this->chatViewBuilder->payload($channel));
    }

    /**
     * Un fichier de l'espace lui-même, lu par le lien.
     *
     * Le même 404 pour tout - jeton faux, lien révoqué, fichier d'un autre
     * espace - que la route voisine, et pour la même raison.
     */
    #[Route(
        '/{selector}/{token}/files/{fileId}/{variant}',
        name: '_file_file',
        requirements: [
            'selector' => '[a-f0-9]{32}',
            'token' => '[a-f0-9]{64}',
            'fileId' => '\d+',
            'variant' => 'file|preview',
        ],
        defaults: ['variant' => 'file'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function spaceFile(string $selector, string $token, int $fileId, string $variant): Response
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        $file = $this->spaceFiles->find($fileId);

        if (!$file instanceof SpaceFileInterface || $file->getSpace()->getId() !== $link->getSpace()->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->responder->respond($this->keyOf($file->getDocument(), $variant));
    }

    /**
     * Le dossier Drive de l'espace, lu par le lien.
     *
     * **C'est ici que tout ce chantier prend son sens.** Le client n'a pas de
     * compte Google : sans cette route, les fichiers que son prestataire a
     * branchés ne seraient visibles que du studio.
     *
     * Le même 404 pour tout, et derrière le même `resolveUsable()` que la
     * page : révoquer un lien referme le dossier à l'instant où il referme la
     * page.
     */
    #[Route(
        '/{selector}/{token}/drive',
        name: '_drive',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function driveFiles(string $selector, string $token): JsonResponse
    {
        $link = $this->links->resolveUsable($selector, $token);

        // **Le même refus qu'un lien inconnu**, et la même raison que pour les
        // écritures : dire « vous pouvez lire mais pas ceci » apprend à celui
        // qui tient une adresse fuitée ce qu'il tient.
        if (!$link instanceof SpaceAccessLinkInterface || !$link->canSeeDrive()) {
            throw $this->createNotFoundException();
        }

        $account = $this->driveSettings->isEnabled() ? $this->driveSettings->account() : null;
        $folderId = $link->getSpace()->getDriveFolderId();

        if (!$account instanceof GoogleServiceAccount || null === $folderId) {
            return $this->jsonSuccess(['files' => []]);
        }

        return $this->jsonSuccess(['files' => $this->drive->files($account, $folderId)]);
    }

    /**
     * Tout le dossier partagé, en un seul fichier.
     *
     * **C'est le geste que le client vient faire.** Trente visuels partagés se
     * récupéraient en trente clics ; le lot répond à « je prends tout ».
     *
     * `priority` et non l'ordre d'écriture : sans elle, la route du fichier
     * accepterait « archive » comme identifiant.
     */
    #[Route(
        '/{selector}/{token}/drive/archive',
        name: '_drive_archive',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Get->value],
        priority: 10,
    )]
    public function driveArchive(string $selector, string $token, Request $request): Response
    {
        // **La route publique la plus chère, et la seule qui n'avait pas de
        // limite.** Elle télécharge chaque fichier chez Google et construit un
        // zip avant d'envoyer le premier octet : un lot de cent cinquante
        // mégaoctets occupe le serveur près de quatre minutes. « Je prends
        // tout » se fait une fois.
        if (!$this->spaceGuestArchiveLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw $this->createNotFoundException();
        }

        $link = $this->links->resolveUsable($selector, $token);

        // **Le même refus qu'un lien inconnu**, et la même raison que pour les
        // écritures : dire « vous pouvez lire mais pas ceci » apprend à celui
        // qui tient une adresse fuitée ce qu'il tient.
        if (!$link instanceof SpaceAccessLinkInterface || !$link->canSeeDrive()) {
            throw $this->createNotFoundException();
        }

        $account = $this->driveSettings->isEnabled() ? $this->driveSettings->account() : null;
        $folderId = $link->getSpace()->getDriveFolderId();

        if (!$account instanceof GoogleServiceAccount || null === $folderId) {
            throw $this->createNotFoundException();
        }

        $files = $this->drive->files($account, $folderId);

        if ([] === $files || $this->driveArchives->weightOf($files) > DriveArchive::MAX_BYTES) {
            // Trop lourd n'est pas une erreur à expliquer ici : l'écran ne
            // propose pas le bouton dans ce cas, et une adresse tapée à la
            // main n'a pas à recevoir un message.
            throw $this->createNotFoundException();
        }

        $path = $this->driveArchives->zipFor($account, $files);

        return $this->file($path, 'documents-'.date('Y-m-d').'.zip')->deleteFileAfterSend(true);
    }

    /**
     * Un fichier du dossier Drive, relayé au client.
     *
     * Le serveur le lit avec le compte de service et le renvoie sous une
     * adresse d'ici : une adresse Drive donnerait à ce lecteur un mur
     * d'authentification, puisque le dossier n'est partagé qu'avec le compte
     * de service et pas avec lui.
     *
     * `?download=1` pour l'emporter plutôt que le regarder : le nom du
     * fichier est alors redemandé à Google, parce que sa réponse au contenu
     * ne le porte pas.
     */
    #[Route(
        '/{selector}/{token}/drive/{fileId}',
        name: '_drive_file',
        requirements: [
            'selector' => '[a-f0-9]{32}',
            'token' => '[a-f0-9]{64}',
            'fileId' => '[A-Za-z0-9_-]+',
        ],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function driveFile(string $selector, string $token, string $fileId, Request $request): Response
    {
        $link = $this->links->resolveUsable($selector, $token);

        // **Le même refus qu'un lien inconnu**, et la même raison que pour les
        // écritures : dire « vous pouvez lire mais pas ceci » apprend à celui
        // qui tient une adresse fuitée ce qu'il tient.
        if (!$link instanceof SpaceAccessLinkInterface || !$link->canSeeDrive()) {
            throw $this->createNotFoundException();
        }

        $account = $this->driveSettings->isEnabled() ? $this->driveSettings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $link->getSpace()->getDriveFolderId()) {
            throw $this->createNotFoundException();
        }

        $response = $this->driveRelay->serve($account, $fileId, $request->query->getBoolean('download'));

        if (!$response instanceof Response) {
            throw $this->createNotFoundException();
        }

        return $response;
    }

    /**
     * A file on one of this space's cards, read through the link that shows it.
     *
     * **This route exists so that revoking an access actually revokes it.**
     * The files used to be published GED documents, which the public catch-all
     * serves to anybody holding the address with no session at all: a client
     * whose link had been revoked kept a working URL for every visual on their
     * board, for ever. They are filed as drafts now, which closes the
     * catch-all, and this is where the client reads them instead - behind the
     * same `resolveUsable()` that gates the page itself, so expiry and
     * revocation reach the files the moment they reach the page.
     *
     * No rate limiter: this is a read, and the page it serves already draws
     * every thumbnail it holds. The wall is the link.
     *
     * A 404 for everything - a bad token, a revoked link, a file belonging to
     * another space - for the reason the writes give: distinguishing them
     * tells whoever holds a leaked address what they hold.
     */
    #[Route(
        '/{selector}/{token}/attachments/{attachmentId}/{variant}',
        name: '_attachment_file',
        requirements: [
            'selector' => '[a-f0-9]{32}',
            'token' => '[a-f0-9]{64}',
            'attachmentId' => '\d+',
            'variant' => 'file|preview',
        ],
        defaults: ['variant' => 'file'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function attachmentFile(string $selector, string $token, int $attachmentId, string $variant): Response
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        $attachment = $this->attachmentRepository->find($attachmentId);

        // The card the file hangs on has to belong to the space this link
        // opens. Without this line the id in the address reaches every file of
        // every client.
        if (null === $attachment
            || $attachment->getItem()->getSpace()->getId() !== $link->getSpace()->getId()
        ) {
            throw $this->createNotFoundException();
        }

        // **Et sa colonne doit être ouverte au client.** Retirer ces fichiers
        // de la page sans fermer leur adresse n'aurait fait que cacher le
        // lien : l'identifiant est un petit entier, et une étape marquée
        // interne l'est pour de bon ou ne l'est pas.
        if (!$attachment->getItem()->getColumn()->isVisibleToClient()) {
            throw $this->createNotFoundException();
        }

        // Servi par le service commun : local déchargé par le serveur
        // web, distant diffusé par morceaux, privé une heure.
        return $this->responder->respond($this->keyOf($attachment->getDocument(), $variant));
    }

    /**
     * L'écriture vient bien de la page, et non d'un autre site.
     *
     * **Ce qui protège cette page est un secret dans son adresse**, et une
     * adresse se transfère, se colle dans un message, finit dans un
     * presse-papier. Qui la connaît peut, depuis n'importe quel site, faire
     * poster le navigateur d'un client vers ces routes : un formulaire
     * inter-site part sans demander la permission, du moment que son type de
     * contenu est ordinaire. Le dépôt de fichier, en `multipart`, est
     * exactement ce cas ; les routes JSON, elles, sont déjà retenues par le
     * contrôle préalable que le navigateur impose à un type de contenu qui
     * n'est pas ordinaire.
     *
     * L'en-tête maison referme le trou restant, et ne coûte rien : un
     * formulaire ne peut pas le poser, et un `fetch` qui le pose déclenche ce
     * même contrôle préalable. Toutes les écritures passent par le même
     * composant côté navigateur, qui l'envoie déjà.
     *
     * Le 404 des autres refus, pour la même raison : ne rien apprendre à qui
     * tâtonne.
     */
    private function assertFromThisPage(Request $request): void
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * Keeps the page out of every cache between here and the reader.
     *
     * The address is a secret handed to one person; a shared proxy holding the
     * answer would hand it to the next person asking for the same URL, and a
     * browser cache would leave a client's content plan on a machine after the
     * link is revoked.
     */
    private function privately(Response $response): Response
    {
        // The same three headers the contract page sets, and set the same way:
        // two pages that keep a secret address out of caches should not differ
        // in how thoroughly they do it.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }

    /**
     * La clé du fichier ou de sa vignette.
     *
     * Le service ne connaît pas les documents, et c'est voulu : il sert une
     * clé de stockage, quelle que soit la chose qui l'a produite.
     */
    private function keyOf(DocumentInterface $document, string $variant): string
    {
        $key = 'preview' === $variant
            ? ($document->getVariants()['thumbnail'] ?? $document->getThumbnailPath())
            : $document->getFilePath();

        if (null === $key || '' === $key) {
            throw $this->createNotFoundException();
        }

        return $key;
    }
}
