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
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatMessageManagerInterface;
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
        private readonly SpaceContentAttachmentRepository $attachmentRepository,
        private readonly UploadPolicyProvider $uploadPolicies,
        private readonly StoredFileResponder $responder,
        private readonly SpaceChatMessageManagerInterface $chat,
        private readonly SpaceChatViewBuilder $chatViewBuilder,
        private readonly SpaceChatHub $chatHub,
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
        $cookie = $this->chatHub->subscriptionCookie($request, $link->getSpace());

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

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || !$link->canApprove()) {
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

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || !$link->canComment()) {
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

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || !$link->canUpload()) {
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
        '/{selector}/{token}/chat/messages',
        name: '_chat_messages',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function chatMessages(string $selector, string $token): JsonResponse
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        return $this->jsonSuccess($this->chatViewBuilder->payload($link->getSpace()));
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
        '/{selector}/{token}/chat',
        name: '_chat_post',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function chatPost(string $selector, string $token, Request $request): JsonResponse
    {
        if (!$this->spaceGuestWriteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.space.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || !$link->canComment()) {
            throw $this->createNotFoundException();
        }

        $body = Str::trimFromArray($this->decodeJson($request), 'body');

        if ('' === $body) {
            return $this->jsonInvalidInput(['body' => 'studio.public.space.errors.comment_required']);
        }

        try {
            $this->chat->postAsClient($link->getSpace(), $link, $body);
        } catch (FieldException) {
            // The link opens another space. Answered like a stranger, for the
            // reason the other writes give.
            throw $this->createNotFoundException();
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess($this->chatViewBuilder->payload($link->getSpace()));
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

        // Servi par le service commun : local déchargé par le serveur
        // web, distant diffusé par morceaux, privé une heure.
        return $this->responder->respond($this->keyOf($attachment->getDocument(), $variant));
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
