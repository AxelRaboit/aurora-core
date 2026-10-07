<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\Service;

interface LocaleOptionsProviderInterface
{
    /**
     * Locale options usable on the Vue side (tabs / selects), filtered on the
     * active locales (in single-locale mode, only holds the default
     * locale).
     *
     * @return list<array{code: string, label: string}>
     */
    public function getActiveOptions(): array;
}
