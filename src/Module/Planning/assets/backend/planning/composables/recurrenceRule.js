/**
 * A recurrence rule, as a form holds it and as the standard writes it.
 *
 * The form's shape and RRULE are not the same thing, and translating between them
 * is where a recurrence UI goes wrong: a select offering "every week" has to
 * produce a rule the server and the iCalendar feed both accept, and reopening the
 * event has to put the reader back on the same option rather than dropping them
 * into a custom panel.
 *
 * No Vue in here, so both directions are testable without mounting a modal.
 */

export const FREQUENCIES = ["DAILY", "WEEKLY", "MONTHLY", "YEARLY"];

/** Monday first, like the grids. */
export const WEEKDAYS = ["MO", "TU", "WE", "TH", "FR", "SA", "SU"];

/** The presets a select offers, plus the two ends of the range. */
export const PRESETS = [
    "none",
    "daily",
    "weekly",
    "monthly",
    "yearly",
    "custom",
];

/** How a series stops. */
export const ENDS = ["never", "until", "count"];

const WEEKDAY_OF_JS_DAY = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"];

/** A blank form, which is "does not repeat". */
export function blankRecurrence() {
    return {
        preset: "none",
        freq: "WEEKLY",
        interval: 1,
        byDay: [],
        end: "never",
        until: "",
        count: 10,
    };
}

/**
 * The form a preset stands for, anchored on the event's own start.
 *
 * "Every week" means every week *on that day*, so the day comes from the date
 * being repeated rather than from a default - otherwise a Thursday meeting set to
 * repeat weekly would land on Mondays.
 *
 * @param {string} preset
 * @param {Date|null} start
 */
export function formForPreset(preset, start) {
    const base = { ...blankRecurrence(), preset };

    if ("none" === preset || "custom" === preset) {
        return base;
    }

    const freq = {
        daily: "DAILY",
        weekly: "WEEKLY",
        monthly: "MONTHLY",
        yearly: "YEARLY",
    }[preset];

    return {
        ...base,
        freq,
        byDay:
            "WEEKLY" === freq && start instanceof Date
                ? [WEEKDAY_OF_JS_DAY[start.getDay()]]
                : [],
    };
}

/**
 * The form as an RRULE, or null for "does not repeat".
 *
 * `INTERVAL=1` is left out because it is the default and writing it makes every
 * rule longer for nothing. An empty `BYDAY` is left out too: a weekly rule with no
 * day repeats on the day it starts, which is what the standard already says.
 *
 * @param {object} form
 * @returns {string|null}
 */
export function toRrule(form) {
    if (!form || "none" === form.preset) {
        return null;
    }

    const parts = [`FREQ=${form.freq}`];

    const interval = Number(form.interval);
    if (Number.isFinite(interval) && interval > 1) {
        parts.push(`INTERVAL=${Math.floor(interval)}`);
    }

    if ("WEEKLY" === form.freq && form.byDay?.length) {
        // In the standard's own order, so two forms that mean the same thing
        // produce the same string - which is what lets a round trip land back on
        // the same preset.
        const ordered = WEEKDAYS.filter((day) => form.byDay.includes(day));
        parts.push(`BYDAY=${ordered.join(",")}`);
    }

    if ("count" === form.end) {
        const count = Number(form.count);
        if (Number.isFinite(count) && count > 0) {
            parts.push(`COUNT=${Math.floor(count)}`);
        }
    } else if ("until" === form.end && form.until) {
        // The end of the chosen day, so a series told to stop on the 31st includes
        // the 31st. UNTIL is an instant and the field is a date.
        const at = new Date(`${form.until}T23:59:59`);
        if (!Number.isNaN(at.getTime())) {
            parts.push(
                `UNTIL=${at
                    .toISOString()
                    .replace(/[-:]/g, "")
                    .replace(/\.\d{3}/, "")}`,
            );
        }
    }

    return parts.join(";");
}

/**
 * An RRULE back into the form, landing on a preset when it is one.
 *
 * A rule that says no more than a preset does reopens on that preset. Anything
 * else - an interval, several days, a rule with an end - reopens as custom, so the
 * reader sees what is actually set rather than an option that would silently
 * simplify it on the next save.
 *
 * @param {string|null} rrule
 * @param {Date|null} start
 */
export function fromRrule(rrule, start) {
    if (!rrule) {
        return blankRecurrence();
    }

    const parts = {};
    for (const piece of rrule.split(";")) {
        const [name, value] = piece.split("=");
        if (name) {
            parts[name.toUpperCase()] = value ?? "";
        }
    }

    const freq = FREQUENCIES.includes(parts.FREQ) ? parts.FREQ : "WEEKLY";
    const interval = Number(parts.INTERVAL ?? 1) || 1;
    const byDay = (parts.BYDAY ?? "")
        .split(",")
        .filter((day) => WEEKDAYS.includes(day));

    const form = {
        preset: "custom",
        freq,
        interval,
        byDay,
        end: "never",
        until: "",
        count: 10,
    };

    if (parts.COUNT) {
        form.end = "count";
        form.count = Number(parts.COUNT) || 1;
    } else if (parts.UNTIL) {
        form.end = "until";
        form.until = untilToDate(parts.UNTIL);
    }

    return { ...form, preset: presetOf(form, start) };
}

/**
 * Which preset this form is, or "custom".
 *
 * Compared against what the preset would have produced rather than by inspecting
 * fields one at a time: the two are the same question, and asking it once means a
 * new preset cannot be forgotten here.
 */
function presetOf(form, start) {
    for (const preset of ["daily", "weekly", "monthly", "yearly"]) {
        const candidate = formForPreset(preset, start);

        if (
            candidate.freq === form.freq &&
            1 === form.interval &&
            "never" === form.end &&
            sameDays(candidate.byDay, form.byDay)
        ) {
            return preset;
        }
    }

    return "custom";
}

function sameDays(a, b) {
    return a.length === b.length && a.every((day) => b.includes(day));
}

/** `20261231T225959Z` back to `2026-12-31`, on the reader's own clock. */
function untilToDate(until) {
    const match = /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/.exec(until);
    if (null === match) {
        return "";
    }

    const [, y, m, d, h, min, s] = match;
    const at = new Date(Date.UTC(+y, +m - 1, +d, +h, +min, +s));
    const pad = (n) => String(n).padStart(2, "0");

    return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`;
}
