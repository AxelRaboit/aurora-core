<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Str;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\View\PublicSpaceViewBuilder;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentAttachmentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentCommentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Service\SpaceGuestUploadPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly SpaceGuestUploadPolicy $uploadPolicy,
        // A limiter of its own rather than the one above. A verdict is a row; a
        // file is megabytes through the whole pipeline - storage, thumbnailing,
        // a poster frame for a video - and forty of those an hour from one
        // address is not the same offer as forty clicks.
        private readonly RateLimiterFactoryInterface $spaceGuestUploadLimiter,
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
    public function show(string $selector, string $token): Response
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

        return $this->privately($this->render('@Studio/public/space.html.twig', $this->viewBuilder->view($link, $token)));
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

        return $this->jsonSuccess($this->viewBuilder->threadPayload($link));
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

        return $this->jsonSuccess($this->viewBuilder->threadPayload($link));
    }

    /**
     * A file from the client, onto one of their cards.
     *
     * **Three walls, and they are not interchangeable.** The limiter is the
     * outer one and counts by address. The link's own `canUpload` is the
     * middle one, and a link without it gets the same 404 a stranger gets -
     * saying "you may read but not send" would tell somebody holding a leaked
     * address exactly what they hold. {@see SpaceGuestUploadPolicy} is the
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

        $refusal = $this->uploadPolicy->refusalFor($file);

        if (null !== $refusal) {
            return $this->jsonInvalidInput(['file' => $refusal]);
        }

        try {
            $this->attachments->uploadAsClient($item, $link, $file);
        } catch (FieldException) {
            // The card belongs to another space.
            throw $this->createNotFoundException();
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess($this->viewBuilder->threadPayload($link));
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
}
