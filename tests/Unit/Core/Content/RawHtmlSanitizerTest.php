<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Content;

use Aurora\Core\Content\RawHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ce filtre est la seule chose entre le HTML qu'un auteur écrit à la main et la
 * page que voient les visiteurs. Une faille ici ne se voit pas : elle s'exécute.
 *
 * Les cas ci-dessous sont donc écrits comme des tentatives, pas comme des
 * exemples.
 */
final class RawHtmlSanitizerTest extends TestCase
{
    private RawHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new RawHtmlSanitizer();
    }

    #[DataProvider('attacks')]
    public function testWhatMustNeverSurvive(string $html, string $mustNotContain, string $why): void
    {
        self::assertStringNotContainsStringIgnoringCase(
            $mustNotContain,
            $this->sanitizer->safe($html),
            $why,
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function attacks(): iterable
    {
        yield 'script' => ['<p>ok</p><script>alert(1)</script>', 'script', 'le cas d\'école'];
        yield 'script imbriqué' => ['<div><span><script>alert(1)</script></span></div>', 'alert', 'la profondeur ne protège pas'];
        yield 'gestionnaire onclick' => ['<div onclick="alert(1)">x</div>', 'onclick', 'tout attribut on* part'];
        yield 'gestionnaire onerror' => ['<img src="x" onerror="alert(1)">', 'onerror', 'le classique de l\'image cassée'];
        yield 'gestionnaire onload' => ['<iframe src="https://www.youtube.com/e" onload="alert(1)"></iframe>', 'onload', 'même sur une balise autorisée'];
        yield 'url javascript' => ['<a href="javascript:alert(1)">x</a>', 'javascript:', 'le schéma est refusé'];
        yield 'url javascript en majuscules' => ['<a href="JaVaScRiPt:alert(1)">x</a>', 'javascript:', 'la casse ne contourne rien'];
        yield 'data non-image' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:text', 'seules les images data: passent'];
        yield 'balise style' => ['<style>body{display:none}</style><p>x</p>', 'display:none', 'repeindre toute la page est refusé'];
        yield 'formulaire' => ['<form action="/x"><input name="pass"></form>', '<input', 'pas de saisie sur une page publique'];
        yield 'object' => ['<object data="x.swf"></object>', '<object', 'plugin arbitraire'];
        yield 'iframe hôte inconnu' => ['<iframe src="https://evil.example/x"></iframe>', 'evil.example', 'un cadre est une page entière'];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">', 'refresh', 'redirection silencieuse'];
        yield 'base' => ['<base href="https://evil.example/">', '<base', 'détournerait toutes les URL relatives'];
    }

    public function testTextSurvivesEvenWhenItsTagDoesNot(): void
    {
        // Un lecteur doit obtenir un mot sans mise en forme, pas un mot manquant.
        self::assertStringContainsString('important', $this->sanitizer->safe('<marquee>important</marquee>'));
    }

    public function testAScriptBodyIsNotUnwrappedIntoThePage(): void
    {
        // La règle précédente a une exception : déballer un script afficherait
        // son code au lecteur.
        self::assertStringNotContainsString('alert', $this->sanitizer->safe('<script>alert(1)</script>'));
    }

    public function testTheLayoutTheBlockExistsForIsKept(): void
    {
        $html = '<figure class="grid"><table class="t"><thead><tr><th colspan="2" scope="col">A</th></tr></thead>'
            .'<tbody><tr><td>1</td><td>2</td></tr></tbody></table><figcaption>Légende</figcaption></figure>';

        $out = $this->sanitizer->safe($html);

        foreach (['<figure', 'class="grid"', '<table', 'colspan="2"', 'scope="col"', '<figcaption'] as $expected) {
            self::assertStringContainsString($expected, $out, 'sans ça le bloc ne sert à rien');
        }
    }

    public function testAnAllowedFrameIsKeptWithItsAttributes(): void
    {
        $out = $this->sanitizer->safe('<iframe src="https://player.vimeo.com/video/1" width="640" allowfullscreen></iframe>');

        self::assertStringContainsString('player.vimeo.com', $out);
        self::assertStringContainsString('width="640"', $out);
    }

    public function testAnInlineImageIsAllowedAsDataUri(): void
    {
        $out = $this->sanitizer->safe('<img src="data:image/png;base64,iVBORw0KGgo=" alt="x">');

        self::assertStringContainsString('data:image/png', $out);
    }

    public function testALinkOpeningElsewhereIsGivenItsRel(): void
    {
        // Sans `rel`, la page ouverte garde une poignée sur celle qui l'ouvre.
        // On le pose plutôt que de refuser `target`.
        $out = $this->sanitizer->safe('<a href="https://example.test" target="_blank">x</a>');

        self::assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    #[DataProvider('empties')]
    public function testNothingInNothingOut(mixed $value): void
    {
        self::assertSame('', $this->sanitizer->safe($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function empties(): iterable
    {
        yield 'chaîne vide' => [''];
        yield 'espaces' => ['   '];
        yield 'null' => [null];
        yield 'entier' => [42];
        yield 'tableau' => [[]];
    }

    public function testAccentedTextIsNotMangled(): void
    {
        // DOMDocument suppose du latin-1 quand rien ne le detrompe.
        $out = $this->sanitizer->safe('<p>Été à Genève, où l\'on écrit çà et là</p>');

        self::assertStringContainsString('Été à Genève', $out);
        self::assertStringContainsString('çà et là', $out);
    }
}
