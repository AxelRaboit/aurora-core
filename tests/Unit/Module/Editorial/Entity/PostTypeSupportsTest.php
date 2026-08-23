<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Entity;

use Aurora\Module\Editorial\PostType\Entity\AbstractPostType;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use PHPUnit\Framework\TestCase;

/**
 * `supports` used to default to ['blocks', 'thumbnail', 'excerpt'] while
 * nothing read 'excerpt' - no column held it and the backend had already
 * dropped its checkbox. Every install advertised a capability that did
 * nothing.
 */
final class PostTypeSupportsTest extends TestCase
{
    public function testDefaultsToCapabilitiesThatExist(): void
    {
        self::assertSame(AbstractPostType::SUPPORTS, (new PostType())->getSupports());
        self::assertNotContains('excerpt', (new PostType())->getSupports());
    }

    public function testDropsACapabilityOutsideTheVocabulary(): void
    {
        $postType = (new PostType())->setSupports(['blocks', 'excerpt', 'teleportation']);

        self::assertSame(['blocks'], $postType->getSupports());
    }

    public function testKeepsTheVocabularyOrderRatherThanTheCallerOrder(): void
    {
        $postType = (new PostType())->setSupports(['thumbnail', 'blocks']);

        self::assertSame(['blocks', 'thumbnail'], $postType->getSupports());
    }

    public function testSupportsAnswersOnWhatWasActuallyStored(): void
    {
        $postType = (new PostType())->setSupports(['blocks', 'excerpt']);

        self::assertTrue($postType->supports('blocks'));
        self::assertFalse($postType->supports('excerpt'));
    }
}
