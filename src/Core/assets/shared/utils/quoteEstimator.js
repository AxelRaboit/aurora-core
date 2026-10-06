/**
 * The quote estimator - `[data-quote]`.
 *
 * Adds up the base price and the price of the ticked boxes on every change,
 * and writes a summary into the first free text field of the form that
 * follows on the page - which is why the zone's panel advises placing one
 * right after it.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_quote_estimator.html.twig
 */
const SELECTOR = "[data-quote]";

/** The summary that the total writes into the next form. */
export function summarise(base, checked, currency) {
    const lines = checked.map(
        (item) => `- ${item.label} (+${item.price}${currency})`,
    );
    const total = base + checked.reduce((sum, item) => sum + item.price, 0);

    return {
        total,
        text: [...lines, `Total : ${total}${currency}`].join("\n"),
    };
}

function nextForm(quote) {
    let el = quote.nextElementSibling;

    while (el) {
        if (el.matches("form, [data-form]") || el.querySelector?.("form")) {
            return el.matches("form") ? el : el.querySelector("form");
        }
        el = el.nextElementSibling;
    }

    return null;
}

function wire(quote) {
    const base = Number.parseFloat(quote.dataset.quoteBase ?? "0") || 0;
    const currency = quote.dataset.quoteCurrency ?? "";
    const total = quote.querySelector("[data-quote-total]");
    const boxes = [...quote.querySelectorAll("[data-quote-price]")];
    const form = nextForm(quote);

    const update = () => {
        const checked = boxes
            .filter((box) => box.checked)
            .map((box) => ({
                label: box.dataset.quoteLabel ?? "",
                price: Number.parseFloat(box.dataset.quotePrice) || 0,
            }));
        const result = summarise(base, checked, currency);

        if (total) {
            total.textContent = `${result.total}${currency}`;
        }

        const field = form?.querySelector("textarea");

        if (field) {
            field.value = result.text;
        }
    };

    boxes.forEach((box) => box.addEventListener("change", update));
}

function arm() {
    document.querySelectorAll(SELECTOR).forEach(wire);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
