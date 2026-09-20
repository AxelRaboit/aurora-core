<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Dto;

use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une ressource, telle que la modale l'envoie.
 *
 * **Ce qui est exigé dépend du genre**, et c'est le rappel qui le dit plutôt
 * que des contraintes posées sur chaque champ : un lien sans adresse n'est pas
 * un lien, un texte sans corps n'est pas un texte, et les deux champs sont
 * pourtant facultatifs pour les autres genres. Des `NotBlank` fixes auraient
 * obligé à remplir un corps pour enregistrer un contact.
 */
class SpaceResourceInput implements SpaceResourceInputInterface
{
    public function __construct(
        public readonly SpaceResourceKindEnum $kind = SpaceResourceKindEnum::Link,
        #[Assert\NotBlank(message: 'backend.studio.space_resources.errors.label_required')]
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.space_resources.errors.label_too_long')]
        public readonly string $label = '',
        // **Les deux schémas web seulement** : ce champ finit dans un `href`,
        // et `javascript:` y serait une adresse que quelqu'un a pu enregistrer.
        //
        // Sans exiger de domaine de premier niveau, comme les deux autres
        // adresses saisies dans l'application : une ressource qu'on garde pour
        // soi peut pointer une machine interne, et le contrôle qui compte ici
        // est le schéma.
        #[Assert\Url(message: 'backend.studio.space_resources.errors.url_invalid', protocols: ['http', 'https'], requireTld: false)]
        #[Assert\Length(max: 2048, maxMessage: 'backend.studio.space_resources.errors.url_too_long')]
        public readonly ?string $url = null,
        #[Assert\Length(max: 10000, maxMessage: 'backend.studio.space_resources.errors.body_too_long')]
        public readonly ?string $body = null,
        #[Assert\Email(message: 'backend.studio.space_resources.errors.email_invalid')]
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.space_resources.errors.email_too_long')]
        public readonly ?string $email = null,
        #[Assert\Length(max: 30, maxMessage: 'backend.studio.space_resources.errors.phone_too_long')]
        public readonly ?string $phone = null,
        // Fermé par défaut, jusque dans la saisie : une requête qui ne dit
        // rien de la visibilité ne publie pas.
        public readonly bool $visibleToClient = false,
    ) {}

    /** Ce que le genre exige, et que rien d'autre ne peut poser. */
    #[Assert\Callback]
    public function validateKindHasWhatItNeeds(ExecutionContextInterface $context): void
    {
        if ($this->kind->needsUrl() && (null === $this->url || '' === $this->url)) {
            $context->buildViolation('backend.studio.space_resources.errors.url_required')
                ->atPath('url')
                ->addViolation();
        }

        if ($this->kind->needsBody() && (null === $this->body || '' === $this->body)) {
            $context->buildViolation('backend.studio.space_resources.errors.body_required')
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
