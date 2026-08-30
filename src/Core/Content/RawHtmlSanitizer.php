<?php

declare(strict_types=1);

namespace Aurora\Core\Content;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

use function in_array;

/**
 * Nettoie le HTML du bloc « code source », plus permissif que
 * {@see BlockHtmlSanitizer} mais toujours fermé aux scripts.
 *
 * Deux filtres coexistent parce que deux besoins coexistent. Le texte courant
 * ne doit accepter que ce que la barre d'outils produit : une poignée de balises
 * en ligne, rien d'autre. Le bloc source existe au contraire pour écrire ce que
 * l'éditeur ne sait pas faire, une mise en page, un tableau complexe, un
 * lecteur intégré. Lui appliquer le filtre du texte courant le viderait, et le
 * rendrait donc inutile.
 *
 * Ce qui ne passe jamais, quelle que soit la permissivité :
 *
 * - `<script>`, et `<style>` qui pourrait repeindre toute la page ;
 * - `<form>` et ses champs, qui inviteraient à saisir un mot de passe sur une
 *   page publique sans que rien ne le trahisse ;
 * - `<object>`, `<embed>`, `<link>`, `<meta>`, `<base>` ;
 * - tout attribut `on*`, donc tout gestionnaire d'événement ;
 * - les URL `javascript:`, et les `data:` sauf images.
 *
 * Les `<iframe>` ne sont acceptées que vers les hôtes listés : un cadre vers
 * n'importe où est une page entière qu'on ne contrôle pas, posée dans la
 * sienne.
 */
final class RawHtmlSanitizer
{
    /** Attributs acceptés sur n'importe quelle balise. */
    private const array GLOBAL_ATTRIBUTES = ['class', 'style', 'id', 'title', 'lang', 'dir', 'role'];

