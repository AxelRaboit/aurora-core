/**
 * The free slide's elements: how they are made, and how they become CSS.
 *
 * **Two units and no pixel.** A position is in per cent of the slide - `x` of
 * its width, `y` of its height - and every other length (a font size, a
 * stroke, a corner, a shadow) is in thousandths of the slide's width. The
 * frame is a container, so both resolve against the slide itself: the same
 * element is right on a 160-pixel thumbnail, in the editor and on the wall.
 * `FreeSlideNormalizer` enforces the same contract on the server.
 *
 * **The order of the list is the stacking order**: the first element is at
 * the back. Nothing here carries a `z`.
 */

/** The slide's height over its width. Every frame in this module is 16:9. */
export const RATIO = 9 / 16;

/** A length in thousandths of the slide's width, as CSS. */
export const cssLength = (value) => `${(Number(value) || 0) / 10}cqw`;

/** The deck's three colours, by the name an element stores. */
const THEME = {
    ink: "var(--slide-ink)",
    accent: "var(--slide-accent)",
    background: "var(--slide-bg)",
};

/** A stored colour as CSS: the deck's own by name, or a hexadecimal. */
export function colour(value, fallback = "currentColor") {
    if (!value) return fallback;

    return THEME[value] ?? value;
}

/**
 * A paint as a CSS background.
 *
 * Null for no paint, so the caller can leave the property out entirely rather
 * than setting it to something transparent that still covers what is below.
 */
export function paint(value) {
    if (!value) return null;

    if (value.type === "solid") return colour(value.color);

    const stops = (value.stops ?? [])
        .map((stop) => `${colour(stop.color)} ${stop.at ?? 0}%`)
        .join(", ");

    if (!stops) return null;

    if (value.type === "radial")
        return `radial-gradient(circle at 50% 50%, ${stops})`;

    return `linear-gradient(${value.angle ?? 180}deg, ${stops})`;
}

/** A shadow as a `drop-shadow()`, which follows a cut-out shape. */
export function shadow(value) {
    if (!value?.color) return null;

    return `drop-shadow(${cssLength(value.x)} ${cssLength(value.y)} ${cssLength(value.blur)} ${colour(value.color)})`;
}

/** A picture's adjustments as one `filter` value. */
export function filters(value) {
    if (!value) return null;

    const parts = [];

    if (value.brightness != null)
        parts.push(`brightness(${value.brightness}%)`);
    if (value.contrast != null) parts.push(`contrast(${value.contrast}%)`);
    if (value.saturate != null) parts.push(`saturate(${value.saturate}%)`);
    if (value.grayscale != null) parts.push(`grayscale(${value.grayscale}%)`);
    if (value.sepia != null) parts.push(`sepia(${value.sepia}%)`);
    if (value.hue != null) parts.push(`hue-rotate(${value.hue}deg)`);
    if (value.blur != null) parts.push(`blur(${value.blur / 10}cqw)`);

    return parts.length ? parts.join(" ") : null;
}

/**
 * What a picture or a film is cut to.
 *
 * Polygons in per cent of the box, so they stretch with it the way the
 * picture does. The circle is an ellipse on a box that is not square, which
 * is what anybody resizing one expects.
 */
const MASKS = {
    circle: "ellipse(50% 50% at 50% 50%)",
    arch: "inset(0 0 0 0 round 50% 50% 0 0 / 35% 35% 0 0)",
    triangle: "polygon(50% 0, 100% 100%, 0 100%)",
    diamond: "polygon(50% 0, 100% 50%, 50% 100%, 0 50%)",
    hexagon: "polygon(25% 0, 75% 0, 100% 50%, 75% 100%, 25% 100%, 0 50%)",
    star: "polygon(50% 0, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%)",
    blob: "inset(0 round 62% 38% 55% 45% / 45% 55% 45% 55%)",
};

export const mask = (value) => MASKS[value] ?? null;

