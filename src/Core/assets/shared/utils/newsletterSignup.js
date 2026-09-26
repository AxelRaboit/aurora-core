/**
 * L'inscription à la lettre d'information - `[data-newsletter]`.
 *
 * Envoie l'adresse en arrière-plan et remplace le formulaire par un mot de
 * remerciement. Sans ce module, le bouton envoie le formulaire normalement
 * (rechargement compris) vers la même adresse.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_newsletter_signup.html.twig
 */
const SELECTOR = "[data-newsletter]";

function wire(form) {
    form.addEventListener("submit", async (event) => {
        event.preventDefault();

        const email = form.querySelector("[data-newsletter-email]");
        const submitButton = form.querySelector("[data-newsletter-submit]");
        const success = form.querySelector("[data-newsletter-success]");
        const error = form.querySelector("[data-newsletter-error]");

        error.classList.add("hidden");
        submitButton.disabled = true;

        try {
            const response = await fetch(form.dataset.newsletterEndpoint, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ email: email.value }),
            });
            const data = await response.json();

            if (data?.success) {
                form.querySelectorAll("input, button").forEach((el) => {
                    el.hidden = true;
                });
                success.classList.remove("hidden");
            } else {
                error.classList.remove("hidden");
            }
        } catch {
            error.classList.remove("hidden");
        } finally {
            submitButton.disabled = false;
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
