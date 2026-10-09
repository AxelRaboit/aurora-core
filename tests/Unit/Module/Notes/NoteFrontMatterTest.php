<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes;

use Aurora\Module\Notes\Markdown\Service\NoteFrontMatter;
use PHPUnit\Framework\TestCase;

/** The block at the top of an exported or imported note (09/10/2026). */
final class NoteFrontMatterTest extends TestCase
{
    public function testWritesTagsEmojiAndPropertiesTheObsidianWay(): void
    {
        $file = NoteFrontMatter::write(
            ['photo', 'méthode'],
            '🚀',
            [
                ['key' => 'Statut', 'type' => 'status', 'value' => 'Validé'],
                ['key' => 'Échéance', 'type' => 'date', 'value' => '2026-10-12'],
                ['key' => 'Pour', 'type' => 'person', 'value' => 3, 'label' => 'Marie Dupont'],
            ],
            'Du texte.',
        );

        self::assertStringStartsWith("---\ntags: [photo, méthode]\nicon: 🚀\nStatut: Validé\n", $file);
        self::assertStringContainsString('Pour: \'Marie Dupont\'', $file);
        self::assertStringEndsWith("---\n\nDu texte.", $file);
    }

    public function testANoteWithNothingAboveItsTextHasNoBlock(): void
    {
        self::assertSame('Du texte.', NoteFrontMatter::write([], null, [], 'Du texte.'));
    }

    public function testReadsBackWhatItWrote(): void
    {
        $file = NoteFrontMatter::write(['photo'], '🚀', [
            ['key' => 'Statut', 'type' => 'status', 'value' => 'Validé'],
            ['key' => 'Échéance', 'type' => 'date', 'value' => '2026-10-12'],
            ['key' => 'Fait', 'type' => 'checkbox', 'value' => true],
            ['key' => 'Heures', 'type' => 'number', 'value' => 3],
        ], "# Titre\n\nDu texte.");

        $read = NoteFrontMatter::read($file);

        self::assertSame(['photo'], $read['tags']);
        self::assertSame('🚀', $read['icon']);
        self::assertSame("# Titre\n\nDu texte.", $read['content']);
        self::assertSame([
            ['key' => 'Statut', 'type' => 'text', 'value' => 'Validé'],
            ['key' => 'Échéance', 'type' => 'date', 'value' => '2026-10-12'],
            ['key' => 'Fait', 'type' => 'checkbox', 'value' => true],
            ['key' => 'Heures', 'type' => 'number', 'value' => 3],
        ], $read['properties']);
    }

    /** Obsidian's own front matter, unquoted date included, comes in as properties. */
    public function testReadsAnObsidianNote(): void
    {
        $read = NoteFrontMatter::read("---\ntags:\n  - client\naliases: [Verrier]\ndue: 2026-10-12\nsource: https://example.com\n---\nTexte");

        self::assertSame(['client'], $read['tags']);
        self::assertSame([
            ['key' => 'due', 'type' => 'date', 'value' => '2026-10-12'],
            ['key' => 'source', 'type' => 'url', 'value' => 'https://example.com'],
        ], $read['properties']);
        self::assertSame('Texte', $read['content']);
    }

    public function testLeavesABlockThatIsNotYamlInTheText(): void
    {
        $raw = "---\nkey: [unclosed, list\n---\nTexte";

        self::assertSame($raw, NoteFrontMatter::read($raw)['content']);
    }
}
