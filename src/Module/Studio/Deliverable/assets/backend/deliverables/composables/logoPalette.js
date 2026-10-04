/**
 * The colours a logo is made of, for a deliverable dressed in a client's.
 *
 * Read in the browser, from the picture itself: drawn small on a canvas, its
 * pixels grouped into coarse buckets, and the most frequent ones kept. Near
 * white, near black, greys and transparent pixels are left out - they are the
 * paper and the ink, not the brand.
 */

function toHex(r, g, b) {
    return `#${[r, g, b].map((value) => value.toString(16).padStart(2, "0")).join("")}`;
}

function hue(r, g, b) {
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    if (max === min) return 0;
    const d = max - min;
    let h;
    if (max === r) h = ((g - b) / d) % 6;
    else if (max === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;

    return (h * 60 + 360) % 360;
}

/**
 * The brand colours among a list of RGBA bytes, most frequent first, two
 * colours being kept apart only when their hues differ by more than 30°.
 */
export function paletteFromPixels(data, count = 2) {
    const buckets = new Map();

    for (let i = 0; i < data.length; i += 4) {
        const [r, g, b, a] = [data[i], data[i + 1], data[i + 2], data[i + 3]];
        if (a < 128) continue;

        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        if (max > 240 && min > 240) continue;
        if (max < 25) continue;
        if (max - min < 30) continue;

        const key = [r, g, b]
            .map((value) => Math.round(value / 24) * 24)
            .join(",");
        const bucket = buckets.get(key) ?? { r: 0, g: 0, b: 0, n: 0 };
        bucket.r += r;
        bucket.g += g;
        bucket.b += b;
        bucket.n += 1;
        buckets.set(key, bucket);
    }

    const colours = [...buckets.values()]
        .sort((a, b) => b.n - a.n)
        .map(({ r, g, b, n }) => ({
            r: Math.round(r / n),
            g: Math.round(g / n),
            b: Math.round(b / n),
        }));

    const kept = [];
    for (const colour of colours) {
        const h = hue(colour.r, colour.g, colour.b);
        const near = kept.some((other) => {
            const delta = Math.abs(h - hue(other.r, other.g, other.b));

            return Math.min(delta, 360 - delta) < 30;
        });
        if (!near) kept.push(colour);
        if (kept.length === count) break;
    }

    return kept.map(({ r, g, b }) => toHex(r, g, b));
}

/** The brand colours of the picture at `url`, or [] when it cannot be read. */
export function paletteFromImage(url, count = 2) {
    return new Promise((resolve) => {
        const image = new Image();
        image.crossOrigin = "anonymous";
        image.onload = () => {
            try {
                const size = 64;
                const canvas = document.createElement("canvas");
                canvas.width = size;
                canvas.height = size;
                const context = canvas.getContext("2d");
                context.drawImage(image, 0, 0, size, size);
                resolve(
                    paletteFromPixels(
                        context.getImageData(0, 0, size, size).data,
                        count,
                    ),
                );
            } catch {
                resolve([]);
            }
        };
        image.onerror = () => resolve([]);
        image.src = url;
    });
}
