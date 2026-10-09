/**
 * The colour a collaborator wears on a shared note: their caret, their
 * avatar, their name tag.
 *
 * **Derived from the account id, never stored.** The same person keeps the
 * same hue from one session to the next, and from one reader's screen to
 * another's, without anybody having to agree on it. 47 is prime to 360, so
 * consecutive accounts land 47 degrees apart on the wheel and every hue is
 * eventually used once before any repeats.
 */
export function collaboratorHue(userId) {
    return (Number(userId) * 47) % 360;
}

/**
 * The fill behind white text: an avatar's initials, a caret's name tag.
 *
 * **28% lightness, and not a shade lighter.** White text needs 4.5:1, and the
 * hue that reflects the most light at a given lightness is yellow: at the
 * 45% the caret bar uses, a yellow tag read at 1.9:1. At 28% the worst hue on
 * the wheel still gives 4.6:1, which the test measures for all 360 of them.
 */
export function collaboratorColor(userId) {
    return `hsl(${collaboratorHue(userId)} 70% 28%)`;
}

/**
 * The caret bar itself, two pixels wide and carrying no text.
 *
 * Brighter than the fill, because it has to stand out on the dark theme's
 * editor as much as on the light one, and a 28% navy bar disappears on a
 * near-black field.
 */
export function collaboratorCaretColor(userId) {
    return `hsl(${collaboratorHue(userId)} 70% 45%)`;
}
