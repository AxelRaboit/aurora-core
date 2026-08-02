<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Routing;

use Aurora\Core\Routing\RouteRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The requirement has to accept every placeholder a URL template can carry.
 *
 * A Vue app is handed a path with `__id__`, `__revisionId__`, `__fieldId__`
 * and so on where the value will go. When the requirement does not accept the
 * one a given template uses, the generator throws **while rendering** — the
 * screen answers 500 before anyone reaches the endpoint the requirement was
 * tightening.
 *
 * This went wrong three times, always the same way: someone wrote
 * `\d+|__id__` and it was later copied onto a parameter whose placeholder is
 * spelled differently. Matching the name by hand was the bug, so the pattern
 * stopped trying to.
 */
final class RouteRequirementTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function placeholderProvider(): iterable
    {
        // Every placeholder the templates in this repository actually emit.
        yield 'id' => ['__id__'];
        yield 'revisionId' => ['__revisionId__'];
        yield 'fieldId' => ['__fieldId__'];
        yield 'termId' => ['__termId__'];
        yield 'itemId' => ['__itemId__'];
        yield 'commentId' => ['__commentId__'];
        // And one nobody has written yet, which is the point.
        yield 'a placeholder invented tomorrow' => ['__somethingElseId__'];
    }

    #[DataProvider('placeholderProvider')]
    public function testAcceptsEveryPlaceholderAUrlTemplateCanCarry(string $placeholder): void
    {
        self::assertMatchesPattern($placeholder);
    }

    public function testAcceptsARealId(): void
    {
        self::assertMatchesPattern('1');
        self::assertMatchesPattern('4213');
    }

    /**
     * The requirement still has to disambiguate: `/posts/new` must not be read
     * as `/posts/{id}`, which is what it is there for.
     */
    public function testRejectsSomethingThatIsNeither(): void
    {
        foreach (['new', 'search', 'trash', '', '12a', '__unterminated'] as $value) {
            self::assertSame(
                0,
                preg_match(sprintf('#^(?:%s)$#', RouteRequirement::ID), $value),
                sprintf('"%s" should not be read as an id', $value),
            );
        }
    }

    private static function assertMatchesPattern(string $value): void
    {
        self::assertSame(
            1,
            preg_match(sprintf('#^(?:%s)$#', RouteRequirement::ID), $value),
            sprintf('"%s" should be accepted', $value),
        );
    }
}
