<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deck\Service\DeckStyleNormalizer;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un document écrit pour un client : un audit, une stratégie, un bilan.
 *
 * **Une page, pas une publication.** Il se compose avec la même grille de
 * zones que les pages du site, mais il n'en a ni l'adresse, ni le
 * référencement, ni le cycle brouillon, relecture, publication : il n'est
 * jamais sur le site. Il a été une publication rattachée à un espace jusqu'à
 * la 0.9.321 ; le module éditorial portait alors un identifiant d'espace, des
 * exceptions dans ses listes, son plan du site et son flux, pour un document
 * qui n'avait rien à y faire. Ici il vit à côté de l'espace, et rien dans
 * l'éditorial ne sait qu'il existe.
 *
 * **Une langue, celle du client.** Une page du site se traduit parce que ses
 * lecteurs parlent plusieurs langues ; un livrable a un lecteur. La grille
 * garde donc sa disposition et son contenu côte à côte, sans traductions.
 *
 * **Ce que voit le client se décide par une case**, `visibleToClient`, fermée
 * par défaut : comme une ressource de l'espace, un livrable en cours n'apparaît
 * pas chez le client avant qu'on l'ait décidé. Ce n'est pas un statut : rien
 * n'est programmé, relu ni archivé.
 *
 * **Son apparence lui appartient** : les couleurs du fond, de l'en-tête, du
 * pied, l'accent, les titres et les chiffres clés, par-dessus celles du thème.
 * Un livrable porte souvent les couleurs du client plutôt que celles du
 * studio.
 *
 * **Avec ou sans espace.** Rattaché à un espace client, il vit dans son onglet
 * et c'est l'équipe de l'espace qui le lit. Sans espace (depuis la 1.7.0), il
 * vit dans le module Livrables de Studio : une proposition écrite avant qu'un
 * client existe, une stratégie pour soi, un modèle que l'équipe reprend. Il a
 * alors un auteur et une portée, perso ou partagée, cf.
 * {@see DeliverableScopeEnum}. La case « visible par le client » n'a de sens
 * que dans un espace.
 *
 * **Ce qui n'a de sens que sans espace** : la case
 * « modèle », qui le propose au moment d'en créer un autre, et le client pour
 * qui il a été écrit avant qu'un espace existe. Les deux se taisent dans un
 * espace : l'entité les refuse, et une copie déposée chez un client ne les
 * emporte pas.
 *
 * **Une page ou des diapositives**, cf. {@see DeliverableFormatEnum}. Un
 * diaporama n'a pas de grille : il a ses diapositives, ordonnées, et leur
 * propre apparence (`slideTheme`, `slideStyle`), distincte de celle de la
 * page, parce qu'une diapositive se dessine avec le thème des présentations
 * et non avec les couleurs du site. Une page n'en a aucune, et ces deux
 * colonnes y restent vides.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractDeliverable implements DeliverableInterface
{
    use TimestampableTrait;

    /** La phrase sous le titre, dans la liste du client et en tête de la page. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $summary = null;

    /** La disposition de la grille : `{enabled, snap, reveal, zones}`, comme une page du site. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $gridLayout = [];

    /** Le contenu de ses zones : `{zones: {id: …}}`. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $gridContent = [];

    /**
     * Les couleurs et les choix de présentation de la page, cf.
     * {@see DeliverableAppearance}.
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $appearance = [];

    /** Ce que dit l'en-tête de la page : pour qui, la date, le logo. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $readingHeader = [];

    #[ORM\Column(options: ['default' => false])]
    protected bool $visibleToClient = false;

    /**
     * Qui l'a créé. Nul quand le compte a été supprimé : un livrable partagé
     * reste à l'équipe, un livrable perso revient aux administrateurs.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $owner = null;

    /**
     * Sa catégorie, pour un livrable de Studio ; un livrable d'espace se range
     * par son espace et n'en a pas. Supprimer la catégorie ne supprime rien :
     * le livrable redevient « sans catégorie ».
     */
    #[ORM\ManyToOne(targetEntity: DeliverableCategoryInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DeliverableCategoryInterface $category = null;

    /**
     * Son image, prise dans la médiathèque : la vignette qui le distingue des
     * autres dans une liste. Supprimer le document la retire, rien de plus.
     */
    #[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentInterface $thumbnail = null;

    /** Perso ou partagé, pour un livrable sans espace ; ignoré dans un espace. */
    #[ORM\Column(length: 16, enumType: DeliverableScopeEnum::class, options: ['default' => 'shared'])]
    protected DeliverableScopeEnum $scope = DeliverableScopeEnum::Shared;

    /**
     * Un modèle : un livrable de Studio qui existe pour être recopié, proposé
     * au moment d'en créer un. Un drapeau et pas une table, parce qu'un modèle
     * *est* un livrable : il se compose, s'aperçoit et s'envoie comme les
     * autres. Jamais dans un espace : ce qu'on y dépose est écrit pour un
     * client, pas pour être repris.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $template = false;

    /**
     * Le client pour qui il a été écrit, quand il n'a pas encore d'espace :
     * la proposition faite à un prospect. Dans un espace, c'est l'espace qui
     * dit son client, et ce champ reste vide.
     *
     * `SET NULL` et pas une cascade : supprimer la fiche d'un client ne
     * supprime pas ce qu'on lui a écrit.
     */
    #[ORM\ManyToOne(targetEntity: CustomerInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CustomerInterface $customer = null;

    /**
     * Les diapositives d'un diaporama, dans l'ordre ; une page n'en a pas.
     * Elles n'ont pas de vie hors du livrable : supprimé, il les emporte.
     *
     * @var Collection<int, SlideInterface>
     */
    #[ORM\OneToMany(targetEntity: SlideInterface::class, mappedBy: 'deliverable', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $slides;

    /**
     * Le thème des diapositives ; nul pour une page, et lu « ardoise »
     * (le thème par défaut des présentations) tant qu'on n'en a pas choisi.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: DeckThemeEnum::class)]
    protected ?DeckThemeEnum $slideTheme = null;

    /**
     * Ce que les diapositives retouchent de leur thème, passé au crible de
     * {@see DeckStyleNormalizer} à l'écriture.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $slideStyle = [];

    public function __construct(
        /** L'espace client qui le reçoit ; nul pour un livrable de Studio. */
        #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        protected ?CustomerSpaceInterface $space,
        #[ORM\Column(length: 255)]
        protected string $title,
        /** La langue dans laquelle il est écrit : celle des dates et des libellés de la page. */
        #[ORM\Column(length: 8)]
        protected string $locale,
        /** Une page ou des diapositives : fixé ici, sans setter, cf. {@see DeliverableFormatEnum}. */
        #[ORM\Column(length: 16, enumType: DeliverableFormatEnum::class, options: ['default' => 'page'])]
        protected DeliverableFormatEnum $format = DeliverableFormatEnum::Page,
    ) {
        $this->slides = new ArrayCollection();
    }

    /** @return Collection<int, SlideInterface> */
    public function getSlides(): Collection
    {
        return $this->slides;
    }

    public function addSlide(SlideInterface $slide): static
    {
        if (!$this->slides->contains($slide)) {
            $this->slides->add($slide);
            $slide->setDeliverable($this);
        }

        return $this;
    }

    public function removeSlide(SlideInterface $slide): static
    {
        $this->slides->removeElement($slide);

        return $this;
    }

    public function getSlideTheme(): DeckThemeEnum
    {
        return $this->slideTheme ?? DeckThemeEnum::Slate;
    }

    public function setSlideTheme(DeckThemeEnum $theme): static
    {
        $this->slideTheme = $theme;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSlideStyle(): array
    {
        return $this->slideStyle;
    }

    /** @param array<string, mixed> $style */
    public function setSlideStyle(array $style): static
    {
        $this->slideStyle = $style;

        return $this;
    }

    public function isSlides(): bool
    {
        return DeliverableFormatEnum::Slides === $this->format;
    }

    public function getFormat(): DeliverableFormatEnum
    {
        return $this->format;
    }

    public function isTemplate(): bool
    {
        return $this->template;
    }

    /** Sans effet dans un espace : un livrable d'espace n'est jamais un modèle. */
    public function setTemplate(bool $template): static
    {
        $this->template = $template && $this->isStandalone();

        return $this;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    /** Sans effet dans un espace : c'est l'espace qui dit son client. */
    public function setCustomer(?CustomerInterface $customer): static
    {
        $this->customer = $this->isStandalone() ? $customer : null;

        return $this;
    }

    public function getSpace(): ?CustomerSpaceInterface
    {
        return $this->space;
    }

    public function isStandalone(): bool
    {
        return !$this->space instanceof CustomerSpaceInterface;
    }

    public function getOwner(): ?CoreUserInterface
    {
        return $this->owner;
    }

    public function setOwner(?CoreUserInterface $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getThumbnail(): ?DocumentInterface
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?DocumentInterface $thumbnail): static
    {
        $this->thumbnail = $thumbnail;

        return $this;
    }

    public function getCategory(): ?DeliverableCategoryInterface
    {
        return $this->category;
    }

    public function setCategory(?DeliverableCategoryInterface $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getScope(): DeliverableScopeEnum
    {
        return $this->scope;
    }

    public function setScope(DeliverableScopeEnum $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getGridLayout(): array
    {
        return $this->gridLayout;
    }

    public function setGridLayout(array $gridLayout): static
    {
        $this->gridLayout = $gridLayout;

        return $this;
    }

    public function getGridContent(): array
    {
        return $this->gridContent;
    }

    public function setGridContent(array $gridContent): static
    {
        $this->gridContent = $gridContent;

        return $this;
    }

    public function getAppearance(): array
    {
        return $this->appearance;
    }

    public function setAppearance(array $appearance): static
    {
        $this->appearance = $appearance;

        return $this;
    }

    public function getReadingHeader(): array
    {
        return $this->readingHeader;
    }

    public function setReadingHeader(array $readingHeader): static
    {
        $this->readingHeader = $readingHeader;

        return $this;
    }

    public function isVisibleToClient(): bool
    {
        return $this->visibleToClient;
    }

    public function setVisibleToClient(bool $visibleToClient): static
    {
        $this->visibleToClient = $visibleToClient;

        return $this;
    }

    /**
     * Quand il a été mis à la corbeille ; nul, il est vivant.
     *
     * Une suppression douce, comme celle des notes et des publications : un
     * livrable est un document client écrit à la main, et la suppression
     * définitive d'un audit par erreur ne se rattrape pas. Mis à la corbeille,
     * il sort des listes, de la recherche et des comptes, et ses liens de
     * lecture cessent de répondre ; il garde pourtant ses images (la
     * médiathèque le compte encore) et ses liens, qui reprennent à la
     * restauration. La purge planifiée le détruit au bout du délai commun.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    /**
     * Marque le livrable comme modifié maintenant.
     *
     * Le rappel de Doctrine ne date une modification que si une colonne a
     * changé ; une sauvegarde qui ne touche qu'un lien de lecture, ou qui
     * réécrit la même grille, doit quand même dire « mis à jour aujourd'hui »
     * au client qui revient voir.
     */
    public function touch(): static
    {
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
