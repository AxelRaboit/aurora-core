/**
 * Le sondage d'une zone - `[data-poll]`.
 *
 * Envoie la réponse choisie, puis remplace les boutons par les résultats. Le
 * serveur ne compte qu'un vote par lecteur ; ce navigateur retient en plus
 * qu'il a voté ici, pour montrer les résultats d'emblée à la visite suivante
 * au lieu de proposer un vote qui ne compterait pas.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_poll.html.twig
 */
const SELECTOR = "[data-poll]";
const STORAGE_PREFIX = "aurora-poll:";

function remembered(key) {
    try {
        return null !== window.localStorage.getItem(STORAGE_PREFIX + key);
    } catch {
        return false;
    }
}

function remember(key) {
    try {
        window.localStorage.setItem(STORAGE_PREFIX + key, "1");
    } catch {
        // Stockage refusé : le serveur, lui, a compté le vote.
    }
}

/** Écrit les résultats dans la zone : barres, pourcentages et total. */
export function showResults(poll, results) {
    const buttons = [...poll.querySelectorAll("[data-poll-answer]")];

    buttons.forEach((button, index) => {
        const answer = results.answers[index] ?? { percent: 0 };
        const bar = button.querySelector("[data-poll-bar]");
        const percent = button.querySelector("[data-poll-percent]");

        button.disabled = true;
        bar?.classList.remove("hidden");
        percent?.classList.remove("hidden");

        if (bar) {
            bar.style.width = `${answer.percent}%`;
        }

        if (percent) {
            percent.textContent = `${answer.percent} %`;
        }
    });

    const total = poll.querySelector("[data-poll-total]");

    if (total) {
        total.textContent = (
            poll.dataset.pollTotalLabel ?? "__count__"
        ).replace("__count__", String(results.total));
        total.classList.remove("hidden");
    }
}

async function vote(poll, answer) {
    const response = await fetch(poll.dataset.pollEndpoint, {
        method: "POST",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ answer }),
    });

    const data = await response.json();

    if (data?.success) {
        remember(poll.dataset.pollKey);
        showResults(poll, data);
    }
}

function arm() {
    for (const poll of document.querySelectorAll(SELECTOR)) {
        if (!poll.dataset.pollEndpoint) {
            continue;
        }

        const answers = [...poll.querySelectorAll("[data-poll-answer]")];

        // Déjà voté ici : les résultats sont ceux que la page a apportés.
        if (remembered(poll.dataset.pollKey)) {
            const results = {
                total: Number(
                    poll
                        .querySelector("[data-poll-total]")
                        ?.textContent.match(/\d+/)?.[0] ?? 0,
                ),
                answers: answers.map((button) => ({
                    percent:
                        Number.parseInt(
                            button.querySelector("[data-poll-percent]")
                                ?.textContent ?? "0",
                            10,
                        ) || 0,
                })),
            };
            showResults(poll, results);

            continue;
        }

        for (const button of answers) {
            button.addEventListener("click", () => {
                answers.forEach((other) => {
                    other.disabled = true;
                });
                void vote(poll, Number(button.dataset.pollAnswer)).catch(() => {
                    answers.forEach((other) => {
                        other.disabled = false;
                    });
                });
            });
        }
    }
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
