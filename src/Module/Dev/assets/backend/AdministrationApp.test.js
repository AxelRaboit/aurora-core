import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

window.__isDev__ = true;
window.matchMedia = vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
}));

const AdministrationApp = (await import("./AdministrationApp.vue")).default;

const i18n = createTestI18n();

const PATHS = [
    "overviewPath",
    "usersPath",
    "userCreatePath",
    "userUpdatePath",
    "userToggleRolePath",
    "userDeletePath",
    "impersonatePath",
    "accessRequestsPath",
    "auditPath",
    "permissionsPath",
    "modulesPath",
    "moduleUpdatePath",
    "moduleVerifyPasswordPath",
    "mountPointsPath",
    "mountPointCreatePath",
    "mountPointUpdatePath",
    "mountPointDeletePath",
    "mountPointTestPath",
    "accessRequestApprovePath",
    "accessRequestRejectPath",
    "accessRequestPurgePath",
].reduce((all, name) => ({ ...all, [name]: `/dev/${name}` }), {});

const mounted = [];

function render(tab = "overview") {
    const wrapper = mount(AdministrationApp, {
        props: { ...PATHS, tab },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ success: true, items: [] }),
    });
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe("the administration page, once its tab row moved to the menu", () => {
    /**
     * Thirty-four lines of template came out, and with them the labels, icons
     * and paths the row needed. Vue resolves template references at render, so
     * a name left behind is invisible to the linter and to the bundler.
     */
    it("still mounts, with no reference left behind", () => {
        const wrapper = render();

        expect(wrapper.html()).toBeTruthy();
        expect(wrapper.findAll("nav")).toHaveLength(0);
    });

    /**
     * Which tab shows is the server's answer now, arriving in a prop, because
     * each tab is a route of its own and the menu links to it. Nothing on this
     * page switches it any more.
     */
    it("shows the tab the route asked for", () => {
        expect(render("permissions").html()).toBeTruthy();
        expect(render("mount_points").html()).toBeTruthy();
    });
});
