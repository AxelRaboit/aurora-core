<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Newsletter;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Newsletter\Setting\NewsletterSettingEnum;
use Aurora\Module\Editorial\Newsletter\Setting\NewsletterSettings;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function json_decode;
use function json_encode;

/**
 * What the GDPR asks of a sign-up form, held by the server and not only by
 * the page: no form without a privacy policy to link, no address without an
 * explicit consent, and nothing accepted from another site.
 *
 * None of these requests reaches the provider: each is refused before the
 * call, which is also what keeps this test off the network.
 */
final class NewsletterComplianceTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->resetRateLimiter('newsletter_subscription');
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(SettingRepository::class)->saveMany(
            array_map(static fn (NewsletterSettingEnum $case): array => [$case->value, null], NewsletterSettingEnum::cases()),
        );

        parent::tearDown();
    }

    public function testTheModuleStaysOffWithoutAPrivacyPolicy(): void
    {
        $this->configure(privacyUrl: null);

        self::assertFalse(static::getContainer()->get(NewsletterSettings::class)->isEnabled());
        self::assertSame(404, $this->subscribe(['email' => 'axel@example.com', 'consent' => true])->getStatusCode());
    }

    public function testNoAddressIsTakenWithoutConsent(): void
    {
        $this->configure(privacyUrl: '/fr/page/confidentialite');

        $response = $this->subscribe(['email' => 'axel@example.com']);

        self::assertSame('frontend.editorial.grid.newsletter.consent_required', json_decode((string) $response->getContent(), true)['error'] ?? null);
        self::assertSame('frontend.editorial.grid.newsletter.consent_required', json_decode((string) $this->subscribe(['email' => 'axel@example.com', 'consent' => 'yes'])->getContent(), true)['error'] ?? null, 'only a real tick counts');
    }

    public function testASignUpPostedFromAnotherSiteIsRefused(): void
    {
        $this->configure(privacyUrl: '/fr/page/confidentialite');

        $this->client->request('POST', '/fr/newsletter', server: ['CONTENT_TYPE' => 'text/plain'], content: '{"email":"axel@example.com","consent":true}');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheSettingsRefuseToSwitchOnWithoutAPolicy(): void
    {
        $response = $this->saveSettings(['enabled' => true, 'termsAccepted' => true, 'provider' => 'mailchimp', 'apiKey' => 'abcd-us21', 'listId' => 'l1', 'privacyUrl' => '']);

        self::assertSame('backend.editorial.newsletter.errors.privacy_required', $response['error'] ?? null);
    }

    public function testBrevoConfirmationNeedsItsTemplate(): void
    {
        $response = $this->saveSettings(['enabled' => true, 'termsAccepted' => true, 'provider' => 'brevo', 'apiKey' => 'xkeysib', 'listId' => '3', 'privacyUrl' => '/fr/page/confidentialite', 'doubleOptIn' => true, 'brevoTemplateId' => '']);

        self::assertSame('backend.editorial.newsletter.errors.template_required', $response['error'] ?? null);
    }

    public function testAPolicyAddressThatIsNotALinkIsRefused(): void
    {
        $response = $this->saveSettings(['enabled' => false, 'termsAccepted' => true, 'provider' => 'mailchimp', 'privacyUrl' => 'javascript:alert(1)']);

        self::assertSame('backend.editorial.newsletter.errors.privacy_url_invalid', $response['error'] ?? null);
    }

    public function testConfirmationIsOnUnlessSwitchedOff(): void
    {
        self::assertTrue(static::getContainer()->get(NewsletterSettings::class)->doubleOptIn());
    }

    private function configure(?string $privacyUrl): void
    {
        static::getContainer()->get(NewsletterSettings::class)->save(
            enabled: true,
            termsAccepted: true,
            provider: 'mailchimp',
            apiKey: 'abcd1234-us21',
            listId: 'list-id',
            acceptedBy: 'test',
            privacyUrl: $privacyUrl ?? '',
        );
    }

    /** @param array<string, mixed> $payload */
    private function subscribe(array $payload): Response
    {
        $this->client->request('POST', '/fr/newsletter', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function saveSettings(array $payload): array
    {
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('POST', '/backend/editorial/newsletter/settings', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
