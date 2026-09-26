/**
 * La prise de rendez-vous - `[data-booking]`.
 *
 * Trois pas : choisir un jour, choisir un créneau, remplir nom et e-mail.
 * L'envoi revérifie le créneau côté serveur ; s'il vient d'être pris, le
 * message le dit et rien d'autre n'est perdu de ce qui a été tapé.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_appointment_booking.html.twig
 */
const SELECTOR = "[data-booking]";

function showDay(booking, date) {
    booking.querySelectorAll("[data-booking-day]").forEach((button) => {
        button.toggleAttribute(
            "data-active",
            button.dataset.bookingDay === date,
        );
    });
    booking.querySelectorAll("[data-booking-slots]").forEach((panel) => {
        panel.hidden = panel.dataset.bookingSlots !== date;
    });
}

async function submit(booking, at, label) {
    const form = booking.querySelector("[data-booking-form]");
    const error = booking.querySelector("[data-booking-error]");
    const submitButton = booking.querySelector("[data-booking-submit]");

    error.classList.add("hidden");
    submitButton.disabled = true;

    try {
        const response = await fetch(booking.dataset.bookingEndpoint, {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify({
                at,
                name: booking.querySelector("[data-booking-name]").value,
                email: booking.querySelector("[data-booking-email]").value,
                phone: booking.querySelector("[data-booking-phone]").value,
                message: booking.querySelector("[data-booking-message]").value,
            }),
        });
        const data = await response.json();

        if (data?.success) {
            form.hidden = true;
            booking
                .querySelectorAll("[data-booking-day], [data-booking-slot]")
                .forEach((el) => {
                    el.disabled = true;
                });
            const success = booking.querySelector("[data-booking-success]");
            success.textContent =
                `${success.dataset.template ?? success.textContent} ${data.label ?? label}`.trim();
            success.classList.remove("hidden");
        } else {
            error.textContent =
                409 === response.status
                    ? (error.dataset.taken ?? "")
                    : (error.dataset.invalid ?? "");
            error.classList.remove("hidden");
        }
    } catch {
        error.textContent = error.dataset.invalid ?? "";
        error.classList.remove("hidden");
    } finally {
        submitButton.disabled = false;
    }
}

function wire(booking) {
    booking.querySelectorAll("[data-booking-day]").forEach((button) => {
        button.addEventListener("click", () =>
            showDay(booking, button.dataset.bookingDay),
        );
    });

    let chosen = null;

    booking.querySelectorAll("[data-booking-slot]").forEach((button) => {
        button.addEventListener("click", () => {
            chosen = {
                at: button.dataset.bookingSlot,
                label: button.textContent.trim(),
            };
            const form = booking.querySelector("[data-booking-form]");
            form.hidden = false;
            form.querySelector("[data-booking-chosen]").textContent =
                chosen.label;
            form.scrollIntoView({ block: "nearest" });
        });
    });

    booking
        .querySelector("[data-booking-form]")
        ?.addEventListener("submit", (event) => {
            event.preventDefault();

            if (chosen) {
                void submit(booking, chosen.at, chosen.label);
            }
        });
}

function arm() {
    document.querySelectorAll(SELECTOR).forEach(wire);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
