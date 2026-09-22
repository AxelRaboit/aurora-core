/**
 * One line of a list slot, split into its cells.
 *
 * A card is a heading and a sentence, a step is a name and a date, a table row
 * is its cells: several values on one line, separated by a pipe. Split here
 * rather than on save, because the line is what somebody typed and storing the
 * pieces would mean a shape the editor has to put back together to let them
 * edit it again.
 *
 * Shared by the frame and by nothing else today, in its own file rather than
 * inside the component: the print page, the player and the public page all
 * draw through that one component, but a second reader of these lines is one
 * layout away.
 */
import { ICON_NAMES } from "./icons.js";

export const CELL_SEPARATOR = "|";

/**
 * The cells of one line, trimmed, empty ones kept.
 *
 * Kept because position is meaning in a table: "Avant | | Après" is a row whose
 * middle cell is empty, and dropping it would shift the third value under the
 * second column.
 */
export function cells(line) {
    return String(line ?? "")
        .split(CELL_SEPARATOR)
        .map((cell) => cell.trim());
}

/** A line as a heading and the rest, for a card or a step. */
export function headed(line) {
    const [head, ...rest] = cells(line);

    return { head, body: rest.join(` ${CELL_SEPARATOR} `) };
}

/**
 * A card or a step, with the two cells that decorate it.
 *
 * **A third and a fourth cell rather than two more slots**, because the line is
 * still one thing somebody types on one line: a card that needed four fields
 * would be four inputs per card in the form, and the list would stop being a
 * list. Position carries the meaning here exactly as it does in a table row.
 *
 * Both are optional and both can be empty, so `Titre | description` keeps
 * meaning what it has always meant, and `Titre | | Phase 1` is a card with a
 * badge and no description.
 */
export function decorated(line) {
    const [head, ...rest] = cells(line);
    const [body = "", badge = "", ...tail] = rest;

    // Une quatrieme cellule qui n'est pas le nom d'une icone est du texte que
    // quelqu'un a tape, et elle retourne dans le corps plutot que de
    // disparaitre. C'est la meme regle que l'import de documents suit : un
    // module qui perd silencieusement un mot est pire qu'un module qui n'a pas
    // la fonctionnalite.
    const known = tail.length > 0 && ICON_NAMES.includes(tail[0]);
    const icon = known ? tail[0] : "";
    const extra = known ? tail.slice(1) : tail;

    return {
        head,
        body: [body, ...extra].filter(Boolean).join(` ${CELL_SEPARATOR} `),
        badge,
        icon,
    };
}

/**
 * A figure and what it counts, plus the share it represents.
 *
 * The third cell is a number between 0 and 100. Anything else draws no gauge
 * rather than an empty one: a bar at zero says "none of it", which is a
 * statement, and a typo should not make one.
 */
export function measured(line) {
    const [value, label = "", share] = cells(line);
    const percent = Number.parseFloat(share);

    return {
        value,
        label,
        share: Number.isFinite(percent)
            ? Math.min(Math.max(percent, 0), 100)
            : null,
    };
}
