<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Enum;

/**
 * How a customer first came in.
 *
 * A short closed list rather than free text: the point of recording it is to
 * count, later, which channel brings the work, and free text gives five
 * spellings of "word of mouth". Anything else is "other".
 *
 * The values are persisted: add and remove, never rename.
 */
enum CustomerSourceEnum: string
{
    case WebsiteForm = 'website_form';

    case Referral = 'referral';

    case Network = 'network';

    case Event = 'event';

    case SocialMedia = 'social_media';

    case Other = 'other';

    public function getLabelKey(): string
    {
        return 'suite.studio.customers.sources.'.$this->value;
    }
}
