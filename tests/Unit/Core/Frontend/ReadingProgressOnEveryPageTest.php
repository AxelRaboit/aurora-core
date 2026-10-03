<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Frontend;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function mb_substr_count;

/**
 * The reading bar belongs to the site, not to one kind of page.
 *
 * It used to be mounted by the publication template alone, so the list of a
 * content type (`/fr/services`) and the page of a taxonomy term scrolled
 * without it, right next to pages that had it: it read as a page forgotten,
 * not as a choice. It now lives in the layout every public page extends, and
 * mounting it a second time anywhere would draw two bars on top of each other.
 */
final class ReadingProgressOnEveryPageTest extends TestCase
{
    private const string THEME = __DIR__.'/../../../../src/Core/templates/Frontend/themes/default';

    private const string COMPONENT = "vue_component('editorial/frontend/ReadingProgress'";

    public function testTheLayoutMountsTheBarOnce(): void
    {
        self::assertSame(1, mb_substr_count($this->read('layout.html.twig'), self::COMPONENT));
    }

    public function testThePublicationTemplateNoLongerMountsItsOwn(): void
    {
        self::assertSame(0, mb_substr_count($this->read('editorial/post/index.html.twig'), self::COMPONENT));
    }

    private function read(string $template): string
    {
        $content = file_get_contents(self::THEME.'/'.$template);
        self::assertIsString($content);

        return $content;
    }
}
