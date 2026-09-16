<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Core\Http\Csp\CspNonce;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()`, for the few scripts that have to be inline.
 *
 * Asking for it is what puts it in the header: {@see CspNonce} generates on
 * first use and the subscriber only advertises a nonce that something used. So
 * a template gets the nonce by needing it, and a page that needs none carries
 * none.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonce $nonce) {}

    /**
     * @return list<TwigFunction>
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce->value(...)),
        ];
    }
}