/** The deck's two families by role, else a family of the catalogue. */
export function fontFamily(key, families = {}) {
    if (!key || key === "body") return "var(--slide-body)";
    if (key === "heading") return "var(--slide-heading)";

    return families[key] ?? "var(--slide-body)";
}

/** A short id, unique enough on one slide and valid on the server. */
export function newId() {
    return `e${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36).slice(-3)}`;
}

/** Rounded to what a person can tell apart, and what the server keeps. */
export const round = (value, places = 3) => {
    const factor = 10 ** places;

    return Math.round(value * factor) / factor;
};

export const clamp = (value, low, high) => Math.min(Math.max(value, low), high);

/**
 * A new element of the given type, centred, at a sensible size.
 *
 * The sizes are what a person would draw first: a title wide and short, a
 * picture a third of the slide, a shape a square. The square is square on the
 * wall, which means taller in per cent of a height than in per cent of a
 * width, because the slide is wider than it is tall.
 */
export function createElement(type, overrides = {}) {
    const square = (side) => ({ w: side, h: side / RATIO });

    const base = {
        text: {
            w: 60,
            h: 14,
            html: "",
            font: "body",
            size: 40,
            weight: 400,
            lineHeight: 1.2,
            align: "left",
            valign: "top",
            autofit: true,
        },
        image: { ...square(30), fit: "cover" },
        video: { w: 40, h: 40, fit: "cover", controls: true },
        embed: { w: 48, h: 48 },
        shape: {
            ...square(18),
            shape: "rect",
            fill: { type: "solid", color: "accent" },
        },
        icon: { ...square(10), icon: "star", color: "accent", strokeWidth: 2 },
        chart: {
            w: 56,
            h: 56,
            chartType: "bar",
            series: ["Janvier|12", "Février|18", "Mars|26"],
        },
        table: {
            w: 60,
            h: 40,
            rows: ["Offre|Prix", "Présence|1 000 €", "Croissance|1 500 €"],
            size: 22,
        },
    }[type] ?? { w: 30, h: 20 };

    const element = { id: newId(), type, ...base, ...overrides };

    if (overrides.x == null) element.x = round(50 - element.w / 2);
    if (overrides.y == null) element.y = round(50 - element.h / 2);

    return element;
}

/**
 * The box an element covers once turned, in per cent of the slide.
 *
 * Measured in a square space - the slide's width as the unit on both axes -
 * because a rotation in per cent of two different lengths is not a rotation.
 */
export function boundsOf(element) {
    const angle = ((element.rotate ?? 0) * Math.PI) / 180;
    const width = element.w;
    const height = element.h * RATIO;
    const cos = Math.abs(Math.cos(angle));
    const sin = Math.abs(Math.sin(angle));
    const turnedWidth = width * cos + height * sin;
    const turnedHeight = width * sin + height * cos;
    const centreX = element.x + element.w / 2;
    const centreY = element.y + element.h / 2;

    return {
        left: centreX - turnedWidth / 2,
        right: centreX + turnedWidth / 2,
        top: centreY - turnedHeight / RATIO / 2,
        bottom: centreY + turnedHeight / RATIO / 2,
        centreX,
        centreY,
    };
}

/** The smallest box around several elements. */
export function unionOf(elements) {
    if (!elements.length) return null;

    const boxes = elements.map(boundsOf);
    const left = Math.min(...boxes.map((box) => box.left));
    const right = Math.max(...boxes.map((box) => box.right));
    const top = Math.min(...boxes.map((box) => box.top));
    const bottom = Math.max(...boxes.map((box) => box.bottom));

    return {
        left,
        right,
        top,
        bottom,
        centreX: (left + right) / 2,
        centreY: (top + bottom) / 2,
    };
}

/**
 * The elements a selection really covers: the ones picked, and everything in
 * their groups. A group is selected whole or not at all, as in any editor
 * where a logo made of three shapes has to move as one.
 */
