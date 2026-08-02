<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Dto;

interface FormInputInterface
{
    /** @return array<string, array{title: string, slug: ?string, description: ?string}> */
    public function getTranslations(): array;

    public function getNotifyEmail(): ?string;

    public function getWebhookUrl(): ?string;

    public function isCrmSync(): bool;

    public function isActive(): bool;

    /** @return list<array{title: string}>|null */
    public function getSteps(): ?array;
}
