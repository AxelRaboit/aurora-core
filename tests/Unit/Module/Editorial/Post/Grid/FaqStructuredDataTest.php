<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Module\Editorial\Post\Grid\FaqStructuredData;
use PHPUnit\Framework\TestCase;

/**
 * A folding list is already a list of questions with their answers, so the
 * page tells search engines about it without anyone typing the pairs twice.
 */
final class FaqStructuredDataTest extends TestCase
{
    private FaqStructuredData $faq;

    protected function setUp(): void
    {
        $this->faq = new FaqStructuredData();
    }

    public function testAFoldingListBecomesAnFaqPage(): void
    {
        $data = $this->faq->fromGrid(['zones' => [
            $this->zone('faq', [['title' => 'Faut-il s\'engager ?', 'description' => 'Trois mois, puis sans durée.']]),
        ]]);

        self::assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [[
                '@type' => 'Question',
                'name' => 'Faut-il s\'engager ?',
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Trois mois, puis sans durée.'],
            ]],
        ], $data);
    }

    public function testOtherListsAndHalfEntriesAreLeftOut(): void
    {
        $data = $this->faq->fromGrid(['zones' => [
            $this->zone('steps', [['title' => 'Découverte', 'description' => 'Un premier échange.']]),
            $this->zone('faq', [
                ['title' => 'Sans réponse', 'description' => ''],
                ['title' => '', 'description' => 'Sans question'],
                ['title' => 'Complète', 'description' => 'Oui.'],
            ]),
        ]]);

        self::assertNotNull($data);
        self::assertSame(['Complète'], array_column($data['mainEntity'], 'name'));
    }

    public function testAQuestionInsideAStackCounts(): void
    {
        $data = $this->faq->fromGrid(['zones' => [
            ['type' => 'stack', 'items' => null, 'children' => [
                $this->zone('faq', [['title' => 'Imbriquée ?', 'description' => 'Oui.']]),
            ]],
        ]]);

        self::assertNotNull($data);
        self::assertSame('Imbriquée ?', $data['mainEntity'][0]['name']);
    }

    public function testAPageWithoutQuestionsSaysNothing(): void
    {
        self::assertNull($this->faq->fromGrid(null));
        self::assertNull($this->faq->fromGrid(['zones' => [$this->zone('steps', [['title' => 'A', 'description' => 'B']])]]));
    }

    public function testTheAuthorsBlockAndTheQuestionsBothGoOut(): void
    {
        $authored = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Aurora'];
        $faq = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];

        self::assertSame($faq, $this->faq->combine(null, $faq));
        self::assertSame($authored, $this->faq->combine($authored, null));
        self::assertSame([$authored, $faq], $this->faq->combine($authored, $faq));
        self::assertSame([$authored, $authored, $faq], $this->faq->combine([$authored, $authored], $faq));
    }

    public function testAHandWrittenFaqPageIsNotDoubled(): void
    {
        $authored = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];

        self::assertSame($authored, $this->faq->combine($authored, ['@type' => 'FAQPage', 'mainEntity' => []]));
    }

    /**
     * @param list<array{title: string, description: string}> $entries
     *
     * @return array<string, mixed>
     */
    private function zone(string $display, array $entries): array
    {
        return ['type' => 'items', 'items' => ['display' => $display, 'entries' => $entries], 'children' => []];
    }
}
