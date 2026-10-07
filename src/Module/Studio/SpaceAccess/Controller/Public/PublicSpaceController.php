<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PageScriptRequestTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
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
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
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
use Aurora\Module\Studio\SpaceFile\Manager\SpaceFileManagerInterface;
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
    use PrivateAddressResponseTrait;

    use JsonRequestTrait;
    use JsonResponseTrait;
    use PageScriptRequestTrait;

    /** How many cards one "approve" gesture may carry. */
    private const int MAX_APPROVED_AT_ONCE = 100;

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
        private readonly SpaceFileManagerInterface $spaceFileManager,
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

        // Read once, for the page and for the hub's cookie below.
        $rooms = $this->chatViewBuilder->roomsForLink($link);

        $response = $this->privately($this->render('@Studio/public/space.html.twig', [
            ...$this->viewBuilder->view($link, $token),
            ...$this->chatViewBuilder->publicView($link, $token, $rooms),
            ...$this->filesViewBuilder->publicView($link, $token),
        ]));

        // **The one place a guest is authorised at the hub.** Everything else
        // on this page is authorised by the address; a push connection cannot
        // be, because the hub has never heard of this link. So whoever just
        // proved they hold a usable one leaves with a short-lived JWT, scoped
        // to this space's topic and to subscribing only, in a cookie the
        // browser sends nowhere but the hub. Revoking the link stops this page
        // being served, and the cookie runs out on its own.
        $cookie = $this->chatHub->subscriptionCookie($request, $rooms);

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

        // **`isPreview()` before the right, and on all six writes.** A
        // preview copies the rights of the link it shows so that the screen is
        // the same; that does not mean it may answer. Without this line, a
        // careless click on "Validé" would record an answer in the client's
        // name, and nothing in the space would say it came from a preview.
        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview() || !$link->canApprove()) {
            throw $this->createNotFoundException();
        }

        $item = $this->clientItem($link, $itemId);

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
     * Approve several cards in one gesture.
     *
     * **Batch approval exists, batch change requests do not.** Approving ten
     * items at once says one thing, ten times; asking for a change without
     * saying which one teaches the studio nothing and forces it to call the
     * client back to understand. A change request therefore stays attached to
     * one card and its comment, on the singular route.
     *
     * The same limiter as the single answer, and one consumption for the call:
     * it is one user gesture, not ten, and charging it ten times would shut the
     * door on someone sorting out their week.
     *
     * Cards from another space are silently ignored rather than refused: the
     * loop does not stop on one, and a forged id teaches nothing more than a
     * 404 would.
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

        // Read in one query, from this space only, and a hundred at most: the
        // list comes from a guest, and each card is a write and a line of
        // audit. A week's board is far below it; a longer list is cut rather
        // than refused, and the count returned says how many went through.
        $wanted = array_slice(array_values(array_unique(array_map(
            intval(...),
            array_filter($ids, static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id))),
        ))), 0, self::MAX_APPROVED_AT_ONCE);

        $items = array_values(array_filter(
            [] === $wanted ? [] : $this->itemRepository->findBy(['id' => $wanted, 'space' => $link->getSpace()]),
            fn (SpaceContentItemInterface $item): bool => $this->isShownTo($link, $item),
        ));

        $approved = $this->items->approveMany($items, $link);

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

        $item = $this->clientItem($link, $itemId);

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

        $item = $this->clientItem($link, $itemId);

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
     * A file from the client, onto the space itself rather than a card.
     *
     * The same three walls as {@see self::attach()}, in the same order and for
     * the same reasons: the upload limiter by address, the link's `canUpload`
     * (answered like a stranger without it, and for a preview whatever it
     * copied, since a preview never sends anything in the client's name), then
     * {@see UploadPolicy::forSpaceGuests()} on the sniffed bytes, refused with
     * a sentence the client can act on. The header guard of the other public
     * writes comes first: a multipart form is exactly what another site could
     * make the client's browser post.
     *
     * The answer is the space's files as this page lists them, so the page
     * replaces its list rather than guessing what its own write did.
     */
    #[Route(
        '/{selector}/{token}/files',
        name: '_file_upload',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function uploadFile(string $selector, string $token, Request $request): JsonResponse
    {
        if (!$this->spaceGuestUploadLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.space.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $this->assertFromThisPage($request);

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof SpaceAccessLinkInterface || $link->isPreview() || !$link->canUpload()) {
            throw $this->createNotFoundException();
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->jsonInvalidInput(['file' => 'studio.public.space.errors.upload_required']);
        }

        $refusal = $this->uploadPolicies->forSpaceGuests()->refusalFor($file);

        if ($refusal instanceof UploadRefusalEnum) {
            return $this->jsonInvalidInput(['file' => match ($refusal) {
                UploadRefusalEnum::TooLarge => 'studio.public.space.errors.upload_too_large',
                UploadRefusalEnum::TypeRefused => 'studio.public.space.errors.upload_type_refused',
                UploadRefusalEnum::Broken => 'studio.public.space.errors.upload_failed',
            }]);
        }

        $this->spaceFileManager->uploadAsClient($link, $file);
        $this->links->markOpened($link);

        return $this->jsonSuccess($this->filesViewBuilder->publicView($link, $token));
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

    /** What comes before what the client's page already holds. */
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

    // There is no longer any private conversation without an account.
    //
    // **A route left this place, and it is a deliberate decision.** A guest
    // could open a conversation with any member of the team, and received the
    // space's named directory to do so. The right that allowed it was "may
    // comment": ticking a box to allow a remark under a post actually opened a
    // messaging line to the employees and gave out their names.
    //
    // What a client has to say therefore goes through a channel, which the
    // studio opens when it decides to. The studio keeps its private
    // conversations between colleagues: only the guest side is closed.
    //
    // The guard that matters is not this absence but
    // {@see SpaceChatChannelRepository::findForLink()}, which no longer returns
    // any direct channel to a link - including those opened before this change.

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

        // `canChat` and not `canComment`: commenting on a card and talking in
        // the space's chat are two conversations, and a single right used to
        // control both.
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
     * A file of the space itself, read through the link.
     *
     * The same 404 for everything - wrong token, revoked link, file of another
     * space, file hidden from the client - as the neighbouring route, and for
     * the same reason. The last case counts as much as the others: removing a
     * file from the page without closing its address would only have hidden
     * the link, and the id is a small integer.
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

        if (!$file instanceof SpaceFileInterface || $file->getSpace()->getId() !== $link->getSpace()->getId() || !$file->isShownToClient()) {
            throw $this->createNotFoundException();
        }

        return $this->responder->respond($this->keyOf($file->getDocument(), $variant));
    }

    /**
     * The space's Drive folder, read through the link.
     *
     * **This is where the whole feature makes sense.** The client has no
     * Google account: without this route, the files their provider connected
     * would be visible to the studio only.
     *
     * The same 404 for everything, and behind the same `resolveUsable()` as
     * the page: revoking a link closes the folder the moment it closes the
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

        // **The same refusal as an unknown link**, and the same reason as for
        // the writes: saying "you may read but not this" tells whoever holds a
        // leaked address what they hold.
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
     * The whole shared folder, in a single file.
     *
     * **This is the gesture the client comes to make.** Thirty shared visuals
     * took thirty clicks to fetch; the batch answers "I'll take everything".
     *
     * `priority` and not the order of declaration: without it, the file route
     * would accept "archive" as an id.
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
        // **The most expensive public route, and the only one that had no
        // limit.** It downloads every file from Google and builds a zip before
        // sending the first byte: a batch of a hundred and fifty megabytes
        // keeps the server busy for nearly four minutes. "I'll take
        // everything" happens once.
        if (!$this->spaceGuestArchiveLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw $this->createNotFoundException();
        }

        $link = $this->links->resolveUsable($selector, $token);

        // **The same refusal as an unknown link**, and the same reason as for
        // the writes: saying "you may read but not this" tells whoever holds a
        // leaked address what they hold.
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
            // Too heavy is not an error to explain here: the screen does not
            // offer the button in that case, and an address typed by hand
            // does not need to get a message.
            throw $this->createNotFoundException();
        }

        $path = $this->driveArchives->zipFor($account, $files);

        return $this->file($path, 'documents-'.date('Y-m-d').'.zip')->deleteFileAfterSend(true);
    }

    /**
     * A file of the Drive folder, relayed to the client.
     *
     * The server reads it with the service account and sends it back under an
     * address of its own: a Drive address would give this reader an
     * authentication wall, since the folder is shared only with the service
     * account and not with them.
     *
     * `?download=1` to take it away rather than look at it: the file name is
     * then asked of Google again, because its content response does not carry
     * it.
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

        // **The same refusal as an unknown link**, and the same reason as for
        // the writes: saying "you may read but not this" tells whoever holds a
        // leaked address what they hold.
        if (!$link instanceof SpaceAccessLinkInterface || !$link->canSeeDrive()) {
            throw $this->createNotFoundException();
        }

        $account = $this->driveSettings->isEnabled() ? $this->driveSettings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $link->getSpace()->getDriveFolderId()) {
            throw $this->createNotFoundException();
        }

        $response = $this->driveRelay->serve($account, $link->getSpace()->getDriveFolderId(), $fileId, $request->query->getBoolean('download'));

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

        // The card the file hangs on has to belong to the space this link
        // opens - checked in the query itself. Without it the id in the
        // address reaches every file of every client.
        $attachment = $this->attachmentRepository->findForGuest($attachmentId, $link->getSpace());

        if (!$attachment instanceof SpaceContentAttachmentInterface) {
            throw $this->createNotFoundException();
        }

        // **And its card must be on the client's page.** Removing these files
        // from the page without closing their address would only have hidden
        // the link: the id is a small integer, and a step marked internal is
        // internal for real or not at all.
        if (!$attachment->getItem()->isShownToClient()) {
            throw $this->createNotFoundException();
        }

        // Served by the shared service: local files offloaded to the web
        // server, remote ones streamed in chunks, private for an hour.
        return $this->responder->respond($this->keyOf($attachment->getDocument(), $variant));
    }

    /**
     * A card this link shows, or a 404.
     *
     * **The page's own sieve, asked again at each write.** A card in a column
     * kept internal is not on the client's page, yet approving it, commenting
     * on it or sending it a file used to check only that it was in the space:
     * a card number was enough to act on work the studio had not shown.
     */
    private function clientItem(SpaceAccessLinkInterface $link, int $itemId): SpaceContentItemInterface
    {
        $item = $this->itemRepository->find($itemId);

        if (!$item instanceof SpaceContentItemInterface || !$this->isShownTo($link, $item)) {
            throw $this->createNotFoundException();
        }

        return $item;
    }

    private function isShownTo(SpaceAccessLinkInterface $link, SpaceContentItemInterface $item): bool
    {
        return $item->getSpace()->getId() === $link->getSpace()->getId() && $item->isShownToClient();
    }

    /**
     * The write really comes from the page, and not from another site.
     *
     * **What protects this page is a secret in its address**, and an address
     * gets forwarded, pasted into a message, ends up in a clipboard. Whoever
     * knows it can, from any site, make a client's browser post to these
     * routes: a cross-site form goes out without asking permission, as long as
     * its content type is a simple one. The file upload, in `multipart`, is
     * exactly that case; the JSON routes are already held back by the
     * preflight check the browser imposes on a content type that is not a
     * simple one.
     *
     * The house header closes the remaining hole, and costs nothing: a form
     * cannot set it, and a `fetch` that sets it triggers that same preflight.
     * Every write goes through the same component on the browser side, which
     * already sends it.
     *
     * The 404 of the other refusals, for the same reason: teach nothing to
     * whoever is probing.
     */
    private function assertFromThisPage(Request $request): void
    {
        if (!$this->isFromThisPage($request)) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * The key of the file or of its thumbnail.
     *
     * The service knows nothing about documents, on purpose: it serves a
     * storage key, whatever produced it.
     */
    private function keyOf(DocumentInterface $document, string $variant): string
    {
        $key = 'preview' === $variant
            ? ($document->getRenditions()['thumbnail'] ?? $document->getThumbnailPath())
            : $document->getFilePath();

        if (null === $key || '' === $key) {
            throw $this->createNotFoundException();
        }

        return $key;
    }
}
