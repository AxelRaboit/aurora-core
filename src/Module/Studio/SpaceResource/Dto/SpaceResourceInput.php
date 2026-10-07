<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Dto;

use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A resource, as the modal sends it.
 *
 * **What is required depends on the kind**, and the callback says so rather
 * than constraints set on each field: a link without an address is not a
 * link, a text without a body is not a text, and yet both fields are
 * optional for the other kinds. Fixed `NotBlank` constraints would have
 * forced filling in a body to save a contact.
 */
class SpaceResourceInput implements SpaceResourceInputInterface
{
    public function __construct(
        public readonly SpaceResourceKindEnum $kind = SpaceResourceKindEnum::Link,
        #[Assert\NotBlank(message: 'suite.studio.space_resources.errors.label_required')]
        #[Assert\Length(max: 180, maxMessage: 'suite.studio.space_resources.errors.label_too_long')]
        public readonly string $label = '',
        // **The two web schemes only**: this field ends up in an `href`, and
        // `javascript:` there would be an address someone managed to save.
        //
        // Without requiring a top-level domain, like the two other addresses typed
        // in the application: a resource kept for yourself can point to an internal
        // machine, and the check that matters here is the scheme.
        #[Assert\Url(message: 'suite.studio.space_resources.errors.url_invalid', protocols: ['http', 'https'], requireTld: false)]
        #[Assert\Length(max: 2048, maxMessage: 'suite.studio.space_resources.errors.url_too_long')]
        public readonly ?string $url = null,
        #[Assert\Length(max: 10000, maxMessage: 'suite.studio.space_resources.errors.body_too_long')]
        public readonly ?string $body = null,
        #[Assert\Email(message: 'suite.studio.space_resources.errors.email_invalid')]
        #[Assert\Length(max: 180, maxMessage: 'suite.studio.space_resources.errors.email_too_long')]
        public readonly ?string $email = null,
        #[Assert\Length(max: 30, maxMessage: 'suite.studio.space_resources.errors.phone_too_long')]
        public readonly ?string $phone = null,
        // Closed by default, down to the input: a request that says nothing about
        // visibility does not publish.
        public readonly bool $visibleToClient = false,
    ) {}

    /** What the kind requires, and that nothing else can set. */
    #[Assert\Callback]
    public function validateKindHasWhatItNeeds(ExecutionContextInterface $context): void
    {
        if ($this->kind->needsUrl() && (null === $this->url || '' === $this->url)) {
            $context->buildViolation('suite.studio.space_resources.errors.url_required')
                ->atPath('url')
                ->addViolation();
        }

        if ($this->kind->needsBody() && (null === $this->body || '' === $this->body)) {
            $context->buildViolation('suite.studio.space_resources.errors.body_required')
                ->atPath('body')
                ->addViolation();
        }
    }

    public function getKind(): SpaceResourceKindEnum
    {
        return $this->kind;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function isVisibleToClient(): bool
    {
        return $this->visibleToClient;
    }
}
