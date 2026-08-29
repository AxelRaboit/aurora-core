<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Service;

/**
 * The note titles a body links to, through `[[Title]]` / `[[Title#heading]]`.
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
            $target = mb_trim($raw);

            // `[[Title#heading]]` points at a place inside a note, not at
            // another note: everything from the hash on is an anchor.
            $hash = mb_strpos($target, '#');
            if (false !== $hash) {
                $target = mb_trim(mb_substr($target, 0, $hash));
            }

            if ('' === $target) {
                continue;
            }

            $titles[] = mb_strtolower($target);
        }

        return array_values(array_unique($titles));
    }
}
