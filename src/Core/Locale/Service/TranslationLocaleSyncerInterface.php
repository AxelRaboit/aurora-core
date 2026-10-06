<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\Service;

interface TranslationLocaleSyncerInterface
{
    /**
     * Among the translations existing in DB (keyed by locale), returns the
     * ones to delete to reflect the input:
     *
     *  - locales outside the active mode (e.g. EN when single FR) are always
     *    **preserved** (so the single-locale mode can be reversed);
     *  - active locales missing from the input are marked for deletion.
     *
     * @template T
     *
     * @param iterable<string, T> $existing     existing translations keyed by locale
     * @param list<string>        $inputLocales locales present in the input
     *
     * @return list<T>
     */
    public function stale(iterable $existing, array $inputLocales): array;
}
