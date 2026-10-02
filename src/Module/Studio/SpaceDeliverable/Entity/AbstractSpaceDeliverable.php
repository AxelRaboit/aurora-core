<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceDeliverable\Service\DeliverableAppearance;
use DateTimeImmutable;
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
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractSpaceDeliverable implements SpaceDeliverableInterface
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

    public function __construct(
        #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected CustomerSpaceInterface $space,
        #[ORM\Column(length: 255)]
        protected string $title,
        /** La langue dans laquelle il est écrit : celle des dates et des libellés de la page. */
        #[ORM\Column(length: 8)]
        protected string $locale
    ) {}

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
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
