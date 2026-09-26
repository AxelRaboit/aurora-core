<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
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

    public function __construct(
        private readonly NewsletterSettings $settings,
        private readonly NewsletterSubscriber $subscriber,
        private readonly RateLimiterFactoryInterface $newsletterSubscriptionLimiter,
    ) {}

    #[Route('/{locale}/newsletter', name: 'editorial_newsletter_subscribe', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Post->value], priority: 11)]
    public function subscribe(string $locale, Request $request): JsonResponse
    {
        if (!$this->settings->isEnabled()) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.unavailable', 404);
        }

        if (!$this->newsletterSubscriptionLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.too_many', 429);
        }

        $email = mb_trim((string) ($this->decodeJson($request)['email'] ?? ''));

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.invalid');
        }

        $subscribed = $this->subscriber->subscribe($this->settings->provider(), $this->settings->apiKey(), $this->settings->listId(), $email);

        if (!$subscribed) {
            return $this->jsonFailure('frontend.editorial.grid.newsletter.failed', 502);
        }

        return $this->jsonSuccess();
    }
}
