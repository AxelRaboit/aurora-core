export function formatCurrency(
    amount,
    currency = "EUR",
    { fallbackDecimals = 2 } = {},
) {
    if (amount === null || amount === undefined) return "-";
    try {
        return new Intl.NumberFormat(undefined, {
            style: "currency",
            currency: currency || "EUR",
        }).format(amount);
    } catch {
        const safe =
            typeof amount === "number"
                ? amount.toFixed(fallbackDecimals)
                : amount;
        return `${safe} ${currency || ""}`.trim();
    }
}

export function formatProductPrice(product) {
    if (!product) return "-";
    return formatCurrency(product.price, product.currency, {
        fallbackDecimals: product.currencyDecimals ?? 2,
    });
}

/**
 * Formats an integer amount stored in CENTS as a localised currency string.
 * Returns the placeholder when the value is null/undefined so it slots
 * straight into a table cell.
 *
 * @param {?number} cents
 * @param {string} [currency="EUR"]
 * @param {string} [placeholder="-"]
 */
export function formatCents(cents, currency = "EUR", placeholder = "-") {
    if (cents === null || cents === undefined) return placeholder;
    return formatCurrency(cents / 100, currency);
}

/**
 * Formats a VAT rate stored in basis points (e.g. 2000 = 20.00%) as a
 * percentage string. Use for invoice line vat_rate_bp display.
 *
 * @param {?number} bp
 * @param {string} [placeholder="-"]
 */
export function formatBpAsPercent(bp, placeholder = "-") {
    if (bp === null || bp === undefined) return placeholder;
    return (
        (bp / 100).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + "%"
    );
}
