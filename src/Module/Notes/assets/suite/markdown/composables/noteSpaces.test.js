import { describe, expect, it } from "vitest";
import {
    SHARED_SPACE_ID,
    detachSharedNotes,
    sortSpaces,
    spaceLabel,
    spacesWithShared,
} from "./noteSpaces.js";

const SPACES = [
    { id: 1, personal: true, position: 0 },
    { id: 2, personal: false, name: "Équipe", position: 1 },
];

const NOTES = [
    { id: 10, title: "Mes informations", folderId: 1, spaceId: 1 },
    { id: 11, title: "Compte rendu", folderId: 7, spaceId: 9 },
];

const translate = (key) => key;

describe("les notes confiées une par une", () => {
    it("sortent du dossier qui les porte, sinon l'arbre ne les dessine pas", () => {
        // Folder 7 lives in space 9, which this reader does not have, and
        // the tree only emits notes whose folder it knows.
        const detached = detachSharedNotes(NOTES, { 11: "editor" });

        expect(detached[1]).toMatchObject({
            id: 11,
            folderId: null,
            spaceId: SHARED_SPACE_ID,
            sharedRole: "editor",
        });
    });

    it("laissent les autres notes intactes", () => {
        const detached = detachSharedNotes(NOTES, { 11: "editor" });

        expect(detached[0]).toEqual(NOTES[0]);
    });

    it("acceptent un identifiant en texte, comme JSON le renvoie", () => {
        const detached = detachSharedNotes(NOTES, { 11: "reader" });

        expect(detached[1].sharedRole).toBe("reader");
    });

    it("ne touchent à rien quand personne n'a rien confié", () => {
        expect(detachSharedNotes(NOTES, {})).toBe(NOTES);
    });

    it("ajoutent leur groupe en dernier, et seulement s'il y en a", () => {
        expect(spacesWithShared(SPACES, {})).toHaveLength(2);

        const withShared = sortSpaces(
            spacesWithShared(SPACES, { 11: "reader" }),
        );

        expect(withShared).toHaveLength(3);
        expect(withShared[2].id).toBe(SHARED_SPACE_ID);
        // Neither writing nor settings: the group is heterogeneous, and
        // those gestures act on the notebook anyway.
        expect(withShared[2].canWrite).toBe(false);
        expect(withShared[2].canManage).toBe(false);
    });

    it("portent leur propre intitulé", () => {
        expect(spaceLabel({ shared: true }, translate)).toBe(
            "notes.markdown.people.shared_with_me",
        );
        expect(spaceLabel({ personal: true }, translate)).toBe(
            "notes.markdown.spaces.my_space",
        );
        expect(spaceLabel({ name: "Équipe" }, translate)).toBe("Équipe");
    });
});