export function withGroups(elements, ids) {
    const groups = new Set(
        elements
            .filter((element) => ids.includes(element.id) && element.group)
            .map((element) => element.group),
    );

    return elements
        .filter(
            (element) =>
                ids.includes(element.id) ||
                (element.group && groups.has(element.group)),
        )
        .map((element) => element.id);
}

/**
 * A slide's elements moved by a list of ids, keeping everyone else in place.
 *
 * Used for forward and back: the moved elements keep their order among
 * themselves, and step past one element that is not moving, which is what a
 * person pressing "forward" once expects to see.
 */
export function restack(elements, ids, direction) {
    const list = [...elements];
    const moving = new Set(ids);

    if (direction === "front") {
        return [
            ...list.filter((element) => !moving.has(element.id)),
            ...list.filter((element) => moving.has(element.id)),
        ];
    }

    if (direction === "back") {
        return [
            ...list.filter((element) => moving.has(element.id)),
            ...list.filter((element) => !moving.has(element.id)),
        ];
    }

    const step = direction === "forward" ? 1 : -1;
    const order = step > 0 ? [...list.keys()].reverse() : [...list.keys()];

    for (const at of order) {
        if (!moving.has(list[at].id)) continue;

        const to = at + step;

        if (to < 0 || to >= list.length || moving.has(list[to].id)) continue;

        [list[at], list[to]] = [list[to], list[at]];
    }

    return list;
}

/**
 * Copies of elements, offset so they do not sit exactly on their originals,
 * with fresh ids and their groups renamed together.
 */
export function cloneElements(elements, offset = 2) {
    const groups = new Map();

    return elements.map((element) => {
        const copy = JSON.parse(JSON.stringify(element));

        copy.id = newId();
        copy.x = round(copy.x + offset);
        copy.y = round(copy.y + offset / RATIO);

        if (copy.group) {
            if (!groups.has(copy.group)) groups.set(copy.group, newId());
            copy.group = groups.get(copy.group);
        }

        delete copy.locked;

        return copy;
    });
}

/**
 * The derived keys the server adds to an element for drawing it.
 *
 * Kept when an element is written locally so the editor keeps drawing the
 * picture without a round trip; dropped by the server on save, since none of
 * them is a key the normalizer accepts.
 */
export const DERIVED = [
    "mediaUrl",
    "mediaAlt",
    "mediaFocusDefault",
    "videoUrl",
    "poster",
    "mimeType",
    "embedUrl",
    "provider",
    "thumbnail",
];

const YOUTUBE = [
    /^https?:\/\/(?:www\.)?youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|v\/)([A-Za-z0-9_-]{6,20})/i,
    /^https?:\/\/youtu\.be\/([A-Za-z0-9_-]{6,20})/i,
];

const VIMEO =
    /^https?:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d{6,12})/i;

/**
 * What the editor can show of a film link before the server has resolved it:
 * the player address and, for YouTube, its published still. The server
 * resolves the address again on save and keeps it only if it knows it, so
 * this is a preview, never a permission.
 */
export function embedPreview(url) {
    const address = String(url ?? "").trim();
    const youtube = YOUTUBE.map((pattern) => address.match(pattern)?.[1]).find(
        Boolean,
    );

    if (youtube) {
        return {
            url: address,
            embedUrl: `https://www.youtube-nocookie.com/embed/${youtube}`,
            thumbnail: `https://i.ytimg.com/vi/${youtube}/hqdefault.jpg`,
            provider: "youtube",
        };
    }

    const vimeo = address.match(VIMEO)?.[1];

    if (vimeo)
        return {
            url: address,
            embedUrl: `https://player.vimeo.com/video/${vimeo}`,
            thumbnail: null,
            provider: "vimeo",
        };

    return { url: address, embedUrl: null, thumbnail: null, provider: null };
}
