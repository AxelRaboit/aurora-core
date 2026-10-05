/**
 * The entries of a month calendar, read from and written back to its data.
 *
 * The data stays the text the server reads, « date | format or network |
 * subject » one line per entry: the table only edits it cell by cell, so a
 * month typed by hand, pasted from a spreadsheet or built row by row is the
 * same thing.
 */
const SEPARATOR = /\s*[|;]\s*/;

/** The entries the table shows: past that, use the text. */
export const MAX_CALENDAR_ENTRIES = 62;

/** One entry per line that says something; the subject keeps any further bar it held. */
export function parseCalendarEntries(code) {
    return String(code ?? "")
        .split("\n")
        .map((line) => line.trim())
        .filter((line) => "" !== line)
        .map((line) => {
            const [date = "", kind = "", ...rest] = line.split(SEPARATOR);

            return { date, kind, title: rest.join(" | ") };
        });
}

/** The data for these entries; an entry left blank is not written, so a new row can wait to be filled. */
export function formatCalendarEntries(entries) {
    return entries
        .map((entry) => ({
            date: entry.date.trim(),
            kind: entry.kind.trim(),
            title: entry.title.trim(),
        }))
        .filter(
            (entry) =>
                "" !== entry.date || "" !== entry.kind || "" !== entry.title,
        )
        .map((entry) => `${entry.date} | ${entry.kind} | ${entry.title}`)
        .join("\n");
}
