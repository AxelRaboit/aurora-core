import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useNotePreview } from "./useNotePreview.js";

/**
 * The hover preview: what it asks of the server, and when.
 */
function anchor() {
    const element = document.createElement("div");

    element.getBoundingClientRect = () => ({
        top: 100,
        left: 100,
        right: 300,
        bottom: 200,
        width: 200,
        height: 100,
    });

    return element;
}

function hoverable(yes) {
    window.matchMedia = vi.fn(() => ({ matches: yes }));
}

beforeEach(() => {
    vi.useFakeTimers();
    hoverable(true);
    window.innerWidth = 1280;
    window.innerHeight = 800;
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe("useNotePreview", () => {
    it("waits before asking for anything", async () => {
        const fetchNote = vi.fn().mockResolvedValue({
            ok: true,
            payload: { note: { content: "x" } },
        });
        const preview = useNotePreview({ fetchNote });

        preview.open({ id: 7 }, anchor());

        await vi.advanceTimersByTimeAsync(200);

        expect(fetchNote).not.toHaveBeenCalled();
        expect(preview.noteId.value).toBeNull();

        await vi.advanceTimersByTimeAsync(400);

        expect(fetchNote).toHaveBeenCalledWith(7);
        expect(preview.noteId.value).toBe(7);
    });

    it("asks once per note and keeps the answer", async () => {
        const fetchNote = vi.fn().mockResolvedValue({
            ok: true,
            payload: { note: { content: "# Titre" } },
        });
        const preview = useNotePreview({ fetchNote });

        preview.open({ id: 7 }, anchor());
        await vi.advanceTimersByTimeAsync(500);

        expect(preview.content.value).toBe("# Titre");

        preview.close();
        preview.open({ id: 7 }, anchor());
        await vi.advanceTimersByTimeAsync(500);

        expect(fetchNote).toHaveBeenCalledTimes(1);
        expect(preview.content.value).toBe("# Titre");
    });

    /**
     * The cursor does not stop to wait for the server: the answer for a card
     * that was left must not show on the one being looked at.
     */
    it("drops an answer for a card the reader has already left", async () => {
        const lente = {
            ok: true,
            payload: { note: { content: "la première" } },
        };
        const rapide = {
            ok: true,
            payload: { note: { content: "la seconde" } },
        };
        const fetchNote = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) =>
                        setTimeout(() => resolve(lente), 300),
                    ),
            )
            .mockResolvedValueOnce(rapide);

        const preview = useNotePreview({ fetchNote });

        preview.open({ id: 1 }, anchor());
        await vi.advanceTimersByTimeAsync(460);

        preview.open({ id: 2 }, anchor());
        await vi.advanceTimersByTimeAsync(600);

        expect(preview.noteId.value).toBe(2);
        expect(preview.content.value).toBe("la seconde");
    });

    it("says nothing on a screen that cannot hover", async () => {
        hoverable(false);
        const fetchNote = vi.fn().mockResolvedValue({
            ok: true,
            payload: { note: { content: "x" } },
        });
        const preview = useNotePreview({ fetchNote });

        preview.open({ id: 7 }, anchor());
        await vi.advanceTimersByTimeAsync(800);

        expect(fetchNote).not.toHaveBeenCalled();
        expect(preview.noteId.value).toBeNull();
    });

    it("cancels an opening that has not happened yet", async () => {
        const fetchNote = vi.fn().mockResolvedValue({
            ok: true,
            payload: { note: { content: "x" } },
        });
        const preview = useNotePreview({ fetchNote });

        preview.open({ id: 7 }, anchor());
        preview.close();

        await vi.advanceTimersByTimeAsync(800);

        expect(fetchNote).not.toHaveBeenCalled();
    });

    /**
     * A preview that fails costs only its absence.
     *
     * The call starts from a timer: nobody awaits it, so an exception would
     * bubble up as an unhandled rejection to the page's safety net, which
     * would replace the library with its error screen.
     */
    it("stays quiet when the request blows up", async () => {
        const fetchNote = vi.fn().mockRejectedValue(new Error("réseau"));
        const preview = useNotePreview({ fetchNote });

        preview.open({ id: 7 }, anchor());
        await vi.advanceTimersByTimeAsync(500);

        expect(preview.noteId.value).toBeNull();
        expect(preview.loading.value).toBe(false);
    });

    /** A card near the right edge moves to the left rather than overflowing. */
    it("puts the card where it fits", async () => {
        const fetchNote = vi.fn().mockResolvedValue({
            ok: true,
            payload: { note: { content: "x" } },
        });
        const preview = useNotePreview({ fetchNote });

        window.innerWidth = 400;
        preview.open({ id: 7 }, anchor());
        await vi.advanceTimersByTimeAsync(500);

        // 100 (card's left edge) - 12 (gap) - 360 (width) is negative, so
        // the margin wins.
        expect(preview.position.value.left).toBe(8);
    });
});
