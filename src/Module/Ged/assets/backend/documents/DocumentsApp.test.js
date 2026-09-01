import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { askPage } from "@/shared/nav/modulePanelBridge.js";

window.__isAdmin__ = true;
window.matchMedia = vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
}));

const DocumentsApp = (await import("./DocumentsApp.vue")).default;

const i18n = createTestI18n();

const FOLDERS = [
    { id: 1, name: "Contrats", parentId: null, documentCount: 3 },
    { id: 2, name: "2026", parentId: 1, documentCount: 7 },
];

const mounted = [];

function render() {
    const wrapper = mount(DocumentsApp, {
        props: {
            documents: { items: [], total: 0, page: 1, totalPages: 1 },
            folders: FOLDERS,
            createPath: "/backend/ged/documents/create",
            updatePath: "/backend/ged/documents/__id__/update",
            deletePath: "/backend/ged/documents/__id__/delete",
            listPath: "/backend/ged/documents/list",
            uploadPath: "/backend/ged/documents/upload",
        },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    localStorage.clear();
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            items: [],
            total: 0,
            folders: FOLDERS,
        }),
    });
});

// Each mount registers window listeners, and window outlives the test. Leaving
// one behind means the next test's `askPage` is answered by a component that no
// longer exists - which is exactly what the last test here checks for.
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe("the documents page, once its folder aside moved to the menu", () => {
    /**
     * A hundred and ninety lines came out of this component. Vue resolves
     * template references at render, not at build, so a name left behind by
     * that deletion is invisible to the linter and to the bundler - it only
     * shows when something mounts the thing.
     */
    it("still mounts, with no reference left behind", () => {
        const wrapper = render();

        expect(wrapper.html()).toBeTruthy();
        expect(wrapper.find("aside").exists()).toBe(false);
    });

    /** The panel's half of the contract: a row click filters in place. */
    it("answers the menu panel when it asks to open a folder", async () => {
        render();
        await flushPromises();

        expect(askPage("ged:select", { folderId: 2, scope: "all" })).toBe(true);
        await flushPromises();

        const listCall = global.fetch.mock.calls
            .map(([url]) => String(url))
            .find((url) => url.includes("folderId=2"));
        expect(listCall).toBeTruthy();
    });

    it("adopts the folders the panel says have changed", async () => {
        const wrapper = render();
        await flushPromises();

        const renamed = [{ ...FOLDERS[0], name: "Contrats signés" }];
        expect(askPage("ged:reload", { folders: renamed })).toBe(true);
        await flushPromises();

        expect(wrapper.html()).toBeTruthy();
    });

    /** Nothing must answer once the listing is gone. */
    it("stops answering after it unmounts", async () => {
        const wrapper = render();
        await flushPromises();
        wrapper.unmount();

        expect(askPage("ged:select", { folderId: 1 })).toBe(false);
    });
});
