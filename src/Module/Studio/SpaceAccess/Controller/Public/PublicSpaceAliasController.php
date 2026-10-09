<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The short address of a client space (10/10/2026): `/c/fournier-k7m2q9`,
 * which a client can read on a card or type from a phone call.
 *
 * It leads to the space's own page, with a token computed for it
 * ({@see SpaceAccessLinkManagerInterface::aliasToken()}): the page, its rights
 * and its writes are those of the link, served by the one route that serves
 * them. Taking the short address away stops both.
 *
 * Every refusal looks the same, as on the long address, and every visit is
 * counted against the visitor's address, found or not.
 */
final class PublicSpaceAliasController extends AbstractController
{
    use PrivateAddressResponseTrait;

    public function __construct(
        private readonly SpaceAccessLinkManagerInterface $links,
        // `$spaceAliasLimiter` resolves to the `space_alias` limiter.
        private readonly RateLimiterFactoryInterface $spaceAliasLimiter,
    ) {}

    #[Route(
        '/c/{alias}',
        name: 'public_space_alias',
        requirements: ['alias' => '[A-Za-z0-9-]{3,60}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function open(string $alias, Request $request): Response
    {
        if (!$this->spaceAliasLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->unavailable(HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->links->resolveAlias($alias);
        $token = $link instanceof SpaceAccessLinkInterface ? $this->links->aliasToken($link) : null;
        if (!$link instanceof SpaceAccessLinkInterface || null === $token) {
            return $this->unavailable(HttpStatusEnum::NotFound->value);
        }

        return $this->privately($this->redirectToRoute('public_space_show', [
            'selector' => $link->getSelector(),
            'token' => $token,
        ]));
    }

    private function unavailable(int $status): Response
    {
        $response = $this->render('@Studio/public/unavailable.html.twig', [
            'messageKey' => 'studio.public.space.unavailable_message',
        ]);
        $response->setStatusCode($status);

        return $this->privately($response);
    }
}
