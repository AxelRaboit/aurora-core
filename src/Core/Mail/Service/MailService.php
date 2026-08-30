<?php

declare(strict_types=1);

namespace Aurora\Core\Mail\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Shared mailer for all notification services. Handles the common boilerplate:
 *   - resolving site name from settings
 *   - building "[Site] subject" pattern from a translation key
 *   - rendering Twig templates with `siteName` injected by default
 *   - silently no-op'ing when the recipient is empty (so callers don't have to guard)
 *   - forcing the locale defined by ApplicationParameterEnum::EmailLocale (admin setting)
 *     so customer emails don't accidentally inherit the actor's request locale
 *
 * Each domain notification service (Order, Comment, CRM…) injects this
 * and stays focused on building the right context per email type.
 */
final readonly class MailService
{
    /**
     * What `backend_email` used to ship with, before it was seeded empty. Kept
     * so the installations that already carry it stop mailing a domain that
     * does not resolve - see adminEmail().
     */
    private const string LEGACY_PLACEHOLDER_ADMIN_EMAIL = 'admin@aurora.app';

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private SettingRepository $settingRepository,
        private TranslatorInterface $translator,
        private LocaleSwitcher $localeSwitcher,
        private string $mailerFrom,
        private string $adminEmail = '',
    ) {}

    /**
     * Send a templated email to a recipient.
     *
     * @param string                $to            Recipient email; empty = silent no-op
     * @param string                $subjectKey    i18n key, will be wrapped as "[SiteName] <translated>"
     * @param string                $template      Twig template (e.g. "@Ecommerce/email/order_paid.html.twig")
     * @param array<string, mixed>  $context       Template variables (siteName injected automatically)
     * @param list<string>          $cc            CC recipients (filtered to avoid duplicate of $to)
     * @param string|null           $locale        Override locale (e.g. customer's stored locale).
     *                                             When null, falls back to EmailLocale setting → DefaultLocale.
     * @param array<string, string> $subjectParams Translation parameters for the subject (e.g. ['{title}' => $title])
     */
    public function send(
        string $to,
        string $subjectKey,
        string $template,
        array $context = [],
        array $cc = [],
        ?string $locale = null,
        array $subjectParams = [],
    ): void {
        if ('' === $to) {
            return;
        }

        $send = function () use ($to, $subjectKey, $template, $context, $cc, $subjectParams): void {
            $siteName = $this->siteName();
            $body = $this->twig->render($template, ['siteName' => $siteName] + $context);
            $subject = sprintf('[%s] %s', $siteName, $this->translator->trans($subjectKey, $subjectParams));

            $email = new Email()
                ->from($this->mailerFrom)
                ->to($to)
                ->subject($subject)
                ->html($body);

            foreach ($cc as $ccAddress) {
                if ('' !== $ccAddress && $ccAddress !== $to) {
                    // `cc()` is a SETTER that replaces the whole list - use
                    // `addCc()` to append each address so multiple CCs stick.
                    $email->addCc($ccAddress);
                }
            }

            $this->mailer->send($email);
        };

        $effectiveLocale = $locale ?? $this->emailLocale();
        if (null === $effectiveLocale) {
            $send();

            return;
        }

        $this->localeSwitcher->runWithLocale($effectiveLocale, $send);
    }

    /**
     * Send to the configured admin email. No-op when AdminEmail isn't set.
     *
     * @param array<string, mixed>  $context
     * @param array<string, string> $subjectParams
     */
    public function sendToAdmin(string $subjectKey, string $template, array $context = [], array $subjectParams = []): void
    {
        $adminEmail = $this->adminEmail();
        if (null === $adminEmail) {
            return;
        }

        $this->send($adminEmail, $subjectKey, $template, $context, subjectParams: $subjectParams);
    }

    public function siteName(): string
    {
        return $this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteName);
    }

    /**
     * Who receives the notifications the application sends to nobody in
     * particular: a form submission, a comment awaiting moderation.
     *
     * The setting wins when an administrator has actually set it. Unset, or
     * still carrying the `admin@aurora.app` it used to be seeded with, the
     * ADMIN_EMAIL of the deployment is the better answer: whoever installed
     * the server filled it, and it is an address that exists.
     *
     * Returning null when neither is set is deliberate - sendToAdmin() skips,
     * which is honest. Sending to a plausible-looking address that bounces is
     * the failure this method exists to avoid.
     */
    public function adminEmail(): ?string
    {
        $email = $this->settingRepository->get(ApplicationParameterEnum::AdminEmail->value);

        if (null !== $email && '' !== $email && self::LEGACY_PLACEHOLDER_ADMIN_EMAIL !== $email) {
            return $email;
        }

        return '' !== $this->adminEmail ? $this->adminEmail : null;
    }

    private function emailLocale(): ?string
    {
        $locale = $this->settingRepository->get(ApplicationParameterEnum::EmailLocale->value);
        if (null !== $locale && '' !== $locale) {
            return $locale;
        }

        // Fallback to the site's default locale so emails are predictable
        // even before the admin explicitly visits the email settings tab.
        $default = $this->settingRepository->get(
            ApplicationParameterEnum::DefaultLocale->value,
            ApplicationParameterEnum::DefaultLocale->getDefaultValue(),
        );

        return '' === $default ? null : $default;
    }
}
