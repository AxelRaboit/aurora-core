import { describe, expect, it } from "vitest";
import {
    JOIN_REQUEST,
    JOIN_SEED,
    canCoedit,
    isElected,
    isForMe,
    isMine,
    joinAction,
    textDelta,
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

describe("textDelta", () => {
    it("says nothing changed, which is what stops a remote update echoing back", () => {
        expect(textDelta("bonjour", "bonjour")).toBeNull();
    });

    it("reads one typed character as one insertion", () => {
        expect(textDelta("abc", "abXc")).toEqual({
            index: 2,
            remove: 0,
            insert: "X",
        });
    });

    it("reads a deletion as a deletion, and inserts nothing", () => {
        expect(textDelta("abXc", "abc")).toEqual({
            index: 2,
            remove: 1,
            insert: "",
        });
    });

    it("appends at the end", () => {
        expect(textDelta("note", "notes")).toEqual({
            index: 4,
            remove: 0,
            insert: "s",
        });
    });

    it("keeps the prefix and the suffix from overlapping on a repeated run", () => {
        // "aa" to "a" is one character gone, not two: a suffix allowed to
        // reach back past the prefix would report `remove: 2` and empty the
        // text. The bound on the second loop is what prevents it.
        expect(textDelta("aa", "a")).toEqual({
            index: 1,
            remove: 1,
            insert: "",
        });
    });

    it("replaces a run in the middle in one operation", () => {
        // "le ch" is common, so the operation is shorter than it looks:
        // "at" out, "ien" in. The shortest one is the point - it is what a
        // neighbour's caret survives.
        expect(textDelta("le chat dort", "le chien dort")).toEqual({
            index: 5,
            remove: 2,
            insert: "ien",
        });
    });

    it("handles a text that was empty", () => {
        expect(textDelta("", "bonjour")).toEqual({
            index: 0,
            remove: 0,
            insert: "bonjour",
        });
    });

    it("handles a text emptied", () => {
        expect(textDelta("bonjour", "")).toEqual({
            index: 0,
            remove: 7,
            insert: "",
        });
    });

    it("rebuilds the text it describes, for any pair", () => {
        // The property that matters: applying the delta to the old text has
        // to give the new one. Anything else and the document drifts from
        // the form by exactly the amount the delta got wrong.
        const samples = [
            ["", ""],
            ["abc", "abc"],
            ["abc", ""],
            ["", "abc"],
            ["le chat dort", "le chien dort"],
            ["aaaa", "aa"],
            ["aa", "aaaa"],
            ["# Titre\n\ntexte", "# Titre\n\ntexte modifié"],
            ["un deux trois", "un trois"],
            ["éàü", "éàüö"],
        ];

        for (const [previous, next] of samples) {
            const delta = textDelta(previous, next);
            const applied =
                null === delta
                    ? previous
                    : previous.slice(0, delta.index) +
                      delta.insert +
                      previous.slice(delta.index + delta.remove);

            expect(applied).toBe(next);
        }
    });
});
