/**
 * The reminder offsets, and how to say them.
 *
 * The list is duplicated from AbstractPlanningEventReminder::OFFSETS, and that
 * is deliberate rather than fetched: it is a fixed vocabulary that changes when
 * someone edits both files in the same commit, and a round trip to learn nine
 * integers would make the form wait on the network to draw its own controls.
 * A test asserts the two lists agree.
 *
 * No Vue in here, so the labelling is testable without mounting a modal.
 */
export const REMINDER_OFFSETS = [0, 5, 10, 15, 30, 60, 120, 1440, 10080];

export const DEFAULT_REMINDER_OFFSET = 30;

/**
 * Names an offset.
 *
 * One key per offset rather than a number and a unit, because the list is closed
 * at nine: nine phrases a translator can read are worth more than divisibility
 * arithmetic plus plural forms this project does not configure, and "1 heure
 * avant" needs no rule to come out right.
 *
 * @param {number} minutes
 * @param {(key: string) => string} t
 * @returns {string}
 */
export function reminderLabel(minutes, t) {
    return t(`backend.plannings.reminders.offsets.${minutes}`);
}

/**
 * Adds or removes an offset, and returns a new sorted list.
 *
 * Returns rather than mutates so the caller assigns it and Vue sees the change;
 * sorted so the chips do not reorder themselves as they are toggled.
 *
 * @param {number[]} offsets
 * @param {number} offset
 * @returns {number[]}
 */
export function toggleReminder(offsets, offset) {
    const next = offsets.includes(offset)
        ? offsets.filter((value) => value !== offset)
        : [...offsets, offset];

    return next.sort((a, b) => a - b);
}
