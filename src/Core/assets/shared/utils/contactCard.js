/**
 * The QR code and the contact to save of a business card - `[data-contact-card]`.
 *
 * Both carry the same vCard, written once on the server side in
 * `data-vcard`. The QR code is read with a phone camera; the button
 * downloads the same contact, which the phone immediately offers to save.
 * Both stay hidden without this module, and the card keeps its links, which
 * work on their own.
 *
 * The QR code library is only loaded on a page that has a card.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_contact_card.html.twig
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
        // Without a QR code, the card stays complete: its links and the button.
    }
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", () => void arm());
} else {
    void arm();
}
