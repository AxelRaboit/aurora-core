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
 * The account block at the foot of the menu.
 *
 * It folds like a nav section, and the logout form is inside it - so the header
 * that unfolds it has to be on screen whenever the block is, or a folded block
 * is a locked door. It used to have a second shape for the icon rail where the
 * header was hidden and the rows shown regardless; the menu no longer has a
 * rail, and that special case is gone with it.
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
     * The menu is rendered twice in one file - an `<aside>` for desktop and a
     * drawer for mobile - and the drawer's copy was left unfoldable when the
     * desktop one gained its header. Both now read the same store, so folding
     * in either place is remembered in both, and neither can drift.
     */
    it("is one state, whichever of the two menus folded it", () => {
        const desktop = useSidemenuNav([], "");
        desktop.toggleAccount();

        // A second call site is what the drawer is: same store, same answer.
        expect(useSidemenuNav([], "").isAccountExpanded()).toBe(false);
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

describe("the hidden menu", () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.className = "";
    });

    // Read from the class the server renders on first paint, so it is right
    // before any script has run - the menu no longer starts wide and snaps shut.
    it("knows it is hidden before anything runs", () => {
        document.documentElement.classList.add("sidemenu-collapsed");

        expect(useSidemenuCollapse().collapsed.value).toBe(true);
    });

    /**
     * The menu and the button that toggles it are two Vue apps that cannot see
     * each other's refs. The class on `<html>` is the shared truth, and this
     * event is how each learns it changed - without it the header's icon would
     * keep saying "open" over a folded menu.
     */
    it("tells the other mount when it changes", () => {
        // Mounted rather than called bare: the listener is registered in
        // `onMounted`, which is where it belongs - the same shape
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

    it("hides and shows with one gesture", () => {
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
     * The fold and the hide are independent, and have to stay that way: the
     * template used to read `collapsed || isAccountExpanded()`, because a rail
     * hid the header that unfolds the block. Nothing hides that header now, so
     * `expanded` alone decides the rows - and hiding the menu must not quietly
     * unfold what the user folded.
     */
    it("leaves the account fold alone", () => {
        const { collapse, expand } = useSidemenuCollapse();
        const { isAccountExpanded, toggleAccount } = useSidemenuNav([], "");

        toggleAccount();
        expect(isAccountExpanded()).toBe(false);

        collapse();
        expand();

        expect(isAccountExpanded()).toBe(false);
    });
});
