/**
 * Le QR code et le contact à enregistrer d'une carte de visite - `[data-contact-card]`.
 *
 * Les deux portent la même vCard, écrite une fois côté serveur dans
 * `data-vcard`. Le QR code se lit avec l'appareil photo d'un téléphone ; le
 * bouton fait télécharger le même contact, que le téléphone propose aussitôt
 * d'enregistrer. Les deux restent cachés sans ce module, et la carte garde
 * ses liens, qui marchent seuls.
 *
 * La bibliothèque du QR code n'est chargée que sur une page qui a une carte.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_contact_card.html.twig
 */
const SELECTOR = "[data-contact-card]";

function save(card) {
    const blob = new Blob([card.dataset.vcard ?? ""], {
        type: "text/vcard;charset=utf-8",
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");

    link.href = url;
    link.download = card.dataset.vcardFile || "contact.vcf";
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function arm() {
    const cards = [...document.querySelectorAll(SELECTOR)];

    if (0 === cards.length) {
        return;
    }

    for (const card of cards) {
        const button = card.querySelector("[data-contact-save]");

        if (button) {
            button.hidden = false;
            button.addEventListener("click", () => save(card));
        }
    }

    try {
        const { default: QRCode } = await import("qrcode");

        for (const card of cards) {
            const slot = card.querySelector("[data-contact-qr]");

            if (!slot || !card.dataset.vcard) {
                continue;
            }

            slot.innerHTML = await QRCode.toString(card.dataset.vcard, {
                type: "svg",
                margin: 0,
                errorCorrectionLevel: "M",
            });
            slot.hidden = false;
        }
    } catch {
        // Sans QR code, la carte reste complète : ses liens et le bouton.
    }
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", () => void arm());
} else {
    void arm();
}
