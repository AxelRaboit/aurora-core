<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Enum;

use function is_string;

/**
 * Ce qu'est un livrable : une page qu'on fait défiler, ou des diapositives.
 *
 * **Fixé à la création, jamais changé ensuite.** Une page se compose avec la
 * grille des pages du site, un diaporama avec des diapositives : ce ne sont pas
 * deux affichages du même contenu mais deux contenus, et passer de l'un à
 * l'autre perdrait l'un des deux. C'est aussi pourquoi ce n'est pas l'affichage
 * « présentation » de l'apparence d'une page, qui fait seulement passer une
 * page section par section, cf. `DeliverableAppearance::DISPLAYS`.
 *
 * `Slides` est la place des présentations de Studio, qui deviennent des
 * livrables : la valeur existe dès maintenant pour que la colonne n'ait plus à
 * changer, mais aucun écran ne crée encore un diaporama, cf.
 * {@see self::isCreatable()}.
 */
enum DeliverableFormatEnum: string
{
    case Page = 'page';
    case Slides = 'slides';

    public function labelKey(): string
    {
        return 'suite.studio.deliverables.formats.'.$this->value;
    }

    /**
     * Ce qu'un formulaire peut créer aujourd'hui.
     *
     * Un diaporama sans son éditeur serait un document qu'on ne peut ni
     * composer ni lire : la création le refuse tant que l'éditeur de
     * diapositives n'est pas branché sur les livrables.
     */
    public function isCreatable(): bool
    {
        return self::Page === $this;
    }

    /** Ce qui arrive d'un formulaire : absent, c'est une page ; inconnu, rien. */
    public static function fromInput(mixed $value): ?self
    {
        if (null === $value || '' === $value) {
            return self::Page;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }
}
