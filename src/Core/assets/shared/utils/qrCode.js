/**
 * Le bloc QR code - `[data-qr-code]`.
 *
 * Dessine le code dans un canvas à partir de `data-qr-text`, pose au centre
 * l'image de `data-qr-logo` sur un carré blanc, et propose de télécharger le
 * tout en PNG. Avec une image, le code est fait au niveau de correction le
 * plus haut (H, 30 %) : c'est ce qui laisse un lecteur le déchiffrer malgré
 * ce que l'image cache. L'image ne couvre jamais plus de 22 % de la largeur,
 * bien en dessous de ce que H tolère.
 *
 * La bibliothèque n'est chargée que sur une page qui a un QR code.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_qr_code.html.twig
 */
const SELECTOR = "[data-qr-code]";

/** Définition du dessin : assez pour une impression nette, quelle que soit la taille affichée. */
const PIXELS = 1024;

/** Part de la largeur que l'image du centre peut prendre. */
export const LOGO_SHARE = 0.22;

/** Le carré blanc du centre et l'image dedans, en pixels, pour un code de `size` pixels. */
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

function loadImage(src) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = src;
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

    // La bibliothèque fixe la taille affichée en pixels, celle du dessin :
    // retirée, c'est la largeur choisie pour le bloc qui décide.
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

            // Recadré au carré, centré : un logo large ou une photo en hauteur
            // gardent leurs proportions au lieu d'être écrasés.
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
            // Sans l'image, le code reste lisible : il est seulement plus sobre.
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
        // Le lien reste affiché à la place du code.
    }
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", () => void arm());
} else {
    void arm();
}
