<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un élément épinglé dans un espace : un lien, un texte, un contact.
 *
 * **Ce que l'espace ne sait pas produire lui-même.** Le tableau porte le
 * travail, les fichiers portent ce qui a été échangé, les notes portent ce que
 * le studio pense. Reste tout ce qui vit ailleurs et qu'on recherche à chaque
 * fois : la maquette dans Canva, le tableau de bord de l'hébergeur, le nom de
 * la personne qui valide chez le client. C'est ce que cette table garde, à
 * l'endroit où on la cherche.
 *
 * **La visibilité est par élément, et fermée par défaut.** C'est la raison
 * d'être de la fonctionnalité autant que la liste elle-même : on range au même
 * endroit ce qui se partage et ce qui ne se partage pas, et c'est une case qui
 * décide, élément par élément. Par défaut fermé, parce qu'une valeur par
 * défaut qui publie se découvre après coup.
 *
 * Le filtre est appliqué là où les éléments sont lus, jamais à l'affichage :
 * un élément caché ne sort pas du serveur pour le client. Filtrer dans la page
 * aurait fait d'une confidence une préférence d'affichage.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractSpaceResource implements SpaceResourceInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    /**
     * Ce que la ligne est, et ce qui décide du reste.
     *
     * Voir {@see SpaceResourceKindEnum} : le genre commande ce qui est exigé,
     * ce qui est validé et la façon dont la ligne se dessine.
     */
    #[ORM\Column(length: 20, enumType: SpaceResourceKindEnum::class)]
    protected SpaceResourceKindEnum $kind;

    /** Le mot qu'on lit dans la liste, quel que soit le genre. */
    #[ORM\Column(length: 180)]
    protected string $label;

    /**
     * L'adresse, pour un lien.
     *
     * 2048 parce que c'est ce qu'une adresse partagée fait réellement : un
     * document Google ou une vue Canva porte un identifiant long et des
     * paramètres, et une colonne de 255 les aurait tronqués en silence.
     */
    #[ORM\Column(length: 2048, nullable: true)]
    protected ?string $url = null;

    /** Le corps, pour un texte ; une précision, pour les autres genres. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $body = null;

    /** L'adresse d'un contact, quand la ligne en est un. */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $email = null;

    /** Son téléphone. */
    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $phone = null;

    /**
     * Si le client voit cette ligne.
     *
     * Fermé par défaut : voir l'en-tête de la classe.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $visibleToClient = false;

    /**
     * L'ordre choisi, et non l'ordre d'arrivée.
     *
     * Une liste de ressources est une liste qu'on range : le lien qu'on ouvre
     * chaque jour remonte en haut. Trier par date de création aurait imposé
     * l'inverse - le dernier ajouté devant, qui est rarement le plus utile.
     */
    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    abstract public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(CustomerSpaceInterface $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getKind(): SpaceResourceKindEnum
    {
        return $this->kind;
    }

    public function setKind(SpaceResourceKindEnum $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
