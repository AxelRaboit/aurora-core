<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides;

use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Doctrine\Common\Collections\Collection;

/**
 * Ce qui porte des diapositives : une présentation de Studio, ou un livrable
 * au format diaporama.
 *
 * **Transitoire.** Les présentations deviennent des livrables ; pendant que
 * les deux existent, une diapositive appartient à l'une ou à l'autre, et tout
 * ce qui écrit, dessine ou compte des diapositives (`SlidesManager`,
 * `DeckAppearance`, `DeckPictures`, `DeckFonts`, le sérialiseur) parle à ce
 * contrat plutôt qu'à l'un des deux. Le jour où les présentations
 * disparaissent, seul le livrable l'implémente encore.
 *
 * Le thème et ses retouches s'appellent `slideTheme`/`slideStyle` parce qu'un
 * livrable a déjà son apparence, celle de la page : les deux ne se confondent
 * pas.
 */
interface SlideOwnerInterface
{
    public function getId(): ?int;

    public function getTitle(): string;

    /** @return Collection<int, SlideInterface> */
    public function getSlides(): Collection;

    public function addSlide(SlideInterface $slide): static;

    public function removeSlide(SlideInterface $slide): static;

    /** Le thème dans lequel les diapositives sont dessinées. */
    public function getSlideTheme(): DeckThemeEnum;

    public function setSlideTheme(DeckThemeEnum $theme): static;

    /**
     * Ce que les diapositives retouchent de leur thème, cf. `DeckStyleNormalizer`.
     *
     * @return array<string, mixed>
     */
    public function getSlideStyle(): array;

    /** @param array<string, mixed> $style */
    public function setSlideStyle(array $style): static;
}