    /** Balise => attributs propres, en plus des globaux. */
    private const array ALLOWED = [
        'div' => [], 'section' => [], 'article' => [], 'aside' => [], 'header' => [], 'footer' => [],
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => ['start', 'reversed'], 'li' => ['value'],
        'dl' => [], 'dt' => [], 'dd' => [],
        'blockquote' => ['cite'], 'pre' => [], 'code' => [],
        'span' => [], 'small' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'mark' => [], 'sub' => [], 'sup' => [], 'abbr' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'caption' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'colgroup' => ['span'], 'col' => ['span'],
        'figure' => [], 'figcaption' => [],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        'picture' => [], 'source' => ['src', 'srcset', 'type', 'media'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy'],

        // Sous-ensemble SVG suffisant pour une icone tracee, et rien de plus.
        // Sont volontairement absents : `use`, qui reference un document
        // exterieur ; `foreignObject`, qui reintroduirait du HTML arbitraire au
        // milieu du SVG ; `image`, `style`, et toutes les balises d'animation.
        'svg' => ['viewBox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'xmlns', 'aria-hidden', 'focusable', 'preserveAspectRatio'],
        'g' => ['fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'path' => ['d', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule', 'transform', 'opacity'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'line' => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-linecap', 'transform', 'opacity'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'transform', 'opacity'],
        'polygon' => ['points', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
    ];

    /**
     * Attributs SVG dont la casse compte, remise apres coup.
     *
     * Le parseur HTML de PHP met tous les noms d'attributs en minuscules, ce qui
     * est correct en HTML et faux en SVG : un `viewbox` est ignore par les
     * navigateurs, et l'icone perd son cadrage sans qu'aucune erreur ne le
     * signale. La correction se fait a la serialisation, `setAttribute`
     * reminusculant de toute facon.
     */
    private const array SVG_CASED_ATTRIBUTES = [
        'viewbox' => 'viewBox',
        'preserveaspectratio' => 'preserveAspectRatio',
    ];

    /**
     * Hôtes acceptés dans un `<iframe>`.
     *
     * Volontairement court. Un cadre charge une page entière avec ses propres
     * scripts : la liste doit rester celle des services qu'on a choisi de faire
     * confiance, pas une commodité qu'on élargit au fil des demandes.
     */
    private const array IFRAME_HOSTS = [
        'www.youtube.com', 'www.youtube-nocookie.com', 'youtube.com',
        'player.vimeo.com',
        'www.dailymotion.com',
        'www.google.com',
        'codepen.io',
        'open.spotify.com',
        'w.soundcloud.com',
    ];

    /** Schémas d'URL acceptés hors images. */
    private const array URL_PREFIXES = ['/', '#', 'http://', 'https://', 'mailto:', 'tel:'];

    public function safe(mixed $value): string
    {
        $html = is_string($value) ? mb_trim($value) : '';
        if ('' === $html) {
            return '';
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Le fragment est enveloppé pour que DOMDocument ne lui invente ni
        // <html> ni <body>, et l'entête force l'UTF-8 que le parseur suppose
        // sinon latin-1.
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="aurora-raw">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('aurora-raw');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->clean($root, $document);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        foreach (self::SVG_CASED_ATTRIBUTES as $lowered => $cased) {
            $out = str_replace(' '.$lowered.'=', ' '.$cased.'=', $out);
        }

        return $out;
    }

    private function clean(DOMNode $node, DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        /** @var iterable<DOMElement> $elements */
        $elements = iterator_to_array($xpath->query('.//*', $node) ?: []);

        foreach ($elements as $element) {
            $tag = mb_strtolower($element->nodeName);

            if (!array_key_exists($tag, self::ALLOWED)) {
                // On supprime la balise mais on garde son texte : un lecteur
                // doit obtenir un paragraphe sans mise en forme, pas un
                // paragraphe amputé. Sauf pour les balises dont le contenu n'est
                // pas du texte destiné à être lu.
                $this->unwrapOrRemove($element, $tag);
                continue;
            }

            if ('iframe' === $tag && !$this->allowedFrame($element->getAttribute('src'))) {
                $element->parentNode?->removeChild($element);
                continue;
            }

            $this->cleanAttributes($element, $tag);
        }
    }

    private function unwrapOrRemove(DOMElement $element, string $tag): void
    {
        $parent = $element->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }

        // Le contenu d'un script ou d'une feuille de style n'est pas de la prose :
        // le déballer afficherait du code au lecteur.
        if (in_array($tag, [
            'script', 'style', 'link', 'meta', 'base', 'object', 'embed',
            'form', 'input', 'button', 'select', 'textarea',
            // Cote SVG : `use` pointe ailleurs, `foreignObject` rouvre le HTML,
            // les animations peuvent declencher des comportements.
            'use', 'foreignobject', 'image', 'animate', 'animatetransform', 'animatemotion', 'set', 'script',
        ], true)) {
            $parent->removeChild($element);

            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = [...self::GLOBAL_ATTRIBUTES, ...self::ALLOWED[$tag]];

        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes ?? []);

        foreach ($attributes as $attribute) {
            $name = mb_strtolower($attribute->nodeName);

            if (!in_array($name, array_map(mb_strtolower(...), $allowed), true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if (in_array($name, ['href', 'src', 'cite'], true)) {
                $url = $this->url($attribute->value, 'img' === $tag || 'source' === $tag);
                if (null === $url) {
                    $element->removeAttribute($attribute->nodeName);
                    continue;
                }

                $element->setAttribute($attribute->nodeName, $url);
            }
        }

        // Un lien qui s'ouvre ailleurs sans `rel` laisse la page ouvrante
        // accessible à la cible. On le pose plutôt que de refuser `target`.
        if ('a' === $tag && '' !== $element->getAttribute('target')) {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function allowedFrame(string $src): bool
    {
        $host = parse_url($src, PHP_URL_HOST);

        return is_string($host) && in_array(mb_strtolower($host), self::IFRAME_HOSTS, true);
    }

    private function url(string $value, bool $imageContext): ?string
    {
        $url = mb_trim($value);
        if ('' === $url) {
            return null;
        }

        $lower = mb_strtolower($url);

        // Les images en ligne sont un usage legitime ; les autres `data:`
        // servent surtout a faire passer du script.
        if ($imageContext && str_starts_with($lower, 'data:image/')) {
            return $url;
        }

        foreach (self::URL_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return $url;
            }
        }

        return null;
    }
}
