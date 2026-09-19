<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Service;

use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use stdClass;

use function array_map;
use function array_pop;
use function count;
use function explode;
use function htmlspecialchars;
use function implode;
use function max;
use function mb_ltrim;
use function mb_rtrim;
use function mb_strlen;
use function mb_substr;
use function mb_trim;
use function min;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_replace;
use function str_starts_with;

/**
 * Du Markdown, remis en blocs d'éditeur.
 *
 * **Écrit à la main plutôt qu'emprunté à une bibliothèque**, et c'est un choix.
 * Un analyseur CommonMark rend un arbre ou du HTML ; l'éditeur d'Aurora veut
 * une liste plate de blocs typés, avec du HTML *inline* à l'intérieur. Le
 * chemin passant par une bibliothèque demanderait donc quand même d'écrire le
 * rendu vers les blocs, c'est-à-dire la moitié difficile, et ajouterait une
 * dépendance pour l'autre moitié. Le Markdown qui arrive ici est par ailleurs
 * connu : c'est celui que rend l'API de Craft, pas du Markdown arbitraire.
 *
 * **Ce que l'éditeur accepte décide de tout.** Ses niveaux de titre sont deux,
 * trois et quatre : un `#` de Craft écrit tel quel donnerait un bloc que le
 * back-office refuse d'ouvrir. Les niveaux sont donc ramenés dans cet
 * intervalle plutôt que recopiés. Même raison pour les entrées de liste, qui
 * portent chacune leur `meta` et leurs `items` : {@see EditorBlocks} dit
 * pourquoi, et cette classe s'en sert partout où la forme est la sienne.
 *
 * **Ce qui se perd est nommé.** Un encadré de Craft devient une citation,
 * faute d'outil équivalent monté dans l'éditeur ; une bascule devient son
 * titre suivi de son contenu, parce qu'un bloc qui se replie n'existe pas non
 * plus. Rien n'est jeté en silence : le texte reste, seule la boîte change.
 *
 * Les images ne sont pas téléchargées ici. Cette classe rend l'adresse telle
 * qu'elle l'a lue, et {@see CraftNoteImporter} la remplace par celle du
 * fichier déposé dans l'espace - sans quoi la note pointerait vers un espace
 * Craft privé que le client ne peut pas ouvrir.
 */
