<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class FormInput implements FormInputInterface
{
    /**
     * @param array<string, array{title: string, slug: ?string, description: ?string}> $translations
     * @param list<array{title: string}>|null                                          $steps
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'backend.forms.errors.translations_required')]
        public readonly array $translations,
        #[Assert\Email(message: 'backend.forms.errors.notify_email_invalid')]
        #[Assert\Length(max: 180, maxMessage: 'backend.forms.errors.notify_email_invalid')]
        public readonly ?string $notifyEmail = null,
        #[Assert\Url(message: 'backend.forms.errors.webhook_url_invalid', requireTld: false)]
        #[Assert\Length(max: 500, maxMessage: 'backend.forms.errors.webhook_url_invalid')]
        public readonly ?string $webhookUrl = null,
        public readonly bool $crmSync = false,
        public readonly bool $active = true,
        public readonly ?array $steps = null,
    ) {}

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function isCrmSync(): bool
    {
        return $this->crmSync;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getSteps(): ?array
    {
        return $this->steps;
    }
}
