<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Access\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Access\Manager\ContractAccessLinkManagerInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Refusal\Dto\ContractRefusalInputFactoryInterface;
use Aurora\Module\Studio\Contract\Refusal\Manager\ContractRefusalManagerInterface;
use Aurora\Module\Studio\Contract\Service\ContractPrivacyNotice;
use Aurora\Module\Studio\Contract\Service\ContractSignedDocument;
use Aurora\Module\Studio\Contract\Signature\Dto\ContractSignatureInputFactoryInterface;
use Aurora\Module\Studio\Contract\Signature\Manager\ContractSignatureChallengeManagerInterface;
use Aurora\Module\Studio\Contract\Signature\Manager\ContractSignatureManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reading a contract without an account.
 *
 * This is the only page of the module a customer ever sees, and it is
 * unauthenticated by design: the address is the credential. Four properties
 * hold it together, all enforced here rather than assumed.
 *
 * - **Nothing in the request widens the view.** The contract shown is the one
 *   the link points at. There is no id to tamper with.
 * - **Every failure looks the same.** Unknown selector, wrong secret, revoked,
 *   expired: one page, 404. Telling them apart tells a stranger which guesses
 *   landed.
 * - **The document is the stored HTML**, never re-rendered. What the customer
 *   reads is byte for byte what the hash covers.
 * - **Nothing here is indexable.** The layout sends `noindex` and the response
 *   sends `Referrer-Policy: no-referrer`, so the address does not leak through
 *   a search engine or through the referrer of any link the reader follows.
 *
 * Read-only for now. The signing form lands with the signature itself, and
 * with the rate limit and the code by email that a write route needs.
 */
