import { describe, it, expect } from "vitest";

const { showResults } = await import("./poll.js");

describe("showResults", () => {
    it("fills the bars, the percentages and the total, and closes the vote", () => {
        document.body.innerHTML = `
            <section data-poll data-poll-total-label="Votes : __count__">
                <button data-poll-answer="0"><span data-poll-bar class="hidden"></span><span data-poll-percent class="hidden"></span></button>
                <button data-poll-answer="1"><span data-poll-bar class="hidden"></span><span data-poll-percent class="hidden"></span></button>
                <p data-poll-total class="hidden"></p>
            </section>`;
        const poll = document.querySelector("[data-poll]");

        showResults(poll, {
            total: 4,
            answers: [{ percent: 75 }, { percent: 25 }],
        });

        const buttons = poll.querySelectorAll("[data-poll-answer]");
        expect(buttons[0].disabled).toBe(true);
        expect(buttons[0].querySelector("[data-poll-bar]").style.width).toBe(
            "75%",
        );
        expect(
            buttons[1].querySelector("[data-poll-percent]").textContent,
        ).toBe("25 %");
        expect(poll.querySelector("[data-poll-total]").textContent).toBe(
            "Votes : 4",
        );
        expect(
            poll
                .querySelector("[data-poll-total]")
                .classList.contains("hidden"),
        ).toBe(false);
    });
});
