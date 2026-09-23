<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Enum;

/**
 * L'habillage d'une note : son fond et l'encre qui va avec.
 *
 * **Des apparences déclarées, pas une couleur libre.** Une couleur choisie à
 * la pipette est écrite telle quelle dans mille notes : le jour où le fond
 * du back-office change, elles gardent toutes l'ancien, et personne ne va
 * les reprendre une par une. Une apparence nommée se fait évoluer - on
 * change ce qu'elle veut dire, et les notes qui la portent suivent.
 *
 * C'est le même arbitrage que `DeckThemeEnum`, pour la même raison, et
 * l'inverse de la couleur d'un dossier : là-bas, la valeur ne sert qu'à
 * reconnaître une ligne dans une liste, elle ne dessine pas un écran.
 *
 * `Plain` est ce qu'une note a toujours été, et reste le défaut, pour
 * qu'aucune note ne change d'allure le jour où la colonne apparaît.
 */
enum NoteAppearanceEnum: string
{
    /** Le fond du back-office. Ce à quoi toutes les notes ressemblaient. */
    case Plain = 'plain';

    /** Papier chaud, encre brune. Pour lire longtemps. */
    case Sepia = 'sepia';

    /** Ardoise. Plus sombre que le reste de l'écran, pour s'en détacher. */
    case Slate = 'slate';

    /** Presque blanc, encre noire. Le registre d'un document imprimé. */
    case Paper = 'paper';

    /** Nuit profonde, encre claire. */
    case Midnight = 'midnight';

    /** Vert d'eau très pâle. */
    case Mint = 'mint';

    /**
     * Lit une valeur venue du dehors sans jamais échouer.
     *
     * Une apparence inconnue - une note écrite par une version plus récente,
     * un payload bricolé - vaut le défaut plutôt qu'une exception : une note
     * doit toujours pouvoir s'afficher, quitte à l'être sans habillage.
     */
    public static function fromNullable(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Plain;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
