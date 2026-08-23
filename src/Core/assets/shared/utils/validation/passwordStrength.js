/**
 * Pure function - no Vue, no i18n.
 * Returns a score 0-5 based on password complexity.
 *
 *   0 = empty
 *   1 = very weak  (< 12 chars)
 *   2 = weak       (≥ 12 chars)
 *   3 = fair       (+ uppercase)
 *   4 = strong     (+ digit)
 *   5 = very strong (+ special char AND ≥ 20 chars)
 */
export function calculatePasswordStrength(password) {
    if (!password) return 0;
    let score = 0;
    if (password.length >= 12) score++;
    if (password.length >= 20) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    return score;
}
