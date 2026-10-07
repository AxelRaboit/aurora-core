<?php

declare(strict_types=1);

namespace Aurora\Core\Locale\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Applies the French elision to every French translation, once the
 * parameters are in: see FrenchElision.
 *
 * Decorates the translator rather than a Twig filter, so the templates, the
 * emails and the flash messages all get it without anyone having to think of
 * it. Everything else is passed through untouched.
 */
#[AsDecorator(decorates: 'translator')]
final readonly class FrenchElisionTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    public function __construct(
        #[AutowireDecorated]
        private TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
    ) {}

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $translated = $this->inner->trans($id, $parameters, $domain, $locale);

        if ([] === $parameters || !str_starts_with($locale ?? $this->inner->getLocale(), 'fr')) {
            return $translated;
        }

        return FrenchElision::apply($translated);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }
}
