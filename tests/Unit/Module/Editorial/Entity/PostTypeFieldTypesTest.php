<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Entity;

use Aurora\Module\Editorial\PostType\Entity\AbstractPostTypeField;
use PHPUnit\Framework\TestCase;

/**
 * Same rule as {@see PostTypeSupportsTest}, one screen further on: a field
 * type is only offered if the post editor draws an input for it.
 *
 * `media` and `reference` were in the list while the editor drew neither, so
 * an admin could define a "featured product" field and the writer would be
 * asked to type a raw database id into a text box — the editor's `v-else`
 * fallback. They belong here again when their pickers exist.
 *
 * The vocabulary is asserted whole rather than by absence: adding a type
 * without an input is the mistake, and only an exact list catches it.
 */
final class PostTypeFieldTypesTest extends TestCase
{
    /** The inputs PostEditorApp.vue actually renders, as of this commit. */
    private const array RENDERED_BY_THE_EDITOR = [
        'text',
        'textarea',
        'number',
        'date',
        'select',
        'checkbox',
        'url',
        'email',
    ];

    public function testOffersExactlyTheTypesTheEditorCanDraw(): void
    {
        self::assertSame(self::RENDERED_BY_THE_EDITOR, AbstractPostTypeField::TYPES);
    }

    public function testDoesNotOfferAPickerThatDoesNotExistYet(): void
    {
        self::assertNotContains('media', AbstractPostTypeField::TYPES);
        self::assertNotContains('reference', AbstractPostTypeField::TYPES);
    }
}
