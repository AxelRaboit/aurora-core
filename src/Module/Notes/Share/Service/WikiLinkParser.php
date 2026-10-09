<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Service;

/**
 * The note titles a body links to, through `[[Title]]` / `[[Title#heading]]`,
 * `[[Title|shown text]]`, `[[Title#^block]]` and the inclusion `![[Title]]`.
 *
 * A second implementation of a rule the editor already owns, which is a debt
 * the project accepts under one condition: it has to be held by a test, not by
 * a comment. `WikiLinkParserMirrorTest` reads the JS and compares the two
 * patterns, so the day somebody widens one, the other fails instead of quietly
 * resolving a different set of links.
 *
 * Mirrors `markedWikiLinks.js`.
 */
final class WikiLinkParser
{
    /**
     * Mirrors the tokenizer in `markedWikiLinks.js`.
     *
     * Public because it is a contract with the front end, not an internal
     * detail: the mirror test reads it from here.
     */
    public const string PATTERN = '\[\[([^\]]+)\]\]';

    /**
     * Lower-cased titles, for matching against a title index.
     *
     * Lower-cased because a wiki link is written the way the writer remembers
     * the title, not the way it was capitalised, and the editor resolves them
     * the same way.
     *
     * @return list<string>
     */
    public function titlesIn(?string $body): array
    {
        if (null === $body || '' === $body) {
            return [];
        }

        if (0 === preg_match_all('/'.self::PATTERN.'/u', $body, $matches)) {
            return [];
        }

        $titles = [];
        foreach ($matches[1] as $raw) {
            $target = self::targetOf($raw);

            if ('' === $target) {
                continue;
            }

            $titles[] = mb_strtolower($target);
        }

        return array_values(array_unique($titles));
    }

    /**
     * The note a link's inside names, as written.
     *
     * The bar first, then the hash, as the editor reads them: in
     * `[[Note#Part|here]]` everything after the bar is what the reader sees,
     * and everything from the hash on is a place inside the note
     * (`markedWikiLinks.js`, `parseWikiTarget`).
     */
    public static function targetOf(string $raw): string
    {
        $target = mb_trim($raw);

        $bar = mb_strpos($target, '|');
        if (false !== $bar) {
            $target = mb_trim(mb_substr($target, 0, $bar));
        }

        $hash = mb_strpos($target, '#');
        if (false !== $hash) {
            return mb_trim(mb_substr($target, 0, $hash));
        }

        return $target;
    }
}
