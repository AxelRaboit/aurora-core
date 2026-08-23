/**
 * The alert menu, and the rows a form holds.
 *
 * `ALERT_OFFSETS` mirrors AbstractPlanningEventAlert::OFFSETS, and that is
 * deliberate rather than fetched: it is a fixed vocabulary that changes when
 * someone edits both files in the same commit, and a round trip to learn nine
 * integers would make the form wait on the network to draw its own controls.
 * A test asserts the two lists agree.
 *
 * No Vue in here, so the rows are testable without mounting a modal.
 */
export const ALERT_OFFSETS = [0, 5, 10, 15, 30, 60, 120, 1440, 10080];

export const DEFAULT_ALERT_OFFSET = 30;

/**
 * The select's last option: a moment of the reader's own choosing.
 *
 * A string, so it cannot collide with an offset however the list grows, and so a
 * row whose value is this one reads as "custom" rather than as a magic number.
 */
export const CUSTOM = "custom";

/**
 * How an alert reaches the reader.
 *
 * Mirrors PlanningAlertChannelEnum, and a test asserts the two agree. No push,
 * because this application has no push channel and offering one the form cannot
 * deliver would be worse than not offering it.
 */
export const CHANNELS = ["notification", "email"];

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
export function alertLabel(minutes, t) {
    return t(`backend.plannings.alerts.offsets.${minutes}`);
}

/**
 * The options the select offers, presets then custom.
 *
 * @param {(key: string) => string} t
 * @returns {Array<{value: number|string, label: string}>}
 */
/**
 * The channels a row can be delivered on.
 *
 * @param {(key: string) => string} t
 */
export function channelOptions(t) {
    return CHANNELS.map((channel) => ({
        value: channel,
        label: t(`backend.plannings.alerts.channel_${channel}`),
    }));
}

export function alertOptions(t) {
    return [
        ...ALERT_OFFSETS.map((minutes) => ({
            value: minutes,
            label: alertLabel(minutes, t),
        })),
        { value: CUSTOM, label: t("backend.plannings.alerts.custom") },
    ];
}

/**
 * A serialised alert as a form row.
 *
 * The wire carries two shapes and the form holds one: a `choice` the select binds
 * to, plus an `at` the picker binds to when that choice is custom. Without the
 * single shape every control in the row would have to know which kind it is
 * looking at.
 *
 * @param {{minutes: number|null, at: string|null}} alert
 * @returns {{choice: number|string, at: string|null}}
 */
export function toRow(alert) {
    const channel = CHANNELS.includes(alert.channel)
        ? alert.channel
        : "notification";

    return null === alert.minutes
        ? { choice: CUSTOM, at: alert.at, channel }
        : { choice: alert.minutes, at: null, channel };
}

/**
 * A form row back on the wire.
 *
 * A custom row with no moment yet sends nothing rather than a half-filled alert:
 * the reader opened the picker and has not chosen, and inventing a time for them
 * would be worse than the row quietly not existing until they do.
 *
 * @param {{choice: number|string, at: string|null}} row
 * @returns {{minutes: number|null, at: string}|null}
 */
export function fromRow(row) {
    const channel = CHANNELS.includes(row.channel)
        ? row.channel
        : "notification";

    if (CUSTOM !== row.choice) {
        return { minutes: row.choice, at: null, channel };
    }

    return row.at ? { minutes: null, at: row.at, channel } : null;
}

/** A fresh row, on the offset most calendars default to. */
export function blankRow() {
    return { choice: DEFAULT_ALERT_OFFSET, at: null, channel: "notification" };
}