#[Route('/contracts', name: 'public_contract')]
final class PublicContractController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly ContractAccessLinkManagerInterface $links,
        private readonly ContractSignatureManagerInterface $signatures,
        private readonly ContractSignatureChallengeManagerInterface $challenges,
        private readonly ContractSignatureInputFactoryInterface $inputFactory,
        private readonly ContractRefusalManagerInterface $refusals,
        private readonly ContractRefusalInputFactoryInterface $refusalInputFactory,
        private readonly ContractPrivacyNotice $privacyNotice,
        private readonly ContractSignedDocument $signedDocument,
        private readonly PayloadValidator $payloadValidator,
        // Autowired by parameter name: `$contractSignatureLimiter` resolves to
        // the `contract_signature` limiter declared in config, the same way the
        // form controller reaches `form_submission`.
        private readonly RateLimiterFactoryInterface $contractSignatureLimiter,
        private readonly RateLimiterFactoryInterface $contractSignatureCodeLimiter,
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

        if (!$link instanceof ContractAccessLinkInterface) {
            return $this->unavailable();
        }

        // Not marked opened here: mail scanners (Outlook's Safe Links and the
        // like) fetch every link they see, and the contract said « Ouvert »
        // before the customer had even read their mail. The page tells the
        // server once it is displayed in a browser, through `openedPath`.
        $contract = $link->getContract();

        return $this->privately($this->render('@Studio/public/contract.html.twig', [
            'contract' => $contract,
            'customer' => $contract->getCustomer(),
            'link' => $link,
            // The document is handed over as it was stored, with only the
            // signer's city and date written in. The template prints it raw on
            // purpose: it is the only markup on this page that must not be
            // regenerated.
            'documentHtml' => $this->signedDocument->html($contract),
            'isSigned' => $contract->getStatus()->isEngaged(),
            'codePath' => $this->generateUrl('public_contract_code', ['selector' => $selector, 'token' => $token]),
            'openedPath' => $this->generateUrl('public_contract_opened', ['selector' => $selector, 'token' => $token]),
            'signPath' => $this->generateUrl('public_contract_sign', ['selector' => $selector, 'token' => $token]),
            'refusePath' => $this->generateUrl('public_contract_refuse', ['selector' => $selector, 'token' => $token]),
            'isConcluded' => ContractStatusEnum::Countersigned === $contract->getStatus(),
            'isRefused' => $contract->isRefused(),
            // Article 13, at the moment of collection, which is this page: the
            // form below asks for a name and an email and records an IP.
            'privacy' => $this->privacyNotice->forContract($contract),
        ]));
    }

    /**
     * Sends a fresh code to the address the contract names.
     *
     * Rate limited on the IP, which is the outer wall: the ceiling per link and
     * the ten-minute window are the ones that hold, because an IP is the
     * attacker's to rotate.
     */
    /**
     * The page, displayed in a browser, says it has been opened.
     *
     * A POST sent by the page's own script, which a link scanner does not
     * run: the GET used to mark the contract « Ouvert » as soon as a mail
     * security filter followed the link.
     */
    #[Route(
        '/{selector}/{token}/opened',
        name: '_opened',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function opened(string $selector, string $token): JsonResponse
    {
        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof ContractAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        $this->links->markOpened($link);

        return $this->jsonSuccess();
    }

    #[Route(
        '/{selector}/{token}/code',
        name: '_code',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function requestCode(string $selector, string $token, Request $request): JsonResponse
    {
        if (!$this->contractSignatureCodeLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.sign.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof ContractAccessLinkInterface) {
            // The same 404 as the page: a stranger probing addresses learns
            // nothing from this endpoint either.
            throw $this->createNotFoundException();
        }

        try {
            $this->challenges->issue($link);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            'sentTo' => $this->maskEmail($link->getRecipientEmail()),
        ]);
    }

    /**
     * Signing.
     *
     * The order is the point, and it is not the obvious one:
     *
     * 1. **Rate limit**, before any work at all.
     * 2. **Validate the payload**, before the code is touched. A typo in a name
     *    must not burn a credential and send somebody back to their mailbox -
     *    and validating a form reveals nothing, so nothing is lost by doing it
     *    first.
     * 3. **Verify and consume the code**, inside the manager where the limits
     *    live.
     * 4. **Write**, and move the status last.
     *
     * There is no captcha here, and that is a decision rather than an omission.
     * Reaching this endpoint already requires 128 bits of secret in the URL and
     * a six-digit code mailed to the customer's own mailbox; a robot has
     * neither. The one thing a captcha would add - slowing somebody who holds a
     * leaked link - is already covered by the per-link ceiling and the limiter.
     * The verifier also lives in the Editorial module, and importing it would
     * make Studio stop working wherever Editorial is not installed.
     */
    #[Route(
        '/{selector}/{token}/sign',
        name: '_sign',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function sign(string $selector, string $token, Request $request): JsonResponse
    {
        if (!$this->contractSignatureLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.sign.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof ContractAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->signatures->signAsCustomer($link, $input, $request);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            'signed' => true,
            'reloadPath' => $this->generateUrl('public_contract_show', [
                'selector' => $selector,
                'token' => $token,
            ]),
        ]);
    }

    /**
     * The other answer, and the only write route on this page that asks for no
     * code.
     *
     * Rate limited on the same limiter as the signature: it is a write from an
     * unauthenticated stranger, and the wall is the same wall. The reason is
     * validated for length only - it is prose, and the one thing that matters
     * is that the column is not a place to paste a document.
     */
    #[Route(
        '/{selector}/{token}/refuse',
        name: '_refuse',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function refuse(string $selector, string $token, Request $request): JsonResponse
    {
        if (!$this->contractSignatureLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('studio.public.sign.errors.too_many_requests', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->links->resolveUsable($selector, $token);

        if (!$link instanceof ContractAccessLinkInterface) {
            throw $this->createNotFoundException();
        }

        $input = $this->refusalInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->refusals->refuseAsCustomer($link, $input, $request);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            'refused' => true,
            'reloadPath' => $this->generateUrl('public_contract_show', [
                'selector' => $selector,
                'token' => $token,
            ]),
        ]);
    }

    /**
     * `co****@durand.test`, so the page can say where the code went.
     *
     * Masked because this reply goes to whoever holds the link, and the full
     * address is one more thing they would not otherwise know. Enough of it
     * shows for the person who owns the mailbox to recognise it.
     */
    private function maskEmail(string $email): string
    {
        $at = mb_strpos($email, '@');

        if (false === $at || $at < 2) {
            return '***';
        }

        return mb_substr($email, 0, 2).str_repeat('*', $at - 2).mb_substr($email, $at);
    }

    private function unavailable(): Response
    {
        // 404 rather than 410: "gone" would confirm that this address was once
        // real, which is one bit more than a stranger should get.
        return $this->privately($this->render(
            '@Studio/public/unavailable.html.twig',
            [],
            new Response(status: Response::HTTP_NOT_FOUND),
        ));
    }

    /**
     * Headers that keep a secret address from travelling.
     *
     * `no-referrer` so following any link off this page does not hand the URL
     * to another site. `no-store` so a shared machine's back button and a proxy
     * cache do not keep a copy of a contract.
     */
    private function privately(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }
}
