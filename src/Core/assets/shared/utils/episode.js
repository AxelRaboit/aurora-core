/**
 * The chapters of an audio episode - `[data-episode-seek]`.
 *
 * A chapter is a button that moves the player of its zone to the right
 * moment and starts playback. Without this module, the chapters stay a
 * readable table of contents, with their timings.
 *
 * Template: templates/Frontend/themes/default/editorial/post/_grid_zone.html.twig
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
