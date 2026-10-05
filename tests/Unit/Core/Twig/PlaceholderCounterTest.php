<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Twig;

use Aurora\Core\Twig\PlaceholderCounter;
use PHPUnit\Framework\TestCase;

/**
 * The server twin of the editor's `countPlaceholders`: what is lit in the
 * preview, what the badge counts and what the guard checks are one number.
 */
final class PlaceholderCounterTest extends TestCase
{
    public function testItCountsBlanksInTextAndStripsMarkupFirst(): void
    {
        $counter = new PlaceholderCounter();

        self::assertSame(2, $counter->count('Pour [Nom] le [mois]'));
        self::assertSame(1, $counter->count('un [pas<b>sage</b> coupé]'));
        self::assertSame(0, $counter->count('aucun trou, [] vide, [a<b] avec balise ouverte'));
    }

    public function testItWalksArraysAndNeverReadsKeys(): void
    {
        $counter = new PlaceholderCounter();

        self::assertSame(3, $counter->count([
            '[clé]' => 'texte',
            'a' => ['[un]', ['[deux]'], 12, null, true],
            'b' => '[trois]',
        ]));
    }

    public function testABlankStopsAtTheEndOfTheLine(): void
    {
        self::assertSame(0, new PlaceholderCounter()->count("[sur\ndeux lignes]"));
    }
}
