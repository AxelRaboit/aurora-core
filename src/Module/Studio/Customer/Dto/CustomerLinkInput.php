<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une adresse du client, et le mot qui dit où elle mène.
 *
 * Un objet plutôt qu'un couple de chaînes dans un tableau : c'est ce qui
 * permet de valider chaque ligne pour elle-même et de rendre l'erreur sur la
 * bonne - `links[2].url` plutôt qu'un « un des liens est invalide » que
 * l'écran ne saurait pas placer.
 */
class CustomerLinkInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.space_information.errors.link_label_required')]
        #[Assert\Length(max: 120, maxMessage: 'backend.studio.space_information.errors.link_label_too_long')]
        public readonly string $label = '',
        // `Url` et non une simple longueur : ce champ finit dans un `href`, et
        // une adresse sans schéma s'y lit comme un chemin relatif du site
        // d'Aurora. Les deux schémas web seulement, pour que `javascript:` ne
        // soit jamais une adresse que quelqu'un a pu enregistrer.
        #[Assert\NotBlank(message: 'backend.studio.space_information.errors.link_url_required')]
        #[Assert\Url(message: 'backend.studio.space_information.errors.link_url_invalid', protocols: ['http', 'https'], requireTld: false)]
        #[Assert\Length(max: 2048, maxMessage: 'backend.studio.space_information.errors.link_url_too_long')]
        public readonly string $url = '',
    ) {}
}
