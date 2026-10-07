<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\Service;

interface LocaleContextInterface
{
    public function isSingleLocaleMode(): bool;

    public function getDefaultLocale(): string;

    /**
     * Locales active for display / writing (only 1 in single-locale mode).
     *
     * @return list<string>
     */
    public function getActiveLocales(): array;

    /**
     * Every locale declared by the bundle, regardless of the mode.
     * For static tools (JS translations dump, etc.).
     *
     * @return list<string>
     */
    public function getAllLocales(): array;
}
