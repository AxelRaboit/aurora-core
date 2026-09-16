<?php

declare(strict_types=1);

namespace Aurora\Core\EventSubscriber;

use Aurora\Core\Http\Csp\ContentSecurityPolicy;
use Aurora\Core\Http\Csp\CspNonce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function str_contains;

/**
 * Hands every HTML page its policy.
 *
 * **HTML only, and on purpose.** A `Content-Security-Policy` describes what a
 * document may load; a PDF, an image or a JSON payload loads nothing, and
 * putting the header on them would say something about files that other
 * headers already govern. It would also have a consequence nobody wants:
 * `frame-ancestors 'none'` on a PDF response stops the document preview from
 * showing it.
 *
 * **Not on a sub-request**, or a rendered fragment would overwrite the page's
 * own header with a second one.
 *
 * **A header already set is left alone.** Symfony's profiler rewrites the
 * policy in development to slip its own nonces in, and a client project may
 * want a stricter one; neither should have to fight this.
 *
 * **Priority zero, which puts it ahead of the profiler's toolbar listener at
 * -128, and that ordering is the whole of it.** Symfony's
 * `ContentSecurityPolicyHandler` reads an existing policy and slips a nonce
 * into it for the toolbar's own inline script. Set this header after that
 * listener and there is nothing for it to read, so in development the toolbar
 * is blocked by a policy it never saw - measured, a console full of refusals
 * on a page that was otherwise fine.
 *
 * Nothing is lost by running early: the nonce is generated while the templates
 * render, which is before any response listener.
 */
final readonly class ContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ContentSecurityPolicy $policy,
        private CspNonce $nonce,
    ) {}

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', 0]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        // An empty type means Symfony never set one, which for a rendered page
        // means HTML. A file response always carries one.
        if ('' !== $contentType && !str_contains($contentType, 'text/html')) {
            return;
        }

        $response->headers->set(
            'Content-Security-Policy',
            // No nonce in the header when no tag asked for one: a page with
            // nothing inline gains nothing from carrying it, and a policy that
            // lists a nonce nobody used reads as though something were missing.
            $this->policy->header($this->nonce->wasUsed() ? $this->nonce->value() : null),
        );

        $this->nonce->reset();
    }
}
