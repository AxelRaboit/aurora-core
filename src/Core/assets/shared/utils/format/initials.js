/**
 * Returns 1-2 uppercase letters representing a person.
 *
 * Priority:
 *  - first + last initial of the full name
 *  - first two letters of a single-word name
 *  - explicit firstName/lastName initials (CRM contacts shape)
 *  - first letter of the email
 *  - "?" fallback
 */
export function initials({
    name = "",
    firstName = "",
    lastName = "",
    email = "",
} = {}) {
    const trimmedName = (name ?? "").trim();
    if (trimmedName) {
        const parts = trimmedName.split(/\s+/).filter(Boolean);
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (
            (parts[0][0] ?? "") + (parts[parts.length - 1][0] ?? "")
        ).toUpperCase();
    }

    const firstInitial = (firstName ?? "")[0] ?? "";
    const lastInitial = (lastName ?? "")[0] ?? "";
    if (firstInitial || lastInitial)
        return (firstInitial + lastInitial).toUpperCase();

    if (email) return email[0].toUpperCase();

    return "?";
}
