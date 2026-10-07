<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Enum;

/**
 * What gets pinned in a space.
 *
 * **Three kinds and not a free field**, because the kind decides what is
 * asked for, what is validated and how the row is drawn: a link opens, a
 * text is read, a contact is dialled. A single generic kind would have given
 * a list of titles with a content that would have had to be guessed at
 * display time.
 *
 * There is deliberately no "credentials" kind. A password filed in a client
 * space is a plain-text password in a database, exported in the backups and
 * shown to whoever opens the screen; the missing field is what keeps the
 * habit from forming. A password manager does that, and the link to the
 * vault is, for its part, a link.
 *
 * The values are persisted: we add and remove, we do not rename.
 */
enum SpaceResourceKindEnum: string
{
    case Link = 'link';

    case Text = 'text';

    case Contact = 'contact';

    public function getLabelKey(): string
    {
        return match ($this) {
            self::Link => 'suite.studio.space_resources.kinds.link',
            self::Text => 'suite.studio.space_resources.kinds.text',
            self::Contact => 'suite.studio.space_resources.kinds.contact',
        };
    }

    /** Whether this kind carries an address, and so whether it is required. */
    public function needsUrl(): bool
    {
        return self::Link === $this;
    }

    /** Whether this kind carries a body, and so whether it is required. */
    public function needsBody(): bool
    {
        return self::Text === $this;
    }
}
