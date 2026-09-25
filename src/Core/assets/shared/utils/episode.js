/**
 * Les chapitres d'un épisode audio - `[data-episode-seek]`.
 *
 * Un chapitre est un bouton qui place le lecteur de sa zone au bon moment et
 * lance la lecture. Sans ce module, les chapitres restent une table des
 * matières lisible, avec leurs minutages.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/_grid_zone.html.twig
 */
function arm() {
    document.querySelectorAll("[data-episode-seek]").forEach((button) => {
        button.addEventListener("click", () => {
            const player = button
                .closest(".not-prose")
                ?.querySelector("[data-episode-player]");

            if (!player) {
                return;
            }

            player.currentTime = Number(button.dataset.episodeSeek) || 0;
            void player.play().catch(() => {});
        });
    });
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
