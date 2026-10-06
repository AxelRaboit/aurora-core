<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Security;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qui décide de ce que le client voit dans son espace.
 *
 * **Une seule règle pour tout l'espace**, fixée le 06/10/2026 après l'audit de
 * Studio : une étape du tableau, une ressource, un livrable, un canal de
 * discussion et un fichier naissent cachés au client, et les montrer (ou les
 * cacher) demande le droit `studio.spaces.share`. L'audit en avait trouvé six,
 * chacune avec son défaut et son droit ; un lecteur ne pouvait pas savoir,
 * sans ouvrir la page du client, ce qu'un geste venait de publier.
 *
 * **Le droit de partager, pas celui de modifier.** Montrer quelque chose au
 * client, c'est le lui envoyer : le même geste que lui donner un lien d'accès,
 * donc le même droit. Un équipier qui écrit dans l'espace sans pouvoir le
 * partager prépare, et quelqu'un qui le peut montre.
 *
 * **Three deliberate exceptions**, which this class does not decide because
 * nobody chooses them: a new space shows its review and published steps, the
 * main chat room is always open to the client, and a file the client sent is
 * always visible to them (`AbstractSpaceFile::addedByClient()`, whether it
 * came on a content item or through « Send a file » on their page; the file
 * Manager refuses to hide it).
 *
 * Deux usages, et c'est pourquoi la règle a un nom : `PRIVILEGE` dans un
 * `#[IsGranted]` pour une route qui ne fait que montrer ou cacher, et
 * {@see canShowOrHide()} pour un formulaire qui enregistre tout, visibilité
 * comprise, et ne réclame le droit que si elle change.
 */
final readonly class ClientVisibility
{
    public const string PRIVILEGE = 'studio.spaces.share';

    public function __construct(private Security $security) {}

    public function canShowOrHide(): bool
    {
        return $this->security->isGranted(self::PRIVILEGE);
    }

    /**
     * Un enregistrement qui ferait passer l'élément de `$current` à `$wanted`
     * est-il permis ? Oui s'il ne change rien, ou si la personne a le droit.
     */
    public function allowsChange(bool $current, bool $wanted): bool
    {
        return $current === $wanted || $this->canShowOrHide();
    }
}
