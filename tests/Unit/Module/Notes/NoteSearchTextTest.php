<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes;

use Aurora\Module\Notes\Search\NoteSearchQuery;
use Aurora\Module\Notes\Search\NoteSearchText;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** What the notes search reads, and how it reads what was typed (10/10/2026). */
final class NoteSearchTextTest extends TestCase
{
    public function testFoldsAccentsAndCaseAndHighlightsTheOriginalLetters(): void
    {
        self::assertSame('echeance de l\'ete', NoteSearchText::fold('Échéance de l\'Été'));

        $text = 'La prochaine Échéance, puis une autre échéance.';
        $ranges = NoteSearchText::ranges($text, ['echeance']);

        self::assertCount(2, $ranges);
        self::assertSame('Échéance', mb_substr($text, $ranges[0][0], $ranges[0][1]));
        self::assertSame('échéance', mb_substr($text, $ranges[1][0], $ranges[1][1]));
    }

    public function testCutsPassagesAroundTheMatchesWithTheirHighlights(): void
    {
        $text = str_repeat('mot ', 60).'le budget du chantier de Rezé '.str_repeat('suite ', 60);
        $snippets = NoteSearchText::snippets($text, ['budget', 'reze']);

        self::assertCount(1, $snippets);
        self::assertStringStartsWith('…', $snippets[0]['text']);
        $covered = array_map(static fn (array $range): string => mb_substr($snippets[0]['text'], $range[0], $range[1]), $snippets[0]['ranges']);
        self::assertSame(['budget', 'Rezé'], $covered);
    }

    public function testReadsMarkdownAsTheWordsShown(): void
    {
        $plain = NoteSearchText::plain("# Titre\n\n> [!tip] Astuce\n> Voir [[Contrat type|le contrat]] et @[Marie Dupont](user:3), ==surligné== **gras**.\n\n- [x] Fait\n\n![Photo](/x.jpg)\n[Site](https://exemple.fr)");

        self::assertStringContainsString('Titre', $plain);
        self::assertStringContainsString('Voir le contrat et @Marie Dupont, surligné gras.', $plain);
        self::assertStringContainsString('Fait', $plain);
        self::assertStringContainsString('Photo', $plain);
        self::assertStringContainsString('Site', $plain);
        self::assertStringNotContainsString('https', $plain);
        self::assertStringNotContainsString('[!tip]', $plain);
        self::assertSame(['Titre'], NoteSearchText::headings("# Titre\n```\n# pas un titre\n```\ntexte"));
    }

    public function testReadsWordsPhrasesExclusionsAndFilters(): void
    {
        $query = NoteSearchQuery::parse('budget "chantier de Rezé" -brouillon tag:Client dossier:Clients statut:"En cours" tâche:à-faire a:commentaire modifiée:>2026-09-01 titre:Verrier');

        self::assertSame(['budget'], $query->terms);
        self::assertSame(['chantier de reze'], $query->phrases);
        self::assertSame(['brouillon'], $query->excluded);
        self::assertSame(['client'], $query->tags);
        self::assertSame('clients', $query->folder);
        self::assertSame([['key' => 'statut', 'value' => 'en cours']], $query->properties);
        self::assertSame('todo', $query->task);
        self::assertSame(['comment'], $query->has);
        self::assertEquals(new DateTimeImmutable('2026-09-01'), $query->modifiedAfter);
        self::assertSame(['verrier'], $query->titleTerms);
        self::assertTrue(NoteSearchQuery::parse('   ')->isEmpty());
        self::assertFalse(NoteSearchQuery::parse('tag:client')->hasText());
    }
}
