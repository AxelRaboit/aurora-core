<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes;

use Aurora\Module\Notes\Share\Service\WikiLinkParser;
use PHPUnit\Framework\TestCase;

/** Every form of link names its note the same way the editor reads it. */
final class WikiLinkParserTest extends TestCase
{
    public function testTheTargetLeavesTheShownTextAndThePlace(): void
    {
        self::assertSame('Cabinet Verrier', WikiLinkParser::targetOf(' Cabinet Verrier '));
        self::assertSame('Cabinet Verrier', WikiLinkParser::targetOf('Cabinet Verrier#Le forfait'));
        self::assertSame('Cabinet Verrier', WikiLinkParser::targetOf('Cabinet Verrier|le client'));
        self::assertSame('Cabinet Verrier', WikiLinkParser::targetOf('Cabinet Verrier#^abc|ici'));
    }

    public function testEveryFormCountsAsALink(): void
    {
        $body = "[[Un]], [[Deux#Partie]], [[Trois|texte]], [[Quatre#^id]] et\n\n![[Cinq]]";

        self::assertSame(['un', 'deux', 'trois', 'quatre', 'cinq'], new WikiLinkParser()->titlesIn($body));
    }
}
