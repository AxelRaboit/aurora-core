/**
 * L'inscription à la lettre d'information - `[data-newsletter]`.
 *
 * Envoie l'adresse et le consentement en arrière-plan, puis remplace le
 * formulaire par un mot de remerciement. Le module est indispensable : la
 * route n'accepte que la requête qu'il envoie.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_newsletter_signup.html.twig
 */
const SELECTOR = "[data-newsletter]";

function wire(form) {
    form.addEventListener("submit", async (event) => {
        event.preventDefault();

        const email = form.querySelector("[data-newsletter-email]");
        const consent = form.querySelector("[data-newsletter-consent]");
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
                body: JSON.stringify({
                    email: email.value,
                    consent: true === consent?.checked,
                }),
            });
            const data = await response.json();

            if (data?.success) {
                form.querySelectorAll("input, button, label").forEach((el) => {
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
