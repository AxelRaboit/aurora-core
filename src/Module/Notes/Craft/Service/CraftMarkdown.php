<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Service;

use function array_map;
use function explode;
use function implode;
use function mb_ltrim;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function min;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;

/**
 * Le Markdown que rend Craft, remis en Markdown de note.
 *
 * **Presque rien à convertir, et c'est la raison du déménagement.** Les notes
 * d'un espace client étaient écrites en blocs d'éditeur ; il fallait donc
 * traduire chaque ligne de Craft dans une structure qui n'était pas la sienne.
 * Le module Notes écrit en Markdown : ce que Craft rend y entre presque tel
 * quel, et il ne reste que ses propres extensions à défaire.
 *
 * Pur : ni réseau, ni stockage. Les images ne sont pas téléchargées ici, leur
 * adresse sort telle qu'elle est entrée, et {@see CraftNoteImporter} la
 * remplace par celle du fichier rangé dans l'espace de notes.
 */
final readonly class CraftMarkdown
{
    /**
     * Le Markdown de Craft, débarrassé de ses balises, sans le titre du
     * document s'il ouvre le texte.
     */
    public function clean(string $markdown, string $title): string
    {
        $markdown = $this->craftTags(str_replace(["\r\n", "\r"], "\n", $markdown));
        $markdown = $this->withoutRepeatedTitle($markdown, $title);

        // Les balises effacées laissent derrière elles des lignes vides en
        // série : deux suffisent toujours à séparer deux blocs.
        return mb_trim(preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown);
    }

    /**
     * Les balises que Craft ajoute au Markdown, traduites ou dépliées.
     *
     * La liste vient de la spécification que la connexion publie elle-même
     * (`GET /openapi.json`, section « Craft Markdown Extensions »), et non
     * d'une devinette : une page imbriquée, un encadré, un surlignage et un
     * fil de commentaire ont chacun leur balise, et elles arrivent dans le
     * Markdown rendu.
     *
     * **Déplier plutôt que jeter.** Laissées telles quelles, elles
     * ressortiraient en balises au milieu de la note, ce qui est la pire des
     * sorties. Le titre d'une page imbriquée devient donc un titre, un encadré
     * devient l'encadré des notes (`> [!note]`), un surlignage la forme courte
     * que l'aperçu des notes sait lire, et le reste rend son contenu et
     * disparaît.
     *
     * Les renvois internes de Craft - `block://`, `date://`, et le
     * `invalid:out_of_scope` que rend un lien hors de la connexion - perdent
     * leur adresse et gardent leur texte : ce sont des liens qui ne mènent
     * nulle part hors de Craft, et un lien mort dans une note est pire qu'un
     * mot.
     */
    private function craftTags(string $markdown): string
    {
        // **Le retrait, d'abord, et à l'intérieur du `<content>` seulement.**
        // Craft y indente le corps du document, et deux espaces valent chez
        // lui un niveau d'imbrication : une liste à plat arrivait empilée
        // sous sa première entrée. Ici plutôt que sur le document entier,
        // parce que le `<page>` et le `<pageTitle>` restent en colonne zéro -
        // un retrait calculé sur eux vaudrait toujours zéro.
        $markdown = preg_replace_callback(
            '#<content>(.*?)</content>#su',
            fn (array $match): string => "\n".$this->dedent($match[1])."\n",
            $markdown,
        ) ?? $markdown;

        // Le titre d'une page imbriquée, en titre de niveau trois : il est
        // sous le titre de la note, qui est le document lui-même.
        $markdown = preg_replace('#<pageTitle>(.*?)</pageTitle>#su', "\n### $1\n", $markdown) ?? $markdown;

        // Un encadré enveloppe des blocs, lignes vides comprises : chacune de
        // ses lignes passe dans la citation que l'aperçu dessine en encadré.
        // Sans fermeture, la balise est seulement effacée plus bas : un
        // document mal formé ne doit pas avaler tout ce qui le suit.
        $markdown = preg_replace_callback(
            '#<callout>(.*?)</callout>#su',
            fn (array $match): string => "\n".$this->callout($match[1])."\n",
            $markdown,
        ) ?? $markdown;

        // Surlignage : ramené à la forme courte que Craft documente comme son
        // équivalent, et que l'aperçu des notes sait déjà lire.
        $markdown = preg_replace('#<highlight[^>]*>(.*?)</highlight>#su', '==$1==', $markdown) ?? $markdown;

        // Un fil de commentaire est une conversation interne à Craft. Le mot
        // reste, le fil ne suit pas.
        $markdown = preg_replace('#<comment[^>]*>(.*?)</comment>#su', '$1', $markdown) ?? $markdown;

        // La liste des colonnes d'une collection n'est pas du texte : c'est
        // un en-tête de tableau sans son tableau.
        $markdown = preg_replace('#<properties>.*?</properties>#su', '', $markdown) ?? $markdown;

        // Le reste rend son contenu et s'efface.
        $markdown = preg_replace(
            '#</?(?:page|card|content|caption|collection|collectionItem|itemsPreview|property|title|callout)(?:\s[^>]*)?>#u',
            '',
            $markdown,
        ) ?? $markdown;

        return preg_replace('#\[([^\]]*)\]\((?:block|date)://[^)]*\)|\[([^\]]*)\]\(invalid:[^)]*\)#u', '$1$2', $markdown)
            ?? $markdown;
    }

    /** Un encadré de Craft, dans l'écriture des encadrés de notes. */
    private function callout(string $inner): string
    {
        $lines = explode("\n", mb_trim($this->dedent($inner)));

        return "> [!note]\n".implode("\n", array_map(
            static fn (string $line): string => '' === mb_trim($line) ? '>' : '> '.$line,
            $lines,
        ));
    }

    /**
     * Le titre du document, écrit une fois et non deux.
     *
     * Craft enveloppe un document dans une page dont `<pageTitle>` porte son
     * titre, et la conversion en fait un titre de section - ce qui est juste
     * pour une page imbriquée, et redondant pour le document lui-même : la
     * note le porte déjà comme titre. Vu sur le premier import réel, pas sur
     * un exemple.
     *
     * Comparé après normalisation des espaces et de la casse, et seulement en
     * première position : un document qui répète son titre plus bas le fait
     * exprès.
     */
    private function withoutRepeatedTitle(string $markdown, string $title): string
    {
        $text = mb_ltrim($markdown);

        if (1 !== preg_match('/^#{1,6}[ \t]+(.*?)[ \t#]*(?:\n|$)/u', $text, $match)) {
            return $markdown;
        }

        if ($this->normalised($match[1]) !== $this->normalised($title)) {
            return $markdown;
        }

        return mb_substr($text, mb_strlen($match[0]));
    }

    private function normalised(string $text): string
    {
        return mb_strtolower(mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /**
     * Le retrait commun d'un bloc de lignes, et rien de plus.
     *
     * L'imbrication *voulue* survit, puisqu'elle est relative, et un bloc de
     * code garde sa mise en forme, puisque toutes les lignes perdent la même
     * chose.
     */
    private function dedent(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $common = null;

        foreach ($lines as $line) {
            if ('' === mb_trim($line)) {
                continue;
            }

            $indent = mb_strlen($line) - mb_strlen(mb_ltrim($line, ' '));
            $common = null === $common ? $indent : min($common, $indent);
        }

        if (null === $common || 0 === $common) {
            return $markdown;
        }

        return implode("\n", array_map(
            static fn (string $line): string => mb_substr($line, $common),
            $lines,
        ));
    }
}
