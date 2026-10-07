<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\Service;

/**
 * Elides "de" before a vowel in a French string: "de Atelier" becomes
 * "d'Atelier".
 *
 * The catalogue writes "Livrables de {space}" once, and the name only
 * arrives at render time: a space called "Atelier Dupont" read "Livrables
 * de Atelier Dupont". No static text of the catalogue has "de" before a
 * vowel, so the rule only ever touches what was inserted.
 *
 * Left alone: a single letter ("de A à Z"), and y and h, which elide or not
 * depending on the word ("de Yann", "de hêtre", "d'Hélène"): guessing wrong
 * there is worse than not eliding.
 *
 * The same rule lives in `frenchElision.js` for the Vue half of the suite.
 */
final class FrenchElision
{
    private const string PATTERN = '/(^|[\s(«"\'])([Dd])e (?=[aeiouàâäéèêëîïôöûüAEIOUÀÂÄÉÈÊËÎÏÔÖÛÜ]\p{L})/u';

    public static function apply(string $text): string
    {
        return preg_replace(self::PATTERN, "\$1\$2'", $text) ?? $text;
    }
}
