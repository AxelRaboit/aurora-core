<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * An address of the customer, and the word that says where it leads.
 *
 * An object rather than a pair of strings in an array: that is what lets
 * each row be validated on its own and the error be returned on the right
 * one - `links[2].url` rather than a "one of the links is invalid" that the
 * screen would not know where to place.
 */
class CustomerLinkInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.customers.errors.link_label_required')]
        #[Assert\Length(max: 120, maxMessage: 'suite.studio.customers.errors.link_label_too_long')]
        public readonly string $label = '',
        // `Url` and not a mere length: this field ends up in an `href`, and an
        // address without a scheme reads there as a relative path of Aurora's
        // site. Only the two web schemes, so that `javascript:` is never an
        // address someone could have saved.
        #[Assert\NotBlank(message: 'suite.studio.customers.errors.link_url_required')]
        #[Assert\Url(message: 'suite.studio.customers.errors.link_url_invalid', protocols: ['http', 'https'], requireTld: false)]
        #[Assert\Length(max: 2048, maxMessage: 'suite.studio.customers.errors.link_url_too_long')]
        public readonly string $url = '',
    ) {}
}
