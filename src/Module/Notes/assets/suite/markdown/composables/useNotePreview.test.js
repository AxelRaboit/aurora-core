import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useNotePreview } from "./useNotePreview.js";

/**
 * L'aperçu au survol : ce qu'il demande au serveur, et quand.
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
     * Le curseur ne s'arrête pas pour attendre le serveur : la réponse d'une
     * carte qu'on a quittée ne doit pas s'afficher sur celle qu'on regarde.
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
     * Un aperçu qui échoue ne coûte que son absence.
     *
     * L'appel part d'un minuteur : personne ne l'attend, donc une exception
     * remonterait en rejet non traité jusqu'au garde-fou de la page, qui
     * remplacerait la bibliothèque par son écran d'erreur.
     */
    it("stays quiet when the request blows up", async () => {
        const fetchNote = vi.fn().mockRejectedValue(new Error("réseau"));
        const preview = useNotePreview({ fetchNote });

        preview.open({ id: 7 }, anchor());
        await vi.advanceTimersByTimeAsync(500);

        expect(preview.noteId.value).toBeNull();
        expect(preview.loading.value).toBe(false);
    });

    /** Une carte près du bord droit passe à gauche plutôt que de déborder. */
    it("puts the card where it fits", async () => {
        const fetchNote = vi.fn().mockResolvedValue({
            ok: true,
            payload: { note: { content: "x" } },
        });
        const preview = useNotePreview({ fetchNote });

        window.innerWidth = 400;
        preview.open({ id: 7 }, anchor());
        await vi.advanceTimersByTimeAsync(500);

        // 100 (bord gauche de la carte) - 12 (écart) - 360 (largeur) est
        // négatif, donc la marge l'emporte.
        expect(preview.position.value.left).toBe(8);
    });
});
