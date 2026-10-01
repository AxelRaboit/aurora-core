<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Contract\Service;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Module\Studio\Contract\Service\ContractDocumentRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Marked values are for a preview, and only for a preview.
 *
 * The preview of a trame colours every value a variable filled in, so the
 * author sees what came from a variable - its examples above all, which are
 * invented. The renderer that seals a contract must never carry the marks: a
 * sealed document with them would not be the document its hash describes.
 */
final class ContractDocumentRendererMarksTest extends TestCase
{
    private const array BLOCKS = [['type' => 'paragraph', 'data' => ['text' => 'Pour {{customer.legal_name}}, SIRET {{customer.siret}}.']]];

    public function testTheRendererThatSealsMarksNothing(): void
    {
        $html = new ContractDocumentRenderer(new BlockHtmlSanitizer())->render(self::BLOCKS, [
            'customer.legal_name' => 'Maison Durand',
            'customer.siret' => '732 829 320 00074',
        ]);

        self::assertStringContainsString('Pour Maison Durand, SIRET 732 829 320 00074.', $html);
        self::assertStringNotContainsString('<mark', $html);
    }

    public function testThePreviewMarksEachFilledValue(): void
    {
        $html = new ContractDocumentRenderer(new BlockHtmlSanitizer())->markingValues()->render(self::BLOCKS, [
            'customer.legal_name' => 'Maison Durand',
            'customer.siret' => '732 829 320 00074',
        ]);

        self::assertStringContainsString('<mark class="contract-variable">Maison Durand</mark>', $html);
        self::assertStringContainsString('<mark class="contract-variable">732 829 320 00074</mark>', $html);
    }

    /** A blank has nothing to colour, and the gap is what the reader must see. */
    public function testAnEmptyValueStaysUnmarked(): void
    {
        $html = new ContractDocumentRenderer(new BlockHtmlSanitizer())->markingValues()->render(self::BLOCKS, [
            'customer.legal_name' => 'Maison Durand',
            'customer.siret' => '',
        ]);

        self::assertStringContainsString('SIRET .', $html);
        self::assertSame(1, mb_substr_count($html, '<mark'));
    }

    /** The mark wraps the escaped value: a value still cannot bring markup. */
    public function testAMarkedValueIsStillEscaped(): void
    {
        $html = new ContractDocumentRenderer(new BlockHtmlSanitizer())->markingValues()->render(self::BLOCKS, [
            'customer.legal_name' => '<script>x</script>',
            'customer.siret' => '1',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Each mark says what its value is, so the preview can colour invented and real apart. */
    public function testEachMarkSaysWhatItsValueIs(): void
    {
        $html = new ContractDocumentRenderer(new BlockHtmlSanitizer())
            ->markingValues(['customer.legal_name' => 'example', 'customer.siret' => 'real'])
            ->render(self::BLOCKS, [
                'customer.legal_name' => 'Maison Durand',
                'customer.siret' => '732 829 320 00074',
            ]);

        self::assertStringContainsString('<mark class="contract-variable contract-variable--example">Maison Durand</mark>', $html);
        self::assertStringContainsString('<mark class="contract-variable contract-variable--real">732 829 320 00074</mark>', $html);
    }

    /** A kind is a word, so nothing else can reach the class attribute. */
    public function testAKindThatIsNotAWordIsLeftOut(): void
    {
        $html = new ContractDocumentRenderer(new BlockHtmlSanitizer())
            ->markingValues(['customer.legal_name' => 'x" onmouseover="alert(1)'])
            ->render(self::BLOCKS, ['customer.legal_name' => 'Maison Durand', 'customer.siret' => '1']);

        self::assertStringNotContainsString('onmouseover', $html);
        self::assertStringContainsString('<mark class="contract-variable">Maison Durand</mark>', $html);
    }
}
