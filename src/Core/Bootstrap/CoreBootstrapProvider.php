<?php

declare(strict_types=1);

namespace Aurora\Core\Bootstrap;

use Aurora\Core\Locale\Entity\Locale;
use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Configuration\Theme\Entity\Theme;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The always-on core's own floor: the active locales, and a theme to render in.
 *
 * Both used to be created by AppFixtures, which never runs in production.
 * Missing locales are the severe half — Context::activeLocaleCodes() comes back
 * empty, assertActiveLocale() throws, and every frontend URL answers 404 on a
 * site that otherwise looks correctly installed.
 */
final readonly class CoreBootstrapProvider implements BootstrapProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function getPriority(): int
    {
        return 100;
    }

    public function bootstrap(): iterable
    {
        yield from $this->seedLocales();
        yield from $this->seedDefaultTheme();

        $this->entityManager->flush();
    }

    /**
     * One row per supported locale, French default — matching what LocaleEnum
     * declares, so the enum stays the single list of what Aurora speaks.
     *
     * Matched on code: an administrator may rename "Français" or reorder the
     * switcher, and a deploy must not undo that.
     *
     * @return iterable<string>
     */
    private function seedLocales(): iterable
    {
        $repository = $this->entityManager->getRepository(Locale::class);
        $position = 0;

        foreach (LocaleEnum::cases() as $case) {
            ++$position;

            if (null !== $repository->findOneBy(['code' => $case->value])) {
                continue;
            }

            $locale = new Locale()
                ->setCode($case->value)
                ->setName($this->localeName($case))
                ->setIsDefault(LocaleEnum::default() === $case)
                ->setPosition($position - 1);

            $this->entityManager->persist($locale);

            yield sprintf('locale %s', $case->value);
        }
    }

    /**
     * ThemeResolver falls back to the bundle's templates when no theme is
     * active, so this is not load-bearing — but an empty themes screen on a
     * fresh install reads as something being broken.
     *
     * @return iterable<string>
     */
    private function seedDefaultTheme(): iterable
    {
        $repository = $this->entityManager->getRepository(Theme::class);

        if (null !== $repository->findOneBy(['slug' => 'default'])) {
            return;
        }

        // Only activated when it is the first theme: re-running this on an
        // install that has since switched to a custom theme must not drag the
        // site back to the default look.
        $isFirst = 0 === $repository->count([]);

        $this->entityManager->persist(
            new Theme()
                ->setSlug('default')
                ->setName('Default')
                ->setDescription('Thème par défaut de Aurora')
                ->setActive($isFirst),
        );

        yield 'thème default';
    }

    private function localeName(LocaleEnum $case): string
    {
        return match ($case) {
            LocaleEnum::French => 'Français',
            LocaleEnum::English => 'English',
        };
    }
}
