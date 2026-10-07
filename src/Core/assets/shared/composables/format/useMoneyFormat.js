import { useI18n } from "vue-i18n";

/**
 * Amounts in the reader's language: « 750 € » and « 10 000 € » in French.
 *
 * `Intl.NumberFormat(undefined, …)` reads the browser's language instead, so
 * a French suite opened in an English browser printed « €750 » and
 * « €10,000 » next to a contract text that says « 750 € ». The language is the
 * suite's, the one every label on the screen is written in.
 *
 * Amounts travel in cents. A round figure drops its decimals (a contract of
 * 750 €, a capital of 10 000 €); the cents show only when they carry
 * something.
 */
export function useMoneyFormat() {
    const { locale } = useI18n();

    function formatMoney(cents, currency = "EUR", placeholder = null) {
        if (null === cents || undefined === cents || "" === cents)
            return placeholder;

        const amount = Number(cents);
        if (!Number.isFinite(amount)) return placeholder;

        return new Intl.NumberFormat(locale.value, {
            style: "currency",
            currency: currency || "EUR",
            minimumFractionDigits: 0 === amount % 100 ? 0 : 2,
        }).format(amount / 100);
    }

    return { formatMoney };
}
