import { afterEach, describe, it, expect, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import NoteTasksPanel from "./NoteTasksPanel.vue";

const TASKS = [
    {
        noteId: 1,
        noteTitle: "Courses",
        noteIcon: "🛒",
        noteLocked: false,
        index: 0,
        text: "Pain",
        done: false,
        due: null,
    },
    {
        noteId: 1,
        noteTitle: "Courses",
        noteIcon: "🛒",
        noteLocked: false,
        index: 1,
        text: "Lait",
        done: true,
        due: null,
    },
    {
        noteId: 2,
        noteTitle: "Projet",
        noteIcon: null,
        noteLocked: false,
        index: 0,
        text: "Devis",
        done: false,
        due: "2020-01-01",
    },
];

const mounted = [];
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

async function render(toggleTask = vi.fn(async () => true)) {
    const wrapper = mount(NoteTasksPanel, {
        props: {
            show: false,
            loadTasks: async () => TASKS.map((task) => ({ ...task })),
            toggleTask,
        },
        global: { plugins: [createTestI18n()] },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await wrapper.setProps({ show: true });
    await flushPromises();

    return wrapper;
}

function shown() {
    return [...document.body.querySelectorAll("[data-note-task]")].map((row) =>
        row.querySelector("span.flex-1").textContent.trim(),
    );
}

describe("NoteTasksPanel", () => {
    it("shows what is left to do by default, the late one first", async () => {
        await render();
        expect(shown()).toEqual(["Devis", "Pain"]);
    });

    it("keeps only the late ones, then shows everything", async () => {
        await render();
        document.body.querySelector('[data-note-tasks-filter="late"]').click();
        await flushPromises();
        expect(shown()).toEqual(["Devis"]);

        document.body.querySelector('[data-note-tasks-filter="all"]').click();
        await flushPromises();
        expect(shown()).toHaveLength(3);
    });

    it("ticks a task through the given function", async () => {
        const toggleTask = vi.fn(async () => true);
        await render(toggleTask);
        const box = document.body.querySelector(
            '[data-note-task] input[type="checkbox"]',
        );
        box.click();
        await flushPromises();
        expect(toggleTask).toHaveBeenCalledWith(
            expect.objectContaining({ text: "Devis" }),
        );
    });
});