final readonly class MarkdownToBlocks
{
    /** Les niveaux que l'éditeur monte, cf. `AppBlockEditor.vue`. */
    private const int MIN_LEVEL = 2;

    private const int MAX_LEVEL = 4;

    /** Le marqueur d'un bloc de code, dans les deux écritures. */
    private const string FENCE = '/^(```|~~~)/';

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function convert(string $markdown): array
    {
        $lines = explode("\n", $this->craftTags(str_replace(["\r\n", "\r"], "\n", $markdown)));
        $blocks = [];
        $paragraph = [];

        $counter = count($lines);

        for ($i = 0; $i < $counter; ++$i) {
            $line = mb_rtrim($lines[$i]);
            $trimmed = mb_trim($line);

            if ('' === $trimmed) {
                $this->flush($paragraph, $blocks);

                continue;
            }

            // Un bloc de code va jusqu'à sa clôture, et rien de ce qu'il
            // contient n'est interprété - c'est tout l'intérêt d'en être un.
            if (preg_match(self::FENCE, $trimmed)) {
                $this->flush($paragraph, $blocks);
                $blocks[] = $this->fence($lines, $i);

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/u', $trimmed, $match)) {
                $this->flush($paragraph, $blocks);
                $blocks[] = EditorBlocks::header(
                    $this->inline(mb_trim($match[2])),
                    $this->level(mb_strlen($match[1])),
                );

                continue;
            }

            if (preg_match('/^(?:---+|\*\*\*+|___+)$/u', $trimmed)) {
                $this->flush($paragraph, $blocks);
                $blocks[] = EditorBlocks::delimiter();

                continue;
            }

            // Une image seule sur sa ligne est un bloc ; la même au milieu
            // d'une phrase reste dans le paragraphe, où elle est une image
            // d'illustration et non une figure.
            if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)$/u', $trimmed, $match)) {
                $this->flush($paragraph, $blocks);
                $blocks[] = EditorBlocks::image($match[2], $this->inline($match[1]));

                continue;
            }

            if (str_starts_with($trimmed, '>') || $this->isCallout($trimmed)) {
                $this->flush($paragraph, $blocks);
                $blocks[] = $this->quote($lines, $i);

                continue;
            }

            if ($this->isListItem($line)) {
                $this->flush($paragraph, $blocks);
                $blocks[] = $this->list($lines, $i);

                continue;
            }

            if ($this->isTableRow($trimmed) && isset($lines[$i + 1]) && $this->isTableRule(mb_trim($lines[$i + 1]))) {
                $this->flush($paragraph, $blocks);
                $blocks[] = $this->table($lines, $i);

                continue;
            }

            $paragraph[] = $trimmed;
        }

        $this->flush($paragraph, $blocks);

        return $blocks;
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
     * **Déplier plutôt que jeter.** Aucune n'a d'équivalent dans l'éditeur
     * d'Aurora ; laissées telles quelles, elles ressortiraient en
     * `&lt;page&gt;` au milieu de la note, ce qui est la pire des sorties.
     * Le titre d'une page imbriquée devient donc un titre, un surlignage
     * devient la forme courte que la conversion sait déjà lire, et le reste
     * rend son contenu et disparaît.
     *
     * Les renvois internes de Craft - `block://`, `date://`, et le
     * `invalid:out_of_scope` que rend un lien hors de la connexion - perdent
     * leur adresse et gardent leur texte : ce sont des liens qui ne mènent
     * nulle part hors de Craft, et un lien mort dans la note d'un client est
     * pire qu'un mot.
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

        // Surlignage : ramené à la forme courte que Craft documente comme son
        // équivalent, et que la conversion en ligne sait déjà lire.
        $markdown = preg_replace('#<highlight[^>]*>(.*?)</highlight>#su', '==$1==', $markdown) ?? $markdown;

        // Un fil de commentaire est une conversation interne à Craft. Le mot
        // reste, le fil ne suit pas.
        $markdown = preg_replace('#<comment[^>]*>(.*?)</comment>#su', '$1', $markdown) ?? $markdown;

        // La liste des colonnes d'une collection n'est pas du texte : c'est
        // un en-tête de tableau sans son tableau.
        $markdown = preg_replace('#<properties>.*?</properties>#su', '', $markdown) ?? $markdown;

        // Le reste rend son contenu et s'efface.
        $markdown = preg_replace(
            '#</?(?:page|card|content|caption|collection|collectionItem|itemsPreview|property|title)(?:\s[^>]*)?>#u',
            '',
            $markdown,
        ) ?? $markdown;

        return preg_replace('#\[([^\]]*)\]\((?:block|date)://[^)]*\)|\[([^\]]*)\]\(invalid:[^)]*\)#u', '$1$2', $markdown)
            ?? $markdown;
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

    /**
     * Les lignes en attente deviennent un paragraphe, et la réserve se vide.
     *
     * Une méthode et non une fermeture sur des références : Rector lisait
     * `[] === $paragraph` au point de définition, où le tableau est
     * effectivement vide, et remplaçait la garde par un `return` sec - ce qui
     * rendait tout le reste du corps mort. Passer par des paramètres retire à
     * l'analyse ce qu'elle croyait savoir.
     *
     * @param list<string>                                          $paragraph
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    private function flush(array &$paragraph, array &$blocks): void
    {
        if ([] === $paragraph) {
            return;
        }

        $text = $this->inline(mb_trim(implode(' ', $paragraph)));
        $paragraph = [];

        if ('' !== $text) {
            $blocks[] = EditorBlocks::paragraph($text);
        }
    }

    /** Deux devient deux, un devient deux, six devient quatre. */
    private function level(int $hashes): int
    {
        return max(self::MIN_LEVEL, min(self::MAX_LEVEL, $hashes));
    }

    /**
     * @param list<string> $lines
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    private function fence(array $lines, int &$i): array
    {
        $fence = mb_trim($lines[$i]);
        $marker = mb_substr($fence, 0, 3);
        $code = [];
        $counter = count($lines);

        for (++$i; $i < $counter; ++$i) {
            if (str_starts_with(mb_trim($lines[$i]), $marker)) {
                break;
            }

            $code[] = $lines[$i];
        }

        // `$i` s'arrête sur la clôture, ou au bout du texte si l'auteur l'a
        // oubliée : dans les deux cas la boucle appelante reprend après.
        return ['type' => 'code', 'data' => ['code' => implode("\n", $code)]];
    }

    /**
     * Craft écrit ses encadrés `<callout>`, l'éditeur n'en monte aucun.
     */
    private function isCallout(string $line): bool
    {
        return str_starts_with($line, '<callout>');
    }

    /**
     * Une citation, ses lignes réunies.
     *
     * @param list<string> $lines
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    private function quote(array $lines, int &$i): array
    {
        $parts = [];
        $counter = count($lines);
        $closesAt = $this->calloutEnd($lines, $i);
        $last = $i;

        for (; $i < $counter; ++$i) {
            $line = mb_trim($lines[$i]);

            if (null === $closesAt) {
                // Une citation se reconnaît ligne à ligne et s'arrête à la
                // première qui ne commence pas par un chevron - la ligne vide
                // comprise, qui sépare deux blocs.
                if (!str_starts_with($line, '>') && !$this->isCallout($line)) {
                    break;
                }

                $line = $this->isCallout($line)
                    ? mb_trim(str_replace(['<callout>', '</callout>'], '', $line))
                    : mb_ltrim(mb_substr($line, 1));
            } else {
                $line = mb_trim(str_replace(['<callout>', '</callout>'], '', $line));
            }

            if ('' !== $line) {
                $parts[] = $line;
            }

            $last = $i;

            if (null !== $closesAt && $i >= $closesAt) {
                break;
            }
        }

        // Sur la dernière ligne consommée : la boucle appelante reprend après.
        $i = $last;

        return EditorBlocks::quote($this->inline(mb_trim(implode(' ', $parts))));
    }

    /**
     * Où se referme l'encadré ouvert à cette ligne, s'il se referme.
     *
     * Un encadré de Craft enveloppe des blocs : il peut porter des lignes
     * vides, que la règle d'une citation prendrait pour une fin. On cherche
     * donc sa fermeture d'abord. **Sans fermeture, il n'est pas traité comme
     * un encadré multiligne** : un document mal formé mangerait tout ce qui
     * le suit, et une note amputée est pire qu'un encadré rendu à plat.
     *
     * @param list<string> $lines
     */
    private function calloutEnd(array $lines, int $from): ?int
    {
        if (!$this->isCallout(mb_trim($lines[$from]))) {
            return null;
        }

        $counter = count($lines);

        for ($line = $from; $line < $counter; ++$line) {
            if (str_contains($lines[$line], '</callout>')) {
                return $line;
            }
        }

        return null;
    }

    private function isListItem(string $line): bool
    {
        return 1 === preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/u', $line);
    }

    private function isTableRow(string $line): bool
    {
        return str_starts_with($line, '|') && str_contains(mb_substr($line, 1), '|');
    }

    private function isTableRule(string $line): bool
    {
        return 1 === preg_match('/^\|[\s:|-]+\|$/u', $line);
    }

    /**
     * Une liste, avec un niveau d'imbrication.
     *
     * **Un seul niveau, et c'est assez.** Une bascule de Craft rend son
     * contenu en enfants indentés de deux espaces : les garder au lieu de les
     * aplatir est ce qui fait qu'un document replié arrive lisible. Au-delà,
     * l'indentation devient une affaire de goût et l'éditeur la rend mal.
     *
     * @param list<string> $lines
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    private function list(array $lines, int &$i): array
    {
        $ordered = null;
        $checklist = false;
        $items = [];
        $counter = count($lines);

        for (; $i < $counter; ++$i) {
            $line = mb_rtrim($lines[$i]);

            if (!$this->isListItem($line)) {
                break;
            }

            preg_match('/^(\s*)(?:([-*+])|(\d+)[.)])\s+(.*)$/u', $line, $match);

            $indent = mb_strlen($match[1]);
            $ordered ??= '' === $match[2];
            $content = $match[4];
            $checked = null;

            if (preg_match('/^\[([ xX])\]\s*(.*)$/u', $content, $box)) {
                $checked = ' ' !== $box[1];
                $checklist = true;
                $content = $box[2];
            }

            $item = [
                'content' => $this->inline(mb_trim($content)),
                'meta' => null === $checked ? new stdClass() : ['checked' => $checked],
                'items' => [],
            ];

            if ($indent >= 2 && [] !== $items) {
                $parent = array_pop($items);
                $parent['items'][] = $item;
                $items[] = $parent;

                continue;
            }

            $items[] = $item;
        }

        --$i;

        $style = $checklist ? 'checklist' : (true === $ordered ? 'ordered' : 'unordered');

        return ['type' => 'list', 'data' => [
            'style' => $style,
            'meta' => new stdClass(),
            'items' => $items,
        ]];
    }

    /**
     * Un tableau, en-tête compris.
     *
     * @param list<string> $lines
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    private function table(array $lines, int &$i): array
    {
        $content = [$this->cells(mb_trim($lines[$i]))];
        $i += 2;
        $counter = count($lines);

        for (; $i < $counter; ++$i) {
            $line = mb_trim($lines[$i]);

            if (!$this->isTableRow($line)) {
                break;
            }

            $content[] = $this->cells($line);
        }

        --$i;

        return ['type' => 'table', 'data' => [
            'withHeadings' => true,
            'content' => $content,
        ]];
    }

    /**
     * @return list<string>
     */
    private function cells(string $row): array
    {
        $inner = mb_trim($row, '|');

        return array_map(
            fn (string $cell): string => $this->inline(mb_trim($cell)),
            explode('|', $inner),
        );
    }

    /**
     * Le balisage d'une ligne, rendu dans le HTML que les blocs acceptent.
     *
     * **Le texte est échappé avant tout le reste.** Ce qui arrive ici vient
     * d'un document que quelqu'un a écrit, et un `<script>` recopié tel quel
     * dans un bloc serait servi à un client. Les balises posées ensuite le
     * sont par ce code seul, et {@see BlockHtmlSanitizer} garde la même liste
     * à l'autre bout.
     *
     * Le code littéral est mis de côté d'abord et remis à la fin : sans cela,
     * deux astérisques dans un extrait de code deviendraient du gras.
     *
     * `ENT_COMPAT` et non `ENT_QUOTES` : le guillemet double est échappé parce
     * qu'une adresse en contenant sortirait de l'attribut `href` écrit plus
     * bas, mais l'apostrophe reste elle-même. Tout le contraire est du texte
     * de contenu, où elle n'a rien à casser, et une note française écrite avec
     * des `&#039;` partout est illisible dans la base comme dans l'éditeur.
     */
    private function inline(string $text): string
    {
        $codes = [];
        $text = preg_replace_callback(
            '/`([^`]+)`/u',
            static function (array $match) use (&$codes): string {
                $codes[] = $match[1];

                return "\u{0}".(count($codes) - 1)."\u{0}";
            },
            $text,
        ) ?? $text;

        $text = htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');

        $text = preg_replace('/\*\*([^*]+)\*\*/u', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/u', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<i>$1</i>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '<i>$1</i>', $text) ?? $text;
        $text = preg_replace('/~~([^~]+)~~/u', '<s>$1</s>', $text) ?? $text;
        $text = preg_replace('/==([^=]+)==/u', '<mark>$1</mark>', $text) ?? $text;

        // Après l'échappement, donc l'adresse est déjà sans guillemet ni
        // chevron ; le sanitiseur refusera de toute façon un schéma qu'il ne
        // connaît pas, `craftdocs://` compris.
        $text = preg_replace(
            '/\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;[^&]*&quot;)?\)/u',
            '<a href="$2">$1</a>',
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '/\x{0}(\d+)\x{0}/u',
            static fn (array $match): string => '<code>'
                .htmlspecialchars($codes[(int) $match[1]], ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8')
                .'</code>',
            $text,
        ) ?? $text;
    }
}
