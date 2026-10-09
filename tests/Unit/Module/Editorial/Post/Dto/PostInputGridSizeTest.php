<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Dto;

use Aurora\Module\Editorial\Post\Dto\PostInput;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

use function array_fill;

/**
 * A page with more zones than allowed is refused, not cut. The normaliser
 * keeps the first zones only, so a save past the cap went through and the end
 * of the page vanished without a word (09/10/2026).
 */
final class PostInputGridSizeTest extends TestCase
{
    public function testAPageAtTheCapIsAccepted(): void
    {
        self::assertSame([], $this->errors(GridNormalizer::MAX_ZONES));
    }

    public function testAPagePastTheCapIsRefusedOnTheGrid(): void
    {
        self::assertSame(['gridLayout' => 'suite.posts.errors.too_many_zones'], $this->errors(GridNormalizer::MAX_ZONES + 1));
    }

    /** @return array<string, string> */
    private function errors(int $zoneCount): array
    {
        $input = new PostInput(
            postTypeId: 1,
            status: 'draft',
            thumbnailId: null,
            termIds: [],
            translations: [],
            gridLayout: ['zones' => array_fill(0, $zoneCount, ['type' => 'text'])],
        );

        $errors = [];
        foreach (Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($input) as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }
}
