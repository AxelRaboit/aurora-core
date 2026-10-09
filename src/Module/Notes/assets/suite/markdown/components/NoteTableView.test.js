import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import NoteTableView from "./NoteTableView.vue";

const NOTES = [
    {
        id: 1,
        title: "Mariage Dupont",
        updatedAt: "2026-10-01T10:00:00Z",
        properties: [
            { key: "Statut", type: "status", value: "Livré" },
            { key: "Budget", type: "number", value: 1800 },
        ],
    },
    {
        id: 2,
        title: "Portrait Martin",
        updatedAt: "2026-10-02T10:00:00Z",
        properties: [
            { key: "Budget", type: "number", value: 250 },
            { key: "Pour", type: "person", value: 7 },
        ],
    },
    {
        id: 3,
        title: "Idées",
        updatedAt: "2026-10-03T10:00:00Z",
        properties: [],
    },
];

function render() {
    return mount(NoteTableView, {
        props: {
            notes: NOTES,
            people: [{ id: 7, name: "Marie" }],
            noteUrlFor: (id) => `/notes/${id}`,
            noteLabel: (note) => note.title,
            folderLabel: (folder) => folder.name,
        },
        global: { plugins: [createTestI18n()] },
    });
}

function titles(wrapper) {
    return wrapper
        .findAll("[data-note-table-row]")
        .map((row) => row.find("td").text());
}

describe("NoteTableView", () => {
    it("makes a column of every property the notes use, in order of first use", () => {
        const wrapper = render();
        const headings = wrapper.findAll("th").map((cell) => cell.text());
        expect(headings.slice(1, -1)).toEqual(["Statut", "Budget", "Pour"]);
        expect(wrapper.text()).toContain("Marie");
    });

    it("sorts by a number up, then down, empty cells last, then back", async () => {
        const wrapper = render();
        const button = wrapper.find('[data-note-table-sort="Budget"]');

        await button.trigger("click");
        expect(titles(wrapper)).toEqual([
            "Portrait Martin",
            "Mariage Dupont",
            "Idées",
        ]);
        await button.trigger("click");
        expect(titles(wrapper)).toEqual([
            "Mariage Dupont",
            "Portrait Martin",
            "Idées",
        ]);
        await button.trigger("click");
        expect(titles(wrapper)).toEqual([
            "Mariage Dupont",
            "Portrait Martin",
            "Idées",
        ]);
    });

    it("filters on any cell, a person by name", async () => {
        const wrapper = render();
        await wrapper.find("[data-note-table-filter] input").setValue("marie");
        expect(titles(wrapper)).toEqual(["Portrait Martin"]);
    });
});
