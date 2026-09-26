<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use Aurora\Module\Editorial\Post\Service\ReadingTimeCalculator;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function implode;

final class ReadingTimeCalculatorTest extends TestCase
{
    private ReadingTimeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ReadingTimeCalculator(new PostTextExtractor());
    }

    public function testAnEmptyGridTakesNoTime(): void
    {
        self::assertSame(0, $this->calculator->minutesFor(['zones' => []]));
    }

    public function testAFewWordsStillRoundUpToOneMinute(): void
    {
        self::assertSame(1, $this->calculator->minutesFor($this->gridOf('Une phrase courte.')));
    }

    /** 200 words per minute, so 400 words should read as two. */
    public function testTwoHundredWordsPerMinute(): void
    {
        $text = implode(' ', array_fill(0, 400, 'mot'));

        self::assertSame(2, $this->calculator->minutesFor($this->gridOf($text)));
    }

    public function testHtmlTagsDoNotCountAsWords(): void
    {
        $withMarkup = $this->calculator->minutesFor($this->gridOf('<p><strong>Un</strong> <em>mot</em>.</p>'));
        $plain = $this->calculator->minutesFor($this->gridOf('Un mot.'));

        self::assertSame($plain, $withMarkup);
    }

    /** @param array<string, mixed> */
    private function gridOf(string $text): array
    {
        return [
            'zones' => [[
                'id' => 'z1',
                'type' => 'text',
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => $text]]],
            ]],
        ];
    }
}
