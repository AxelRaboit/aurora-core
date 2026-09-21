<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Enum;

use Aurora\Module\Studio\Deck\Enum\DeckFontPairEnum;

/**
 * La famille dans laquelle un thème compose toute l'application.
 *
 * **Une liste close, et pas un champ libre.** La CSP d'Aurora pose
 * `font-src 'self' data:` : une police appelée par son nom seul n'arrive
 * jamais, elle est résolue par le système du visiteur ou pas du tout. Les
 * familles proposées ici sont donc celles qu'`app.css` embarque, une par
 * paquet `@fontsource`, et un champ où on taperait un nom produirait des sites
 * composés dans une police que personne n'a. C'est la même raison que pour
 * {@see DeckFontPairEnum}, à une autre échelle.
 *
 * **Cinq, choisies pour se distinguer les unes des autres.** Une géométrique,
 * une neutre d'interface, une grotesque, une arrondie et une à empattements :
 * le choix ne vaut que si passer de l'une à l'autre se voit.
 *
 * Ajouter une famille veut dire trois gestes qui vont ensemble : le cas ici,
 * ses six graisses importées dans `app.css`, et son paquet en dépendance.
 */
enum ThemeFontEnum: string
{
    /** Géométrique. La police historique d'Aurora, et son défaut. */
    case Poppins = 'poppins';

    /** Neutre, dessinée pour les écrans. Se fait oublier. */
    case Inter = 'inter';

    /** Grotesque, un peu plus large et plus chaleureuse qu'Inter. */
    case WorkSans = 'work-sans';

    /** Arrondie. Le ton le moins institutionnel de la liste. */
    case Nunito = 'nunito';

    /** À empattements. Fait lire la page comme un texte plutôt qu'un écran. */
    case Lora = 'lora';

    /**
     * Les piles de secours servent deux fois : le temps que le fichier arrive,
     * et pour toujours si la requête échoue.
     */
    private const string SANS_FALLBACK = 'ui-sans-serif, system-ui, sans-serif';

    private const string SERIF_FALLBACK = 'ui-serif, Georgia, "Times New Roman", serif';

    /**
     * La famille qu'une configuration de thème désigne, ou le défaut.
     *
     * Tolère tout ce que `Theme::config` peut contenir : la colonne est un
     * JSON libre, une clé peut y avoir été écrite à la main ou avoir survécu à
     * la suppression d'un cas, et un écran composé dans Poppins vaut mieux
     * qu'une page d'erreur.
     */
    public static function fromConfig(mixed $raw): self
    {
        return is_string($raw) ? (self::tryFrom($raw) ?? self::default()) : self::default();
    }

    public static function default(): self
    {
        return self::Poppins;
    }

    /**
     * La valeur CSS complète, telle qu'elle est posée sur `--th-font-sans`.
     *
     * Le nom de la famille est celui que déclarent les `@font-face` de
     * `@fontsource`, guillemets compris : « Work Sans » ne se résout pas sans
     * eux.
     */
    public function stack(): string
    {
        return match ($this) {
            self::Poppins => "'Poppins', ".self::SANS_FALLBACK,
            self::Inter => "'Inter', ".self::SANS_FALLBACK,
            self::WorkSans => "'Work Sans', ".self::SANS_FALLBACK,
            self::Nunito => "'Nunito', ".self::SANS_FALLBACK,
            self::Lora => "'Lora', ".self::SERIF_FALLBACK,
        };
    }

    /**
     * Le nom de la famille, qui est un nom propre : il ne se traduit pas et
     * ne passe donc pas par le catalogue.
     */
    public function label(): string
    {
        return match ($this) {
            self::Poppins => 'Poppins',
            self::Inter => 'Inter',
            self::WorkSans => 'Work Sans',
            self::Nunito => 'Nunito',
            self::Lora => 'Lora',
        };
    }

    /** La ligne qui dit à quoi la famille ressemble, elle traduite. */
    public function descriptionKey(): string
    {
        return 'backend.themes.fonts.'.$this->value;
    }

    /**
     * De quoi peupler le sélecteur du back-office : la valeur stockée, le nom
     * affiché, la clé de description et la pile, pour que l'aperçu se compose
     * dans la police proposée sans que le JavaScript redéclare les piles.
     *
     * @return list<array{value: string, label: string, descriptionKey: string, stack: string}>
     */
    public static function choices(): array
    {
        return array_map(
            static fn (self $font): array => [
                'value' => $font->value,
                'label' => $font->label(),
                'descriptionKey' => $font->descriptionKey(),
                'stack' => $font->stack(),
            ],
            self::cases(),
        );
    }
}
