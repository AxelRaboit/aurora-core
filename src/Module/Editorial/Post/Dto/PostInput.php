<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Dto;

use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PostInput implements PostInputInterface
{
    /**
     * @param array<string, PostTranslationInput> $translations
     * @param list<int>                           $termIds
     * @param list<int>                           $relatedPostIds
     * @param array<string, mixed>                $bannerLayout   raw; normalised at the write boundary by BannerNormalizer
     */
    public function __construct(
        #[Assert\Positive(message: 'backend.posts.errors.post_type_required')]
        public readonly int $postTypeId,
        #[Assert\NotBlank(message: 'backend.posts.errors.status_required')]
        #[Assert\Choice(callback: [PostStatusEnum::class, 'values'], message: 'backend.posts.errors.status_invalid')]
        public readonly string $status,
        public readonly ?int $thumbnailId,
        public readonly array $termIds,
        public readonly array $translations,
        public readonly array $relatedPostIds = [],
        public readonly ?string $scheduledAt = null,
        /** When it should come down. Free of the status, unlike `scheduledAt`. */
        public readonly ?string $unpublishAt = null,
        public readonly ?int $version = null,
        public readonly bool $force = false,
        public readonly bool $commentsEnabled = true,
        public readonly bool $titleVisible = true,
        public readonly array $bannerLayout = [],
        public readonly array $gridLayout = [],
        public readonly array $galleryLayout = [],
        public readonly string $thumbnailFit = 'cover',
        public readonly ?float $thumbnailFocalX = null,
        public readonly ?float $thumbnailFocalY = null,
        public readonly ?string $headerColor = null,
        public readonly ?string $footerColor = null,
        public readonly ?string $backgroundColor = null,
    ) {}

    public function withStatus(string $status): PostInputInterface
    {
        return new self(
            postTypeId: $this->postTypeId,
            status: $status,
            thumbnailId: $this->thumbnailId,
            termIds: $this->termIds,
            translations: $this->translations,
            relatedPostIds: $this->relatedPostIds,
            scheduledAt: $this->scheduledAt,
            unpublishAt: $this->unpublishAt,
            version: $this->version,
            force: $this->force,
            commentsEnabled: $this->commentsEnabled,
            titleVisible: $this->titleVisible,
            bannerLayout: $this->bannerLayout,
            gridLayout: $this->gridLayout,
            galleryLayout: $this->galleryLayout,
            thumbnailFit: $this->thumbnailFit,
            thumbnailFocalX: $this->thumbnailFocalX,
            thumbnailFocalY: $this->thumbnailFocalY,
            headerColor: $this->headerColor,
            footerColor: $this->footerColor,
            backgroundColor: $this->backgroundColor,
        );
    }

    public function getPostTypeId(): int
    {
        return $this->postTypeId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getThumbnailId(): ?int
    {
        return $this->thumbnailId;
    }

    public function getThumbnailFit(): string
    {
        return $this->thumbnailFit;
    }

    public function getThumbnailFocalX(): ?float
    {
        return $this->thumbnailFocalX;
    }

    public function getThumbnailFocalY(): ?float
    {
        return $this->thumbnailFocalY;
    }

    public function getTermIds(): array
    {
        return $this->termIds;
    }

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getRelatedPostIds(): array
    {
        return $this->relatedPostIds;
    }

    public function getScheduledAt(): ?string
    {
        return $this->scheduledAt;
    }

    public function getUnpublishAt(): ?string
    {
        return $this->unpublishAt;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function isForce(): bool
    {
        return $this->force;
    }

    public function isCommentsEnabled(): bool
    {
        return $this->commentsEnabled;
    }

    public function isTitleVisible(): bool
    {
        return $this->titleVisible;
    }

    public function getBannerLayout(): array
    {
        return $this->bannerLayout;
    }

    public function getGridLayout(): array
    {
        return $this->gridLayout;
    }

    /** @return array<string, mixed> */
    public function getGalleryLayout(): array
    {
        return $this->galleryLayout;
    }

    public function getHeaderColor(): ?string
    {
        return $this->headerColor;
    }

    public function getFooterColor(): ?string
    {
        return $this->footerColor;
    }

    public function getBackgroundColor(): ?string
    {
        return $this->backgroundColor;
    }

    /**
     * A scheduled post needs a date, and one in the future - otherwise the
     * publisher would either never fire or fire on its next tick, which is
     * not what "schedule" means to the editor who set it.
     */
    #[Assert\Callback]
    public function validateScheduling(ExecutionContextInterface $context): void
    {
        if (PostStatusEnum::Scheduled->value !== $this->status) {
            return;
        }

        if (null === $this->scheduledAt) {
            $context->buildViolation('backend.posts.errors.scheduled_at_required')
                ->atPath('scheduledAt')
                ->addViolation();

            return;
        }

        try {
            $date = new DateTimeImmutable($this->scheduledAt);
        } catch (Exception) {
            $context->buildViolation('backend.posts.errors.scheduled_at_invalid')
                ->atPath('scheduledAt')
                ->addViolation();

            return;
        }

        if ($date <= new DateTimeImmutable()) {
            $context->buildViolation('backend.posts.errors.scheduled_at_in_past')
                ->atPath('scheduledAt')
                ->addViolation();
        }
    }

    /**
     * An end date has to be a date, and has to be in the future.
     *
     * Not tied to a status, unlike `scheduledAt`: setting "comes down on the 30th"
     * on a post that is live today is the ordinary way to use it, and requiring a
     * particular status first would mean setting the date after publishing and
     * hoping to remember.
     */
    #[Assert\Callback]
    public function validateUnpublishAt(ExecutionContextInterface $context): void
    {
        if (null === $this->unpublishAt) {
            return;
        }

        try {
            $date = new DateTimeImmutable($this->unpublishAt);
        } catch (Exception) {
            $context->buildViolation('backend.posts.errors.unpublish_at_invalid')
                ->atPath('unpublishAt')
                ->addViolation();

            return;
        }

        if ($date <= new DateTimeImmutable()) {
            $context->buildViolation('backend.posts.errors.unpublish_at_in_past')
                ->atPath('unpublishAt')
                ->addViolation();
        }
    }
}
