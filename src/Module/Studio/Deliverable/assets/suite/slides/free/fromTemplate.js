import { newId, round } from "./model.js";
import { FREE_ICONS } from "./icons.js";

/**
 * A laid-out slide, turned into a free one that looks the same.
 *
 * **Read from the slide as it is drawn, not from its slots.** Nineteen layouts
 * each place their words in their own way, and a converter per layout would
 * be nineteen copies of the stylesheet that already knows. The editor has the
 * slide on screen: every title, line, picture and card is a box with a size,
 * a font and a colour the browser has already worked out. This reads those
 * boxes and writes one element for each, in per cent of the frame, so the free
 * slide starts exactly where the laid-out one was.
 *
 * What the frame draws for every slide - the deck's ground and texture, a
 * backdrop, the band, the footer - is left to the frame: the free slide keeps
 * those settings and draws them the same way.
 *
 * Pure DOM reading and no writing, so it is tested on a drawn fixture.
 */

/** Blocks of words, outermost first: a list is one box, not one per line. */
const TEXT_BLOCKS = [
    ".sf-kicker",
    ".sf-title",
    ".sf-subtitle",
    ".sf-section",
    ".sf-ghost",
    ".sf-heading",
    ".sf-quote",
    ".sf-attribution",
    ".sf-list",
    ".sf-columns > p",
    ".sf-stat",
    ".sf-stat-label",
    ".sf-beside-text",
    ".sf-compare-head",
    ".sf-compare-side > p",
    ".sf-figure-value",
    ".sf-figure-label",
    ".sf-agenda > li",
    ".sf-portrait-quote",
    ".sf-portrait-who",
    ".sf-end-lines > span",
    ".sf-card-head",
    ".sf-card-body",
    ".sf-step-head",
    ".sf-step-body",
    ".sf-badge",
    ".sf-caption",
];

/** Boxes that are only paint: a card, a step's dot, a gauge. */
const PAINTED = [
    ".sf-card",
    ".sf-step-mark",
    ".sf-gauge",
    ".sf-gauge > i",
    ".sf-compare-side",
];

/** The slots the frame keeps drawing on a free slide. */
const KEPT_SLOTS = [
    "bgMediaId",
    "bgMediaUrl",
    "bgDim",
    "bgTreatment",
    "bgVeil",
    "vignette",
    "ground",
    "band",
    "transition",
    "drift",
];

/**
 * Any colour the browser computed, as `#rrggbb` or `#rrggbbaa`; null when
 * transparent or unreadable.
 *
 * `rgb()` is read directly; anything else - the `color(srgb …)` a
 * `color-mix()` computes to, an `oklab()` - is painted on a one-pixel canvas
 * and read back, which is the browser converting its own notation.
 */
export function hexOf(css) {
    const value = String(css ?? "").trim();
    const match = value.match(
        /^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,/]+([\d.]+%?))?\s*\)$/i,
    );

    let channels = null;

    if (match) {
        const [, red, green, blue, rawAlpha] = match;
        let alpha = rawAlpha === undefined ? 1 : parseFloat(rawAlpha);

        if (String(rawAlpha).endsWith("%")) alpha /= 100;

        channels = [Number(red), Number(green), Number(blue), alpha];
    } else if (value && value !== "transparent" && value !== "none") {
        channels = painted(value);
    }

    if (!channels || channels[3] === 0) return null;

    const [red, green, blue, alpha] = channels;
    const hex = [red, green, blue]
        .map((part) => Math.round(part).toString(16).padStart(2, "0"))
        .join("");

    return alpha >= 1
        ? `#${hex}`
        : `#${hex}${Math.round(alpha * 255)
              .toString(16)
              .padStart(2, "0")}`;
}

let pixel = null;

function painted(css) {
    if (typeof document === "undefined") return null;

    pixel ??= document
        .createElement("canvas")
        .getContext("2d", { willReadFrequently: true });

    if (!pixel) return null;

    pixel.clearRect(0, 0, 1, 1);
    pixel.fillStyle = "#010203";
    pixel.fillStyle = css;

    if (pixel.fillStyle === "#010203" && css !== "#010203") return null;

    pixel.fillRect(0, 0, 1, 1);

    const [red, green, blue, alpha] = pixel.getImageData(0, 0, 1, 1).data;

    return [red, green, blue, alpha / 255];
}

