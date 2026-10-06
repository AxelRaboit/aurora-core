<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Enum;

use function is_string;

/**
 * What a deliverable is: a page you scroll, or slides.
 *
 * **Set at creation, never changed afterwards.** A page is composed with the
 * site pages' grid, a slideshow with slides: they are not two displays of
 * the same content but two contents, and switching from one to the other
 * would lose one of them. That is also why this is not the "présentation"
 * display of a page's appearance, which only steps through a page section by
 * section, see `DeliverableAppearance::DISPLAYS`.
 *
 * `Slides` is where Studio presentations go, as they become deliverables: a
 * slideshow is composed with the presentation slide editor, and its reading
 * page shows its slides. It lives in
 * Studio as in a client space, where the client reads it once shown to them.
 */
enum DeliverableFormatEnum: string
{
    case Page = 'page';
    case Slides = 'slides';

    public function labelKey(): string
    {
        return 'suite.studio.deliverables.formats.'.$this->value;
    }

    /**
     * What a form can create today: both, since the slide editor was wired to
     * deliverables. Kept as a stopping point for a future format that would
     * not have an editor yet.
     */
    public function isCreatable(): bool
    {
        return true;
    }

    /** What comes from a form: absent, it is a page; unknown, nothing. */
    public static function fromInput(mixed $value): ?self
    {
        if (null === $value || '' === $value) {
            return self::Page;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }
}
