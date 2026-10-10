import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const request = vi.fn();

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

// The notes editor is the Notes module's own, tested there; here it only
// has to be drawn with the props the server built.
vi.mock("@notes/suite/markdown/MarkdownNotesApp.vue", () => ({
    default: {
        name: "MarkdownNotesApp",
        props: { fill: Boolean },
        inheritAttrs: false,
        template: '<div data-notes-app :data-fill="fill" />',
    },
}));

const SpaceNoteSpaceView = (await import("./SpaceNoteSpaceView.vue")).default;

const i18n = createTestI18n();

const PATHS = {
    open: "/workspace/7/notes/open",
    library: "/workspace/7?view=notes",
};

function render(state) {
    return mount(SpaceNoteSpaceView, {
        props: { state },
        global: { plugins: [i18n] },
    });
}

beforeEach(() => {
    request.mockReset();
});

/**
 * A client space's notes are written in the client space (10/10/2026): the
 * section draws the notes editor, or opens the notes space first.
 */
describe("SpaceNoteSpaceView", () => {
    it("draws the notes editor, filling the section, once the notes space is open", () => {
        const wrapper = render({
            enabled: true,
            noteSpace: {
                id: 3,
                name: "Atelier Dupont",
                readable: true,
                canWrite: true,
            },
            app: {
                libraryPath:
                    "/suite/notes/markdown?notesHost=studio.customer_space%3A7",
            },
            paths: PATHS,
        });

        const app = wrapper.find("[data-notes-app]");
        expect(app.exists()).toBe(true);
        expect(app.attributes("data-fill")).toBe("true");
        expect(wrapper.find("[data-space-note-open]").exists()).toBe(false);
    });

    it("opens the notes space on the first gesture, then shows the section on it", async () => {
        const assign = vi.fn();
        vi.stubGlobal("location", { ...window.location, assign });
        request.mockResolvedValue({ noteSpace: { id: 3 } });

        const wrapper = render({
            enabled: true,
            noteSpace: null,
            app: null,
            paths: PATHS,
        });

        await wrapper.find("[data-space-note-open]").trigger("click");
        await flushPromises();

        expect(request).toHaveBeenCalledWith(PATHS.open);
        expect(assign).toHaveBeenCalledWith(PATHS.library);

        vi.unstubAllGlobals();
    });

    it("says so when the notes space is closed to the reader", () => {
        const wrapper = render({
            enabled: true,
            noteSpace: {
                id: 3,
                name: "Atelier Dupont",
                readable: false,
                canWrite: false,
            },
            app: null,
            paths: PATHS,
        });

        expect(wrapper.find("[data-notes-app]").exists()).toBe(false);
        expect(wrapper.find("[data-space-note-open]").exists()).toBe(false);
    });
});
