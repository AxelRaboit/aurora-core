<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes;

use Aurora\Module\Notes\Share\Service\WikiLinkParser;
use PHPUnit\Framework\TestCase;

/**
 * The PHP and JS wiki-link patterns have to stay the same expression.
 *
 * Sharing resolves links server-side, the editor resolves them in the browser,
 * and the two answering differently is the kind of drift nothing reports: the
 * reader would simply find a link the editor showed as live rendered as dead
 * text, or the other way round.
 *
 * Per `convention_mirrored_contract_php_js`: the duplication is allowed, a test
 * holds it rather than a comment.
 */
final class WikiLinkParserMirrorTest extends TestCase
{
    private const string JS_PATH = __DIR__.'/../../../../src/Module/Notes/assets/backend/markdown/composables/markedExtensions/markedWikiLinks.js';

    public function testThePhpPatternMatchesTheOneInTheEditor(): void
    {
        $js = file_get_contents(self::JS_PATH);
        self::assertIsString($js, 'The editor extension could not be read.');

        // The tokenizer's literal, anchored at the token start with `^`, which
        // the PHP side does not carry because it scans a whole body.
        self::assertSame(
            1,
            preg_match('/src\.match\(\/\^(.+?)\/\)/', $js, $matches),
            'The tokenizer regex could not be found in markedWikiLinks.js - if it moved, this test has to follow it rather than be deleted.',
        );

        self::assertSame(
            WikiLinkParser::PATTERN,
            $matches[1],
            'The wiki-link pattern drifted between PHP and JS.',
        );
    }
}
