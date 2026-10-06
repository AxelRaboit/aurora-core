/**
 * The colours a logo is made of, for a deliverable dressed in a client's.
 *
 * Read in the browser, from the picture itself: drawn small on a canvas, its
 * pixels grouped into coarse buckets, and the most frequent ones kept. Near
 * white, near black, greys and transparent pixels are left out - they are the
 * paper and the ink, not the brand.
 */

function toHex(red, green, blue) {
    return `#${[red, green, blue].map((value) => value.toString(16).padStart(2, "0")).join("")}`;
}

function hue(red, green, blue) {
    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    if (max === min) return 0;
    const chroma = max - min;
    let sector;
    if (max === red) sector = ((green - blue) / chroma) % 6;
    else if (max === green) sector = (blue - red) / chroma + 2;
    else sector = (red - green) / chroma + 4;

    return (sector * 60 + 360) % 360;
}

/**
 * The brand colours among a list of RGBA bytes, most frequent first, two
 * colours being kept apart only when their hues differ by more than 30°.
 */
export function paletteFromPixels(data, count = 2) {
    const buckets = new Map();

    for (let offset = 0; offset < data.length; offset += 4) {
        const [red, green, blue, alpha] = [
            data[offset],
            data[offset + 1],
            data[offset + 2],
            data[offset + 3],
        ];
        if (alpha < 128) continue;

        const max = Math.max(red, green, blue);
        const min = Math.min(red, green, blue);
        if (max > 240 && min > 240) continue;
        if (max < 25) continue;
        if (max - min < 30) continue;

        const key = [red, green, blue]
            .map((value) => Math.round(value / 24) * 24)
            .join(",");
        const bucket = buckets.get(key) ?? {
            red: 0,
            green: 0,
            blue: 0,
            pixels: 0,
        };
        bucket.red += red;
        bucket.green += green;
        bucket.blue += blue;
        bucket.pixels += 1;
        buckets.set(key, bucket);
    }

    const colours = [...buckets.values()]
        .sort((left, right) => right.pixels - left.pixels)
        .map(({ red, green, blue, pixels }) => ({
            red: Math.round(red / pixels),
            green: Math.round(green / pixels),
            blue: Math.round(blue / pixels),
        }));

    const kept = [];
    for (const colour of colours) {
        const colourHue = hue(colour.red, colour.green, colour.blue);
        const near = kept.some((other) => {
            const delta = Math.abs(
                colourHue - hue(other.red, other.green, other.blue),
            );

            return Math.min(delta, 360 - delta) < 30;
        });
        if (!near) kept.push(colour);
        if (kept.length === count) break;
    }

    return kept.map(({ red, green, blue }) => toHex(red, green, blue));
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
