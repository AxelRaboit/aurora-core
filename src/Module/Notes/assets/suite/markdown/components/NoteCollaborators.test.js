import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import NoteCollaborators from "./NoteCollaborators.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({
        t: (key, parameters) =>
            parameters ? `${key} ${JSON.stringify(parameters)}` : key,
    }),
}));

function person(userId, name, editing = true) {
    return { userId, name, editing };
}

describe("NoteCollaborators", () => {
    it("draws one face per person", () => {
        const wrapper = mount(NoteCollaborators, {
            props: { people: [person(2, "Test A"), person(3, "Test B")] },
        });

        expect(wrapper.findAll("[data-note-collaborator]")).toHaveLength(2);
        expect(wrapper.text()).toContain("TA");
        expect(wrapper.text()).toContain("TB");
    });

    it("counts the people past four instead of drawing them", () => {
        const people = [1, 2, 3, 4, 5, 6].map((id) =>
            person(id, `Person ${id}`),
        );
        const wrapper = mount(NoteCollaborators, { props: { people } });

        expect(wrapper.findAll("[data-note-collaborator]")).toHaveLength(4);
        expect(wrapper.text()).toContain("+2");
    });

    it("says on each face who it is, whether they write, and how the room is kept up to date", () => {
        const wrapper = mount(NoteCollaborators, {
            props: {
                people: [person(2, "Test A", true), person(3, "Test B", false)],
                status: "notes.markdown.live.streaming",
            },
        });
        const [writer, reader] = wrapper.findAll("[data-note-collaborator]");

        expect(writer.attributes("title")).toBe(
            "Test A · notes.markdown.live.editing\nnotes.markdown.live.streaming",
        );
        expect(reader.attributes("title")).toContain(
            "notes.markdown.live.reading",
        );
    });

    it("gives a screen reader the whole sentence", () => {
        const alone = mount(NoteCollaborators, {
            props: { people: [person(2, "Test A")] },
        });
        expect(alone.attributes("aria-label")).toContain(
            "notes.markdown.live.here ",
        );
        expect(alone.attributes("aria-label")).toContain("Test A");

        const several = mount(NoteCollaborators, {
            props: { people: [person(2, "Test A"), person(3, "Test B")] },
        });
        expect(several.attributes("aria-label")).toContain(
            "notes.markdown.live.here_many",
        );
    });

    it("paints two people in two colours", () => {
        const wrapper = mount(NoteCollaborators, {
            props: { people: [person(2, "Test A"), person(3, "Test B")] },
        });
        const [first, second] = wrapper.findAll(
            "[data-note-collaborator] > div",
        );

        expect(first.attributes("style")).toContain("background-color");
        expect(first.attributes("style")).not.toBe(second.attributes("style"));
    });
});
