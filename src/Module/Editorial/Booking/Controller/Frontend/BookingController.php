<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Booking\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PageScriptRequestTrait;
use Aurora\Module\Editorial\Booking\Service\BookingReserver;
use Aurora\Module\Editorial\Booking\Service\BookingSlotFinder;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function filter_var;
use function implode;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mb_trim;
use function sprintf;
use function str_starts_with;

use const FILTER_VALIDATE_EMAIL;

/**
 * Where a visitor's chosen slot lands.
 *
 * Anonymous, like a poll vote - a name and an email typed on the spot, never
 * an account. The slot is re-checked against the calendar at the moment of
 * the write, which is the only moment that matters: the grid a visitor is
 * looking at may already be a few minutes stale.
 */
final class BookingController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use PageScriptRequestTrait;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly GridNormalizer $gridNormalizer,
        private readonly BookingSlotFinder $slots,
        private readonly BookingReserver $reserver,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $editorialBookingLimiter,
    ) {}

    #[Route('/{locale}/booking/{postId}/{zoneId}', name: 'editorial_booking_reserve', requirements: ['locale' => '[a-z]{2}', 'postId' => '\d+', 'zoneId' => '[A-Za-z0-9_-]{1,36}'], methods: [HttpMethodEnum::Post->value], priority: 12)]
    public function reserve(string $locale, int $postId, string $zoneId, Request $request): JsonResponse
    {
        if (!$this->isFromThisPage($request)) {
            return $this->jsonFailure('frontend.editorial.grid.booking.unavailable', 404);
        }

        if (!$this->editorialBookingLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('frontend.editorial.grid.booking.too_many', 429);
        }

        $post = $this->postRepository->find($postId);

        if (!$post instanceof PostInterface || !$post->isPublished()) {
            return $this->jsonFailure('frontend.editorial.grid.booking.unavailable', 404);
        }

        $options = $this->options($post, $zoneId);

        if (null === $options) {
            return $this->jsonFailure('frontend.editorial.grid.booking.unavailable', 404);
        }

        $payload = $this->decodeJson($request);
        $at = is_string($payload['at'] ?? null) ? $payload['at'] : null;
        // The name becomes the event's title and its source label, both 255
        // characters wide: cut here rather than let a long one fail the write.
        $name = mb_substr(mb_trim((string) ($payload['name'] ?? '')), 0, 120);
        $email = mb_trim((string) ($payload['email'] ?? ''));
        $phone = mb_trim((string) ($payload['phone'] ?? ''));
        $message = mb_substr(mb_trim((string) ($payload['message'] ?? '')), 0, 1000);

        if (null === $at || '' === $name || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonFailure('frontend.editorial.grid.booking.invalid');
        }

        $start = DateTimeImmutable::createFromFormat(DATE_ATOM, $at);

        if (false === $start) {
            return $this->jsonFailure('frontend.editorial.grid.booking.invalid');
        }

        $timezone = new DateTimeZone($options['timezone']);
        $start = $start->setTimezone($timezone);
        $end = $start->modify(sprintf('+%d minutes', (int) $options['slotDuration']));

        // Only a slot the grid could have offered, asked of the service that
        // draws the grid, so a forged instant cannot book a Tuesday at 3 a.m.
        // - or at 10:17, or past closing - because nothing said it could not.
        if (!$this->slots->isOffered($options, $start)) {
            return $this->jsonFailure('frontend.editorial.grid.booking.invalid');
        }

        $planning = $this->slots->calendar($this->translator->trans('frontend.editorial.grid.booking.calendar_name', [], 'messages', $locale));

        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle($name);
        $event->setDescription($this->summary($email, $phone, $message));
        $event->setSpan($start, $end);
        $event->setStatus(PlanningEventStatusEnum::Tentative);
        $event->setSource('editorial.booking', $postId, $name);
        $event->setSourceUrl($this->pageAddress($request));

        // Checked and written under one lock: two visitors on the same slot
        // in the same second no longer both get it.
        if (!$this->reserver->reserve($event)) {
            return $this->jsonFailure('frontend.editorial.grid.booking.taken', 409);
        }

        return $this->jsonSuccess(['label' => new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::SHORT, $timezone)->format($start)]);
    }

    /**
     * The page the booking was made from, as the back office links to it.
     *
     * Kept only when it is this site's own address: the header is the
     * browser's word, and a link in the back office should not point
     * wherever a visitor's browser was told to say it came from.
     */
    private function pageAddress(Request $request): ?string
    {
        $referer = (string) $request->headers->get('Referer', '');

        return str_starts_with($referer, $request->getSchemeAndHttpHost().'/') && mb_strlen($referer) <= 255 ? $referer : null;
    }

    private function summary(string $email, string $phone, string $message): string
    {
        $lines = [$email];

        if ('' !== $phone) {
            $lines[] = $phone;
        }

        if ('' !== $message) {
            $lines[] = '';
            $lines[] = $message;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed>|null */
    private function options(PostInterface $post, string $zoneId): ?array
    {
        $layout = $this->gridNormalizer->normalizeLayout($post->getGridLayout());

        foreach (GridNormalizer::flatten($layout['zones']) as $zone) {
            if ($zone['id'] === $zoneId && GridNormalizer::ZONE_APPOINTMENT_BOOKING === $zone['type']) {
                return $zone['options'];
            }
        }

        return null;
    }
}
