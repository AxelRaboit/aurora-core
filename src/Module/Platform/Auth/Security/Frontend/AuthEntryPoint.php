<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Security\Frontend;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class AuthEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator) {}

    public function start(Request $request, ?AuthenticationException $authenticationException = null): Response
    {
        // If the path looks like a front locale path (/fr/... /en/... etc.), redirect to front login.
        if (preg_match('#^/([a-z]{2})/#', $request->getPathInfo(), $matches)) {
            return new RedirectResponse(
                $this->urlGenerator->generate('frontend_login', ['locale' => $matches[1]]),
            );
        }

        return new RedirectResponse($this->urlGenerator->generate('suite_platform_login'));
    }
}
