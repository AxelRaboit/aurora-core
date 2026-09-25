/**
 * Le compte à rebours d'une zone - `[data-countdown-at]`.
 *
 * La page arrive avec le temps restant au moment où elle a été faite ; ce
 * module le fait vivre, seconde par seconde, jusqu'à l'instant visé. L'instant
 * est absolu (une date ISO avec son décalage) : l'horloge et le fuseau du
 * lecteur ne déplacent pas la cible, ils ne décident que du « maintenant ».
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_countdown.html.twig
 */
const SELECTOR = "[data-countdown-at]";

/** Jours, heures, minutes et secondes entre maintenant et la cible, jamais négatifs. */
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
            // Calé sur la seconde pleine, pour que les secondes ne sautent pas.
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
