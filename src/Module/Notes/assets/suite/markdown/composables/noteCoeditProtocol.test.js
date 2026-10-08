import { describe, expect, it } from "vitest";
import {
    JOIN_REQUEST,
    JOIN_SEED,
    canCoedit,
    isElected,
    isForMe,
    isMine,
    joinAction,
} from "./noteCoeditProtocol.js";

describe("entrer dans une session", () => {
    it("amorce le document quand la pièce est vide", () => {
        expect(joinAction([])).toBe(JOIN_SEED);
        expect(joinAction(null)).toBe(JOIN_SEED);
    });

    /**
     * The rule the whole design rests on: two clients that each build a
     * document from the same markdown build two different histories, and
     * merging those duplicates every character.
     */
    it("demande l'état dès que quelqu'un est déjà là, jamais ne réamorce", () => {
        expect(joinAction([{ userId: 7 }])).toBe(JOIN_REQUEST);
        expect(joinAction([{ userId: 7 }, { userId: 9 }])).toBe(JOIN_REQUEST);
    });
});

describe("l'élection, qui ne demande l'accord de personne", () => {
    it("désigne le plus petit identifiant de la pièce", () => {
        expect(isElected(3, [{ userId: 7 }, { userId: 9 }])).toBe(true);
        expect(isElected(9, [{ userId: 3 }, { userId: 7 }])).toBe(false);
    });

    it("désigne celui qui est seul", () => {
        expect(isElected(42, [])).toBe(true);
    });

    it("bascule sur le suivant quand le plus petit s'en va", () => {
        // 3 is gone: 7 only sees 9 now, and takes over.
        expect(isElected(7, [{ userId: 9 }])).toBe(true);
    });

    it("ne désigne personne quand la page ne sait pas qui elle est", () => {
        expect(isElected(null, [])).toBe(false);
    });

    it("compare des nombres, pas des textes", () => {
        // "10" < "9" as text; 10 > 9 as numbers.
        expect(isElected(10, [{ userId: "9" }])).toBe(false);
        expect(isElected("9", [{ userId: 10 }])).toBe(true);
    });
});

describe("trier ce qui arrive du bus", () => {
    it("ne garde un état que s'il nous est adressé", () => {
        expect(isForMe({ to: 5 }, 5)).toBe(true);
        expect(isForMe({ to: 5 }, 9)).toBe(false);
    });

    it("laisse passer ce qui n'est adressé à personne", () => {
        expect(isForMe({ kind: "doc-update" }, 9)).toBe(true);
    });

    it("reconnaît son propre écho", () => {
        expect(isMine({ from: 9 }, 9)).toBe(true);
        expect(isMine({ from: 3 }, 9)).toBe(false);
    });
});

describe("les quatre conditions pour co-éditer", () => {
    const ready = {
        spaceAllows: true,
        canWrite: true,
        hasChannel: true,
        selfUserId: 3,
    };

    it("les veut toutes", () => {
        expect(canCoedit(ready)).toBe(true);
        expect(canCoedit({ ...ready, spaceAllows: false })).toBe(false);
        expect(canCoedit({ ...ready, canWrite: false })).toBe(false);
        expect(canCoedit({ ...ready, hasChannel: false })).toBe(false);
        expect(canCoedit({ ...ready, selfUserId: null })).toBe(false);
    });

    /** No hub means no channel: the editor stays on autosave. */
    it("refuse sans canal, ce qui est le mode dégradé", () => {
        expect(canCoedit({ ...ready, hasChannel: false })).toBe(false);
    });
});
