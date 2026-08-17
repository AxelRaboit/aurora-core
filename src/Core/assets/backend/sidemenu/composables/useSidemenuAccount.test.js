import { beforeEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useSidemenuCollapse } from "./useSidemenuCollapse.js";

/** The smallest component that uses the composable the way the real ones do. */
function mountHolder() {
    return mount({
        setup() {
            return { api: useSidemenuCollapse() };
        },
        template: "<i />",
    });
}
import { useSidemenuNav } from "./useSidemenuNav.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

/**
 * The account block at the foot of the menu, and the one way it could lock
 * somebody out.
 *
 * It folds like a nav section, and its header is hidden by the stylesheet when
 * the menu shows icons only (`.sidemenu-collapsed .si-section-header`). So a
 * block that stayed folded in icon mode would have no control left to unfold it
 * — and the logout form is inside it.
 */
describe("the account block", () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.className = "";
    });

    it("starts open, like every section", () => {
        const { isAccountExpanded } = useSidemenuNav([], "");

        expect(isAccountExpanded()).toBe(true);
    });

    // Only explicit collapses are stored, which is what makes a fresh browser
    // show an open menu rather than an empty one.
    it("remembers being folded, and nothing else", () => {
        const first = useSidemenuNav([], "");
        first.toggleAccount();

        expect(first.isAccountExpanded()).toBe(false);
        expect(useSidemenuNav([], "").isAccountExpanded()).toBe(false);

        first.toggleAccount();

        expect(useSidemenuNav([], "").isAccountExpanded()).toBe(true);
    });

    /**
     * Shares the sections' own store rather than opening a second one: one key,
     * one rule. A separate mechanism for the same gesture would be a second
     * thing to keep in agreement.
     */
    it("is kept in the same store as the sections", () => {
        useSidemenuNav([], "").toggleAccount();

        expect(
            JSON.parse(localStorage.getItem("aurora-sidemenu-sections")),
        ).toEqual({ account: false });
    });
});

describe("the collapsed menu", () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.className = "";
    });

    // Read from the class the server renders on first paint, so it is right
    // before any script has run — the menu no longer starts wide and snaps shut.
    it("knows it is showing icons before anything runs", () => {
        document.documentElement.classList.add("sidemenu-collapsed");

        expect(useSidemenuCollapse().collapsed.value).toBe(true);
    });

    /**
     * The menu and the button that toggles it are two Vue apps that cannot see
     * each other's refs. The class on `<html>` is the shared truth, and this
     * event is how each learns it changed — without it the header's icon would
     * keep saying "open" over a folded menu.
     */
    it("tells the other mount when it changes", () => {
        // Mounted rather than called bare: the listener is registered in
        // `onMounted`, which is where it belongs — the same shape
        // `useSidemenuLiveColors` uses for the other cross-mount value. A test
        // that called the composable directly would be testing a way it is
        // never used, and would pass against a version that never listens.
        const holders = [mountHolder(), mountHolder()];
        const [menu, header] = holders.map((h) => h.vm.api);

        header.toggle();

        expect(header.collapsed.value).toBe(true);
        expect(menu.collapsed.value).toBe(true);

        menu.toggle();

        expect(header.collapsed.value).toBe(false);
        expect(menu.collapsed.value).toBe(false);

        holders.forEach((h) => h.unmount());
    });

    it("folds and unfolds with one gesture", () => {
        const { collapsed, toggle } = useSidemenuCollapse();

        toggle();
        expect(collapsed.value).toBe(true);

        toggle();
        expect(collapsed.value).toBe(false);
    });

    it("follows the two gestures that change it", () => {
        const { collapsed, collapse, expand } = useSidemenuCollapse();

        expect(collapsed.value).toBe(false);

        collapse();
        expect(collapsed.value).toBe(true);
        expect(
            document.documentElement.classList.contains("sidemenu-collapsed"),
        ).toBe(true);

        expand();
        expect(collapsed.value).toBe(false);
        expect(
            document.documentElement.classList.contains("sidemenu-collapsed"),
        ).toBe(false);
    });

    /**
     * The invariant the template leans on: `collapsed || isAccountExpanded()`.
     * Folded *and* in icon mode is the one combination where the rows must show
     * anyway, because the header that would unfold them is not on screen.
     */
    it("shows the account rows even when they are folded", () => {
        const { collapsed, collapse } = useSidemenuCollapse();
        const { isAccountExpanded, toggleAccount } = useSidemenuNav([], "");

        toggleAccount();
        collapse();

        expect(isAccountExpanded()).toBe(false);
        expect(collapsed.value || isAccountExpanded()).toBe(true);
    });
});
