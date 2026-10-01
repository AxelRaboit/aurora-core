<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;

use function array_keys;
use function array_unique;
use function array_values;
use function is_array;
use function json_encode;
use function preg_match_all;
use function preg_quote;
use function sort;
use function sprintf;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Which blanks a trame asks the contract to fill.
 *
 * The wording is the declaration, and that is the whole design: writing
 * `{{contract.custom.seuil_km}}` in an article is what makes `seuil_km` a
 * field the preparation screen asks for and the freeze refuses to leave empty.
 * A separate list of expected fields would be a second source of truth, and
 * the two would disagree the first time somebody edited a clause.
 *
 * Scanned over the encoded blocks rather than over the rendered HTML: a token
 * has to be found before rendering, because the whole point is to refuse a
 * document that would print a blank.
 *
 * The prefix is a parameter because the same question is asked twice, of two
 * different sources: `contract.custom.` for what one contract fills in, and
 * `provider.` for what the settings hold. Both refuse a blank at the freeze,
 * and neither should be answered by a second copy of this regex.
 */
final readonly class ContractCustomFieldScanner
{
    /** The prefix that separates a per-contract blank from a catalogue variable. */
    public const string PREFIX = 'contract.custom.';

    /** The provider's own identity, which comes from the settings. */
    public const string PROVIDER_PREFIX = 'provider.';

    /**
     * Keys a version needs, across every language it carries.
     *
     * Every language, because a contract is issued in one of them and the
     * preparation screen cannot know which one before the fact. A key used
     * only in the Spanish wording is still a key this trame needs.
     *
     * @return list<string>
     */
    public function keysOf(ContractTemplateVersionInterface $version, string $prefix = self::PREFIX): array
    {
        $found = [];

        foreach ($version->getTranslations() as $translation) {
            foreach ($this->keysIn($translation->getContent(), $prefix) as $key) {
                $found[$key] = true;
            }

            foreach ($this->keysInText($translation->getTitle(), $prefix) as $key) {
                $found[$key] = true;
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    public function keysIn(array $content, string $prefix = self::PREFIX): array
    {
        $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];

        // Encoded rather than walked: a token can sit in a paragraph, a list
        // item, a table cell or a caption, and a walker would have to know the
        // shape of every block type to find it - which is exactly the kind of
        // knowledge that goes stale when a block type is added.
        return $this->keysInText((string) json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $prefix);
    }

    /**
     * The keys a single wording asks for, title included: a contract's
     * adapted text, which belongs to no version.
     *
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    public function keysInWording(string $title, array $content, string $prefix = self::PREFIX): array
    {
        $keys = array_values(array_unique([...$this->keysIn($content, $prefix), ...$this->keysInText($title, $prefix)]));
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function keysInText(string $text, string $prefix): array
    {
        $pattern = sprintf('/\{\{%s([a-z0-9_]+)\}\}/', preg_quote($prefix, '/'));

        if (0 === preg_match_all($pattern, $text, $matches)) {
            return [];
        }

        $keys = [];

        foreach ($matches[1] as $key) {
            $keys[$key] = true;
        }

        return array_keys($keys);
    }
}
