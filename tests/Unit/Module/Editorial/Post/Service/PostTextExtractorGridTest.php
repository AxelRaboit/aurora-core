<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * What of a content grid reaches the search index.
 *
 * Worth its own test because the failure it guards against is silent. The body
 * moved into the grid when the grid became the only one; an extractor still
 * reading `blocks` alone goes on returning a string - the pre-migration one,
 * since that column is deliberately kept - so search keeps answering, with
 * answers a version out of date. Nothing throws, nothing is red, and the only
 * symptom is a page nobody can find.
 */
final class PostTextExtractorGridTest extends TestCase
{
    private PostTextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PostTextExtractor();
    }

    public function testTheWordsOfEveryTextZoneAreCollected(): void
    {
        $text = $this->extractor->textFromGrid(['zones' => [
            'intro' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Une bastide restaurée']],
            ]],
            'outro' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Ouverte toute l\'année']],
            ]],
        ]]);

        self::assertStringContainsString('Une bastide restaurée', $text);
        self::assertStringContainsString('Ouverte toute l\'année', $text);
    }

    /**
     * Words an author wrote for a reader, which is the whole test for whether
     * something belongs in an index.
     */
    public function testCaptionsAndAltTextCountAsWords(): void
    {
        $text = $this->extractor->textFromGrid(['zones' => [
            'picture' => ['blocks' => [], 'alt' => 'La vallée au matin', 'caption' => 'Vue depuis la terrasse'],
        ]]);

        self::assertStringContainsString('La vallée au matin', $text);
        self::assertStringContainsString('Vue depuis la terrasse', $text);
    }

    /**
     * An address is a location rather than prose. Someone searching for
     * "youtube" wants pages about it, not every page that embeds a clip.
     */
    public function testAVideoAddressIsNotIndexed(): void
    {
        $text = $this->extractor->textFromGrid(['zones' => [
            'film' => ['blocks' => [], 'url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'],
        ]]);

        self::assertStringNotContainsString('youtube', $text);
    }

    public function testAGridThatSaysNothingExtractsNothing(): void
    {
        self::assertSame('', $this->extractor->textFromGrid([]));
        self::assertSame('', $this->extractor->textFromGrid(['zones' => []]));
        self::assertSame('', $this->extractor->textFromGrid(['zones' => ['a' => 'pas un tableau']]));
    }
}
