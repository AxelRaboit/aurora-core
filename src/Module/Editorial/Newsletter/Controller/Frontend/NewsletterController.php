<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PageScriptRequestTrait;
use Aurora\Module\Editorial\Newsletter\Service\NewsletterSubscriber;
use Aurora\Module\Editorial\Newsletter\Setting\NewsletterSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function filter_var;
use function mb_trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Where an email typed into a newsletter-signup zone lands: the client's own
 * Brevo or Mailchimp list, added through their own key.
 */
final class NewsletterController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use PageScriptRequestTrait;

    public function __construct(
        private readonly NewsletterSettings $settings,
        private readonly NewsletterSubscriber $subscriber,
        private readonly RateLimiterFactoryInterface $newsletterSubscriptionLimiter,
    ) {}

    #[Route('/{locale}/newsletter', name: 'editorial_newsletter_subscribe', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Post->value], priority: 11)]
    public function subscribe(string $locale, Request $request): JsonResponse
    {
        if (!$this->settings->isEnabled() || !$this->isFromThisPage($request)) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.unavailable', 404);
        }

        if (!$this->newsletterSubscriptionLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.too_many', 429);
        }

        $payload = $this->decodeJson($request);
        $email = mb_trim((string) ($payload['email'] ?? ''));

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.invalid');
        }

        // Consent is a tick the visitor gives, never assumed from the click
        // on the button: the form's box starts unticked, and the server asks
        // for it too, so a request without it adds nobody.
        if (true !== ($payload['consent'] ?? null)) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.consent_required');
        }

        $subscribed = $this->subscriber->subscribe(
            $this->settings->provider(),
            $this->settings->apiKey(),
            $this->settings->listId(),
            $email,
            doubleOptIn: $this->settings->doubleOptIn(),
            brevoTemplateId: $this->settings->brevoTemplateId(),
            // Where the confirmation link lands: this site's home page, in
            // the visitor's language.
            redirectUrl: $request->getSchemeAndHttpHost().'/'.$locale,
            visitorIp: $request->getClientIp(),
        );

        if (!$subscribed) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.failed', 502);
        }

        return $this->jsonSuccess();
    }
}
