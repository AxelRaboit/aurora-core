/**
 * The QR code block - `[data-qr-code]`.
 *
 * Draws the code in a canvas from `data-qr-text`, places the image from
 * `data-qr-logo` in the centre on a white square, and offers to download the
 * whole thing as a PNG. With an image, the code is made at the highest
 * correction level (H, 30%): that is what lets a reader decode it despite
 * what the image hides. The image never covers more than 22% of the width,
 * well below what H tolerates.
 *
 * The library is only loaded on a page that has a QR code.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_qr_code.html.twig
 */
const SELECTOR = "[data-qr-code]";

/** Drawing resolution: enough for a sharp print, whatever the displayed size. */
const PIXELS = 1024;

/** Share of the width the centre image may take. */
export const LOGO_SHARE = 0.22;

/** The white centre square and the image inside it, in pixels, for a code of `size` pixels. */
export function logoBox(size) {
    const box = Math.round(size * LOGO_SHARE);
    const padding = Math.round(box * 0.12);

    return {
        x: Math.round((size - box) / 2),
        y: Math.round((size - box) / 2),
        box,
        padding,
        image: box - 2 * padding,
    };
}

function loadImage(source) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = source;
    });
}

function roundedRect(context, x, y, width, height, radius) {
    context.beginPath();
    context.moveTo(x + radius, y);
    context.arcTo(x + width, y, x + width, y + height, radius);
    context.arcTo(x + width, y + height, x, y + height, radius);
    context.arcTo(x, y + height, x, y, radius);
    context.arcTo(x, y, x + width, y, radius);
    context.closePath();
}

async function draw(QRCode, zone) {
    const canvas = zone.querySelector("[data-qr-canvas]");
    const text = zone.dataset.qrText;

    if (!canvas || !text) {
        return;
    }

    const logo = zone.dataset.qrLogo;

    await QRCode.toCanvas(canvas, text, {
        width: PIXELS,
        margin: 1,
        errorCorrectionLevel: logo ? "H" : "M",
        color: { dark: "#000000", light: "#ffffff" },
    });

    // The library sets the displayed size in pixels, the drawing's size:
    // removed, it is the width chosen for the block that decides.
    canvas.style.removeProperty("width");
    canvas.style.removeProperty("height");

    if (logo) {
        try {
            const image = await loadImage(logo);
            const context = canvas.getContext("2d");
            const { x, y, box, padding, image: side } = logoBox(canvas.width);

            context.fillStyle = "#ffffff";
            roundedRect(context, x, y, box, box, box * 0.18);
            context.fill();

            // Cropped to a centred square: a wide logo or a tall photo keep their
            // proportions instead of being squashed.
            const crop = Math.min(image.naturalWidth, image.naturalHeight);
            context.save();
            roundedRect(
                context,
                x + padding,
                y + padding,
                side,
                side,
                side * 0.14,
            );
            context.clip();
            context.drawImage(
                image,
                (image.naturalWidth - crop) / 2,
                (image.naturalHeight - crop) / 2,
                crop,
                crop,
                x + padding,
                y + padding,
                side,
                side,
            );
            context.restore();
        } catch {
            // Without the image the code stays readable: it is only plainer.
        }
    }

    canvas.hidden = false;
    zone.querySelector("[data-qr-fallback]")?.setAttribute("hidden", "");

    const button = zone.querySelector("[data-qr-download]");

    if (button) {
        button.hidden = false;
        button.addEventListener("click", () => {
            const link = document.createElement("a");
            link.href = canvas.toDataURL("image/png");
            link.download = button.dataset.qrDownload || "qr-code.png";
            document.body.append(link);
            link.click();
            link.remove();
        });
    }
}

async function arm() {
    const zones = [...document.querySelectorAll(SELECTOR)];

    if (0 === zones.length) {
        return;
    }

    try {
        const { default: QRCode } = await import("qrcode");

        for (const zone of zones) {
            await draw(QRCode, zone);
        }
    } catch {
        // The link stays displayed in place of the code.
    }
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", () => void arm());
} else {
    void arm();
}
