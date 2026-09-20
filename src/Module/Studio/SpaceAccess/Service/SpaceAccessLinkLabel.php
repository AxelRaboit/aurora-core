<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Service;

use Aurora\Module\Studio\SpaceAccess\Dto\SpaceAccessLinkInput;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;

use function explode;
use function mb_convert_case;
use function mb_trim;
use function str_replace;

use const MB_CASE_TITLE;

/**
 * Le nom sous lequel un invité apparaît.
 *
 * **Jamais son adresse.** Elle signait ses messages, ses commentaires et ses
 * fichiers ; sur un espace qui compte plusieurs liens, chaque invité lisait
 * donc les adresses des autres, sans l'avoir demandé ni pouvoir l'empêcher.
 *
 * Le libellé est obligatoire à l'émission d'un lien depuis
 * {@see SpaceAccessLinkInput}, donc le
 * cas normal est une seule ligne. Le repli existe pour les liens émis avant
 * cette règle : il tire un nom de la partie gauche de l'adresse plutôt que de
 * laisser un message anonyme, parce que deux invités sans nom dans un même fil
 * ne se distinguent plus.
 *
 * Une classe plutôt qu'une méthode sur l'entité du lien : trois entités s'en
 * servent, et c'est la troisième copie d'une règle qui apprend qu'elle n'a pas
 * sa place dans chacune.
 */
final readonly class SpaceAccessLinkLabel
{
    public static function of(SpaceAccessLinkInterface $link): string
    {
        $label = mb_trim((string) $link->getLabel());

        if ('' !== $label) {
            return $label;
        }

        return self::fromEmail($link->getRecipientEmail());
    }

    /**
     * « camille.perrot@… » devient « Camille Perrot ».
     *
     * Le domaine ne sort pas : il nomme l'entreprise, et c'est la partie de
     * l'adresse qu'on lit le plus vite dans un fil.
     */
    private static function fromEmail(string $email): string
    {
        $local = explode('@', $email)[0];
        $words = mb_trim(str_replace(['.', '_', '-', '+'], ' ', $local));

        return '' === $words ? 'Invité' : mb_convert_case($words, MB_CASE_TITLE);
    }
}
