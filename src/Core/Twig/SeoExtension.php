<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Builds the SEO/Open Graph/Twitter Cards payload consumed by the public `head.html.twig` partial.
 *
 * Passerelles call `{% do seo({...}) %}` inside the `{% block seo_define %}` of the layout
 * (rendered BEFORE the head include). The function resolves the SEO title template, fills in
 * defaults, absolutizes URLs and normalizes image inputs, then stores the result on the current
 * request so the head partial can read it via `seo_current()`.
 *
 * The block-based dispatch is required because top-level `{% set %}` in a child template
 * does not propagate through a 2-level `extends` chain (e.g. `auth/login → auth/layout → layout`).
 * Blocks always propagate, so a `{% block seo_define %}` override at any level reaches the
 * layout's render flow before the head include.
 *
 * See docs/aurora-core/dev/convention_seo_head.md for the full contract.
 */
final readonly class SeoExtension
{
    private const string REQUEST_ATTRIBUTE = '_aurora_seo';

    public function __construct(
        private Context $context,
        private SettingRepository $settingRepository,
        private DocumentRepository $documentRepository,
        private RequestStack $requestStack,
        private DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    /**
     * @param array<string, mixed> $data
     *
     * @return array{title: string, description: string, image: string, imageAlt: string, canonical: string, type: string, twitterCard: string, twitterHandle: ?string, noindex: bool, extraMeta: string, jsonLd: array<string, mixed>|null}
     */
    #[AsTwigFunction(name: 'seo')]
    public function build(array $data = []): array
    {
        $resolved = $this->resolve($data);

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $resolved);
        }

        return $resolved;
    }

    /**
     * @return array{title: string, description: string, image: string, imageAlt: string, canonical: string, type: string, twitterCard: string, twitterHandle: ?string, noindex: bool, extraMeta: string, jsonLd: array<string, mixed>|null}
     */
    #[AsTwigFunction(name: 'seo_current')]
    public function current(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $stored = $request?->attributes->get(self::REQUEST_ATTRIBUTE);

        if (is_array($stored)) {
            /* @var array{title: string, description: string, image: string, imageAlt: string, canonical: string, type: string, twitterCard: string, twitterHandle: ?string, noindex: bool, extraMeta: string, jsonLd: array<string, mixed>|null} $stored */
            return $stored;
        }

        return $this->resolve([]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{title: string, description: string, image: string, imageAlt: string, canonical: string, type: string, twitterCard: string, twitterHandle: ?string, noindex: bool, extraMeta: string, jsonLd: array<string, mixed>|null}
     */
    private function resolve(array $data): array
    {
        $siteName = $this->context->siteName();
        $siteUrl = $this->context->siteUrl();
        $image = $this->resolveImage($data['image'] ?? null, $siteUrl);

        return [
            'title' => $this->resolveTitle($data['title'] ?? null, $siteName),
            'description' => $this->resolveDescription($data['description'] ?? null),
            'image' => $image,
            // Only when there is an image to describe: `og:image:alt` without
            // an `og:image` describes nothing, and an empty one is worse than
            // absent - it tells a screen reader the picture is decorative when
            // it is the whole preview of a shared link.
            'imageAlt' => '' !== $image && is_string($data['imageAlt'] ?? null)
                ? mb_trim($data['imageAlt'])
                : '',
            'canonical' => $this->resolveCanonical($data['canonical'] ?? null, $siteUrl),
            'type' => is_string($data['type'] ?? null) && '' !== $data['type'] ? $data['type'] : 'website',
            'twitterCard' => is_string($data['twitterCard'] ?? null) && '' !== $data['twitterCard']
                ? $data['twitterCard']
                : ('' !== $image ? 'summary_large_image' : 'summary'),
            'twitterHandle' => $this->settingRepository->get(ApplicationParameterEnum::SeoTwitterHandle->value, '') ?: null,
            'noindex' => (bool) ($data['noindex'] ?? false),
            'extraMeta' => is_string($data['extraMeta'] ?? null) ? $data['extraMeta'] : '',
            'jsonLd' => is_array($data['jsonLd'] ?? null) ? $data['jsonLd'] : null,
        ];
    }

    private function resolveTitle(mixed $title, string $siteName): string
    {
        $title = is_string($title) ? mb_trim($title) : '';

        if ('' === $title || $title === $siteName) {
            return $siteName;
        }

        $template = $this->settingRepository->get(
            ApplicationParameterEnum::SeoTitleTemplate->value,
            ApplicationParameterEnum::SeoTitleTemplate->getDefaultValue(),
        ) ?: ApplicationParameterEnum::SeoTitleTemplate->getDefaultValue();

        return strtr($template, ['{title}' => $title, '{siteName}' => $siteName]);
    }

    private function resolveDescription(mixed $description): string
    {
        $description = is_string($description) ? mb_trim($description) : '';

        if ('' !== $description) {
            return $description;
        }

        $fallback = $this->settingRepository->get(
            ApplicationParameterEnum::SeoDefaultDescription->value,
            '',
        ) ?? '';

        return '' !== $fallback ? $fallback : ($this->context->siteDescription() ?? '');
    }

    private function resolveImage(mixed $image, string $siteUrl): string
    {
        $url = $this->extractUrl($image);

        if ('' === $url) {
            $url = $this->resolveDefaultOgImageUrl();
        }

        return '' === $url ? '' : $this->absolutize($url, $siteUrl);
    }

    private function resolveCanonical(mixed $canonical, string $siteUrl): string
    {
        $url = is_string($canonical) ? mb_trim($canonical) : '';

        if ('' !== $url) {
            return $this->absolutize($url, $siteUrl);
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return mb_rtrim($siteUrl, '/');
        }

        return mb_rtrim($siteUrl, '/').$request->getPathInfo();
    }

    /**
     * Accepts a DocumentInterface entity, a serialized document array (with
     * `publicUrl` key - also recognises the historical Media array shape) or
     * a raw URL string. The historical shape stayed compatible because the
     * `publicUrl` key spans both serializers.
     */
    private function extractUrl(mixed $image): string
    {
        if ($image instanceof DocumentInterface) {
            return (string) $this->documentUrlGenerator->publicUrl($image);
        }

        if (is_array($image) && isset($image['publicUrl']) && is_string($image['publicUrl'])) {
            return $image['publicUrl'];
        }

        return is_string($image) ? mb_trim($image) : '';
    }

    private function resolveDefaultOgImageUrl(): string
    {
        return $this->resolveMediaUrl(ApplicationParameterEnum::SeoDefaultOgImage)
            ?? $this->resolveMediaUrl(ApplicationParameterEnum::LogoMediaId)
            ?? '';
    }

    private function resolveMediaUrl(ApplicationParameterEnum $parameter): ?string
    {
        $rawId = $this->settingRepository->get($parameter->value, '');
        if (null === $rawId || '' === $rawId) {
            return null;
        }

        $documentId = (int) $rawId;
        if ($documentId <= 0) {
            return null;
        }

        return $this->documentUrlGenerator->publicUrl($this->documentRepository->find($documentId));
    }

    private function absolutize(string $url, string $siteUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (!str_starts_with($url, '/')) {
            $url = '/'.$url;
        }

        return mb_rtrim($siteUrl, '/').$url;
    }
}
