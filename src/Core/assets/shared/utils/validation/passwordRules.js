export const PASSWORD_RULES = [
    {
        key: "length",
        test: (password) => password.length >= 8,
        errorKey: "shared.password.errors.too_short",
    },
    {
        key: "uppercase",
        test: (password) => /[A-Z]/.test(password),
        errorKey: "shared.password.errors.no_uppercase",
    },
    {
        key: "number",
        test: (password) => /[0-9]/.test(password),
        errorKey: "shared.password.errors.no_number",
    },
    {
        key: "special",
        test: (password) => /[^A-Za-z0-9]/.test(password),
        errorKey: "shared.password.errors.no_special",
    },
];

/**
 * Returns a validator function for password strength.
 * @param {Function} t - vue-i18n translate function
 * @returns {(value: string) => string|null}
 */
export function passwordValidator(t) {
    return (value) => {
        for (const rule of PASSWORD_RULES) {
            if (!value || !rule.test(value)) return t(rule.errorKey);
        }
        return null;
    };
}
