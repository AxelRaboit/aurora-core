/**
 * The countdown of a zone - `[data-countdown-at]`.
 *
 * The page arrives with the time remaining at the moment it was made; this
 * module brings it to life, second by second, until the target instant. The
 * instant is absolute (an ISO date with its offset): the reader's clock and
 * time zone do not move the target, they only decide what "now" is.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_countdown.html.twig
 */
const SELECTOR = "[data-countdown-at]";

/** Days, hours, minutes and seconds between now and the target, never negative. */
export function remaining(targetMs, nowMs) {
    const left = Math.max(0, Math.floor((targetMs - nowMs) / 1000));

    return {
        passed: 0 === left,
        days: Math.floor(left / 86400),
        hours: Math.floor((left % 86400) / 3600),
        minutes: Math.floor((left % 3600) / 60),
        seconds: left % 60,
    };
}

function paint(zone, parts) {
    for (const unit of ["days", "hours", "minutes", "seconds"]) {
        const cell = zone.querySelector(`[data-countdown-unit="${unit}"]`);

        if (cell) {
            cell.textContent =
                "days" === unit
                    ? String(parts[unit])
                    : String(parts[unit]).padStart(2, "0");
        }
    }

    if (parts.passed) {
        zone.querySelector("[data-countdown-figures]")?.classList.add("hidden");
        zone.querySelector("[data-countdown-after]")?.classList.remove(
            "hidden",
        );
    }
}

function arm() {
    const zones = [...document.querySelectorAll(SELECTOR)]
        .map((zone) => ({
            zone,
            target: Date.parse(zone.dataset.countdownAt ?? ""),
        }))
        .filter(({ target }) => !Number.isNaN(target));

    if (0 === zones.length) {
        return;
    }

    const tick = () => {
        const now = Date.now();
        let running = false;

        for (const { zone, target } of zones) {
            const parts = remaining(target, now);
            paint(zone, parts);
            running ||= !parts.passed;
        }

        if (running) {
            // Aligned on the full second, so that the seconds do not skip.
            window.setTimeout(tick, 1000 - (Date.now() % 1000));
        }
    };

    tick();
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