const normalise = (family) =>
    String(family ?? "")
        .replace(/["']/g, "")
        .replace(/\s+/g, " ")
        .trim()
        .toLowerCase();

/**
 * Convert the drawn slide.
 *
 * @param {HTMLElement} frame the `.slide-frame` of the slide, drawn
 * @param {object} slide the slide, for the ids its pictures point at
 * @param {object} appearance the deck's resolved look
 * @returns {{layout: string, content: object}}
 */
export function freeFromDrawn(frame, slide, appearance) {
    const box = frame.getBoundingClientRect();
    const left = box.left + frame.clientLeft;
    const top = box.top + frame.clientTop;
    const width = frame.clientWidth;
    const height = frame.clientHeight;
    const stage = frame.querySelector(".slide-stage") ?? frame;
    const content = slide.content ?? {};
    const frameStyle = getComputedStyle(frame);

    /** The deck's three colours as they are drawn on this slide's ground. */
    const theme = {
        ink: hexOf(frameStyle.color),
        accent: hexOf(colourOfVariable(frame, "--slide-accent")),
        background: hexOf(colourOfVariable(frame, "--slide-bg")),
    };

    const named = (hex) => {
        if (!hex) return null;

        for (const [name, value] of Object.entries(theme)) {
            if (
                value &&
                value.slice(0, 7) === hex.slice(0, 7) &&
                hex.length === 7
            )
                return name;
        }

        return hex;
    };

    const headingFamily = normalise(appearance?.headingFont).split(",")[0];

    /** A node's box in per cent of the frame. */
    const place = (node) => {
        const rect = node.getBoundingClientRect();

        return {
            x: round(((rect.left - left) / width) * 100),
            y: round(((rect.top - top) / height) * 100),
            w: round((rect.width / width) * 100),
            h: round((rect.height / height) * 100),
        };
    };

    const thousandths = (pixels) =>
        round((parseFloat(pixels) / width) * 1000, 1);

    const elements = [];
    const seen = new Set();

    const texts = new Set(
        TEXT_BLOCKS.flatMap((selector) => [
            ...stage.querySelectorAll(selector),
        ]),
    );
    const paintedBoxes = new Set(
        PAINTED.flatMap((selector) => [...stage.querySelectorAll(selector)]),
    );

    // A list that reveals one line a press keeps doing so: its lines become
    // boxes of their own, each on its press.
    const revealsLines = content.reveal === true;

    if (revealsLines) {
        for (const list of stage.querySelectorAll(".sf-list")) {
            texts.delete(list);

            list.querySelectorAll(":scope > li").forEach((item) =>
                texts.add(item),
            );
        }
    }

    /** Every node of the stage in drawing order, which is the stacking order. */
    const walker = document.createTreeWalker(stage, NodeFilter.SHOW_ELEMENT);

    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if ([...seen].some((outer) => outer.contains(node))) continue;

        const rect = node.getBoundingClientRect();

        if (rect.width < 1 || rect.height < 1) continue;

        elements.push(...pseudoShapesOf(node, rect));

        if (paintedBoxes.has(node)) {
            elements.push(...shapesOf(node, rect));

            // Its words and icons are read on their own, below it.
            continue;
        }

        if (texts.has(node)) {
            const display = getComputedStyle(node).display;

            // Words laid out side by side - a number beside its line in the
            // agenda - are two boxes, not one: a single box would put them
            // one under the other.
            if (/flex|grid/.test(display) && node.children.length > 1) {
                for (const child of node.children) texts.add(child);

                continue;
            }

            seen.add(node);
            elements.push(textOf(node));

            continue;
        }

        if (node.tagName === "IMG") {
            seen.add(node);

            const picture = pictureOf(node);

            if (picture) elements.push(picture);

            continue;
        }

        if (
            node.tagName === "svg" &&
            /\blucide-/.test(node.getAttribute("class") ?? "")
        ) {
            seen.add(node);

            const name = (node
                .getAttribute("class")
                .match(/\blucide-([a-z0-9-]+)/) ?? [])[1];

            if (name && FREE_ICONS[name]) {
                elements.push({
                    id: newId(),
                    type: "icon",
                    icon: name,
                    ...place(node),
                    color:
                        named(hexOf(getComputedStyle(node).color)) ?? "accent",
                    strokeWidth:
                        parseFloat(node.getAttribute("stroke-width")) || 2,
                });
            }

            continue;
        }

        if (node.classList.contains("slide-chart")) {
            seen.add(node);
            elements.push({
                id: newId(),
                type: "chart",
                ...place(node),
                chartType: content.chartType ?? "bar",
                series: content.series ?? [],
            });

            continue;
        }

        if (node.classList.contains("sf-table")) {
            seen.add(node);

            const cell = node.querySelector("td, th");

            elements.push({
                id: newId(),
                type: "table",
                ...place(node),
                rows: content.rows ?? [],
                size: cell ? thousandths(getComputedStyle(cell).fontSize) : 22,
            });
        }
    }

    /**
     * A painted box: its fill, and its borders.
     *
     * Four equal borders are the box's outline. A border on some sides only -
     * the accent rule on top of a card - is a thin bar of its own, since an
     * outline is all four sides or none.
     */
    function shapesOf(node, rect) {
        const style = getComputedStyle(node);
        const fill = hexOf(style.backgroundColor);
        const radius = parseFloat(style.borderTopLeftRadius) || 0;
        const sides = ["Top", "Right", "Bottom", "Left"].map((side) => ({
            side,
            thickness: parseFloat(style[`border${side}Width`]) || 0,
            colour: hexOf(style[`border${side}Color`]),
        }));
        const drawn = sides.filter((side) => side.thickness > 0 && side.colour);
        const outline =
            drawn.length === 4 &&
            drawn.every(
                (side) =>
                    side.thickness === drawn[0].thickness &&
                    side.colour === drawn[0].colour,
            );
        const box = place(node);
        const shapes = [];

        if (fill || outline) {
            shapes.push({
                id: newId(),
                type: "shape",
                shape:
                    radius >= Math.min(rect.width, rect.height) / 2 - 1
                        ? "ellipse"
                        : "rect",
                ...box,
                ...(fill
                    ? { fill: { type: "solid", color: named(fill) } }
                    : {}),
                ...(outline
                    ? {
                          stroke: {
                              color: named(drawn[0].colour),
                              width: thousandths(drawn[0].thickness),
                              style: "solid",
                          },
                      }
                    : {}),
                ...(radius > 0
                    ? { radius: Math.min(500, thousandths(radius)) }
                    : {}),
            });
        }

        if (!outline) {
            for (const { side, thickness, colour } of drawn) {
                const tall = round((thickness / height) * 100);
                const wide = round((thickness / width) * 100);
                const bar =
                    side === "Top" || side === "Bottom"
                        ? {
                              x: box.x,
                              w: box.w,
                              h: tall,
                              y:
                                  side === "Top"
                                      ? box.y
                                      : round(box.y + box.h - tall),
                          }
                        : {
                              y: box.y,
                              h: box.h,
                              w: wide,
                              x:
                                  side === "Left"
                                      ? box.x
                                      : round(box.x + box.w - wide),
                          };

                shapes.push({
                    id: newId(),
                    type: "shape",
                    shape: "rect",
                    ...bar,
                    fill: { type: "solid", color: named(colour) },
                });
            }
        }

        return shapes;
    }

    /**
     * The painted pseudo-elements of a node: the line that joins the steps
     * of a timeline is a `::after`, which no tree walker ever meets.
     *
     * Only the positioned ones with a fill are read, which is how this
     * stylesheet draws furniture; their box is worked out from their offsets
     * inside the node and cut to the frame, since a line drawn a slide wide
     * and clipped by the frame should land as a line that stops at its edge.
     */
    function pseudoShapesOf(node, rect) {
        const shapes = [];

        for (const which of ["::before", "::after"]) {
            const style = getComputedStyle(node, which);

            if (
                !style.content ||
                style.content === "none" ||
                style.display === "none"
            )
                continue;
            if (style.position !== "absolute") continue;

            const fill = hexOf(style.backgroundColor);

            if (!fill) continue;

            const offsetLeft = parseFloat(style.left);
            const offsetTop = parseFloat(style.top);
            const boxWidth = parseFloat(style.width);
            const boxHeight = parseFloat(style.height);

            if (
                ![offsetLeft, offsetTop, boxWidth, boxHeight].every(
                    Number.isFinite,
                )
            )
                continue;

            const startX = Math.max(rect.left + offsetLeft, left);
            const startY = Math.max(rect.top + offsetTop, top);
            const endX = Math.min(
                rect.left + offsetLeft + boxWidth,
                left + width,
            );
            const endY = Math.min(
                rect.top + offsetTop + boxHeight,
                top + height,
            );

            if (endX - startX < 0.5 || endY - startY < 0.5) continue;

            const opacity = parseFloat(style.opacity);

            shapes.push({
                id: newId(),
                type: "shape",
                shape: "rect",
                x: round(((startX - left) / width) * 100),
                y: round(((startY - top) / height) * 100),
                w: round(((endX - startX) / width) * 100),
                h: round(((endY - startY) / height) * 100),
                fill: { type: "solid", color: named(fill) },
                ...(Number.isFinite(opacity) && opacity < 1
                    ? { opacity: round(opacity, 2) }
                    : {}),
            });
        }

        return shapes;
    }

    /**
     * How opaque a node is on screen: its own opacity times every ancestor's
     * up to the stage. The agenda dims the lines that are not the current
     * one on the line itself, and its words, read on their own, would
     * otherwise come out at full strength.
     */
    function seenThrough(node) {
        let opacity = 1;

        for (let at = node; at && at !== stage; at = at.parentElement) {
            const own = parseFloat(getComputedStyle(at).opacity);

            if (Number.isFinite(own)) opacity *= own;
        }

        return opacity;
    }

    /** One block of words, with the look the browser gave it. */
    function textOf(node) {
        const style = getComputedStyle(node);
        const size = parseFloat(style.fontSize);
        const lineHeight = parseFloat(style.lineHeight);
        const family = normalise(style.fontFamily).split(",")[0];
        const colour = named(hexOf(style.color));
        const background = hexOf(style.backgroundColor);
        const at =
            revealsLines && node.tagName === "LI"
                ? [...node.parentNode.children].indexOf(node) + 1
                : 0;
        const spacing = parseFloat(style.letterSpacing);

        return {
            id: newId(),
            type: "text",
            ...place(node),
            // A hair taller than drawn: the same words in the same box wrap the
            // same way, but a rounding of a pixel would otherwise shrink them.
            h: round(place(node).h + 0.6),
            html: wordsOf(node, style, at > 0),
            font: family && family === headingFamily ? "heading" : "body",
            size: thousandths(size),
            weight:
                Math.round((parseInt(style.fontWeight, 10) || 400) / 100) * 100,
            ...(style.fontStyle === "italic" ? { italic: true } : {}),
            ...(Number.isFinite(spacing) && spacing !== 0
                ? { spacing: round((spacing / size) * 1000, 1) }
                : {}),
            ...(parseFloat(style.paddingLeft) > 0
                ? { padX: thousandths(style.paddingLeft) }
                : {}),
            ...(parseFloat(style.paddingTop) > 0
                ? { padY: thousandths(style.paddingTop) }
                : {}),
            lineHeight: Number.isFinite(lineHeight)
                ? round(lineHeight / size, 2)
                : 1.2,
            align: ["center", "right", "justify"].includes(style.textAlign)
                ? style.textAlign
                : "left",
            valign: "top",
            ...(colour && colour !== "ink" ? { color: colour } : {}),
            ...(style.textTransform === "uppercase" ? { case: "upper" } : {}),
            ...(style.textTransform === "lowercase" ? { case: "lower" } : {}),
            ...(background
                ? {
                      fill: { type: "solid", color: named(background) },
                      radius:
                          Math.min(
                              500,
                              thousandths(style.borderTopLeftRadius),
                          ) || undefined,
                  }
                : {}),
            ...(seenThrough(node) < 1
                ? { opacity: round(seenThrough(node), 2) }
                : {}),
            ...(at > 0 ? { reveal: at } : {}),
            autofit: true,
        };
    }

    /**
     * The words of a block as the text box keeps them.
     *
     * Bold and italic stay what they are; any word drawn in another colour than
     * its block - the accent the deck's `==mark==` paints - becomes a span of
     * that colour, which is the one styling a text box keeps.
     */
    function wordsOf(node, style, bulleted) {
        const blockColour = style.color;

        const write = (source) => {
            let html = "";

            for (const child of source.childNodes) {
                if (child.nodeType === Node.TEXT_NODE) {
                    html += escape(child.textContent);
                    continue;
                }

                if (child.nodeType !== Node.ELEMENT_NODE) continue;

                const tag = child.tagName.toLowerCase();
                const childStyle = getComputedStyle(child);
                const inner = write(child);
                const coloured =
                    childStyle.color !== blockColour
                        ? hexOf(childStyle.color)
                        : null;
                const highlighted = hexOf(childStyle.backgroundColor);
                const styles = [
                    coloured ? `color: ${coloured}` : null,
                    highlighted ? `background-color: ${highlighted}` : null,
                ].filter(Boolean);
                const wrapped = [
                    "b",
                    "strong",
                    "i",
                    "em",
                    "u",
                    "s",
                    "br",
                ].includes(tag)
                    ? tag === "br"
                        ? "<br>"
                        : `<${tag}>${inner}</${tag}>`
                    : inner;
                const separated =
                    childStyle.display === "block" && html
                        ? `<br>${wrapped}`
                        : wrapped;

                html += styles.length
                    ? `<span style="${styles.join("; ")}">${separated}</span>`
                    : separated;
            }

            return html;
        };

        if (node.tagName === "UL" || node.tagName === "OL") {
            const items = [...node.children]
                .map((item) => `<li>${write(item)}</li>`)
                .join("");

            return `<${node.tagName.toLowerCase()}>${items}</${node.tagName.toLowerCase()}>`;
        }

        const words = write(node);

        return bulleted
            ? `<span style="color: ${theme.accent ?? "#888888"}">•</span>&nbsp;${words}`
            : words;
    }

    /** A picture, pointed back at the document the slide holds for it. */
    function pictureOf(node) {
        const cell = node.closest(".sf-logos-cell, .sf-mosaic-cell");
        let mediaId = null;

        if (cell) {
            const index = [...cell.parentNode.children].indexOf(cell);

            mediaId = content.mediaIds?.[index] ?? null;
        } else if (node.classList.contains("sf-image-file")) {
            mediaId = content.mediaId ?? null;
        }

        if (!mediaId) return null;

        const style = getComputedStyle(node);
        const holder = node.parentElement
            ? getComputedStyle(node.parentElement)
            : style;
        const radius = Math.max(
            parseFloat(style.borderTopLeftRadius) || 0,
            parseFloat(holder.borderTopLeftRadius) || 0,
        );
        const rect = node.getBoundingClientRect();
        const round_ = radius >= Math.min(rect.width, rect.height) / 2 - 1;

        return {
            id: newId(),
            type: "image",
            ...place(node),
            mediaId,
            mediaUrl: node.getAttribute("src"),
            fit: style.objectFit === "contain" ? "contain" : "cover",
            ...(style.objectPosition && style.objectPosition !== "50% 50%"
                ? { focus: focusOf(style.objectPosition) }
                : {}),
            ...(round_
                ? { mask: "circle" }
                : radius > 0
                  ? { radius: thousandths(radius) }
                  : {}),
        };
    }

    const kept = Object.fromEntries(
        KEPT_SLOTS.filter((slot) => content[slot] !== undefined).map((slot) => [
            slot,
            content[slot],
        ]),
    );

    return {
        layout: "free",
        content: { ...kept, elements: elements.filter(Boolean) },
    };
}

/** A computed `object-position` as the `x% y%` the server keeps. */
function focusOf(position) {
    const parts = position
        .split(/\s+/)
        .map((part) =>
            part.endsWith("%") ? Math.round(parseFloat(part)) : 50,
        );

    return parts.length === 2 ? `${parts[0]}% ${parts[1]}%` : undefined;
}

/** A custom property's colour, resolved by letting the browser paint it. */
function colourOfVariable(frame, name) {
    const probe = document.createElement("span");

    probe.style.color = `var(${name})`;
    probe.style.display = "none";
    frame.appendChild(probe);

    const colour = getComputedStyle(probe).color;

    probe.remove();

    return colour;
}

function escape(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
}
