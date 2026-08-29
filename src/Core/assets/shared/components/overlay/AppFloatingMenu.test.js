import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppFloatingMenu from "./AppFloatingMenu.vue";

const items = [
    { id: "a", label: "Alpha" },
    { id: "b", label: "Bravo" },
    { id: "c", label: "Charlie" },
];

/**
 * The menu teleports to <body>, so the mount wrapper holds nothing on its own.
 * These assertions are about the markup rather than the portal, so the teleport
 * is stubbed away and the content renders in place. The portal itself has its
 * own test at the bottom - it is the part that fixes a real bug and it would be
 * silently undone if only the stubbed view were covered.
 */
function renderMenu(overrides = {}, slots = {}) {
    return mount(AppFloatingMenu, {
        props: {
            items,
            position: { top: 10, left: 20 },
            activeIndex: 0,
            ...overrides,
        },
        slots: {
            default: `<template #default="{ item }">{{ item.label }}</template>`,
            ...slots,
        },
        global: { stubs: { teleport: true } },
    });
}

describe("AppFloatingMenu", () => {
    it("renders one button per item", () => {
        const wrapper = renderMenu();
        expect(wrapper.findAll("button")).toHaveLength(3);
    });

    it("forwards the scoped slot for each item's content", () => {
        const wrapper = renderMenu();
        const text = wrapper.findAll("button").map((b) => b.text());
        expect(text).toEqual(["Alpha", "Bravo", "Charlie"]);
    });

    it("applies the active class to the row matching activeIndex", () => {
        const wrapper = renderMenu({ activeIndex: 1 });
        const buttons = wrapper.findAll("button");
        expect(buttons[0].classes()).not.toContain("bg-accent-500/15");
        expect(buttons[1].classes()).toContain("bg-accent-500/15");
        expect(buttons[2].classes()).not.toContain("bg-accent-500/15");
    });

    it("positions the menu via inline top/left from the position prop", () => {
        const wrapper = renderMenu({ position: { top: 42, left: 99 } });
        // Selected by the data attribute rather than a positioning class: the
        // menu went from `absolute` to `fixed` when it started teleporting, and
        // a selector spelled after the layout breaks on that kind of change.
        const menu = wrapper.find("[data-floating-menu]");
        const style = menu.attributes("style");
        expect(menu.classes()).toContain("fixed");
        expect(style).toContain("top: 42px");
        expect(style).toContain("left: 99px");
    });

    it("emits select(item) when a row is clicked", () => {
        const wrapper = renderMenu();
        wrapper.findAll("button")[1].trigger("mousedown");
        expect(wrapper.emitted("select")?.[0]).toEqual([items[1]]);
    });

    it("emits highlight(index) on mouseenter", () => {
        const wrapper = renderMenu();
        wrapper.findAll("button")[2].trigger("mouseenter");
        expect(wrapper.emitted("highlight")?.[0]).toEqual([2]);
    });

    it("honors a custom min-width class", () => {
        const wrapper = renderMenu({ minWidthClass: "min-w-96" });
        expect(wrapper.find("[data-floating-menu]").classes()).toContain(
            "min-w-96",
        );
    });

    it("renders the header slot above the list when provided", () => {
        const wrapper = mount(AppFloatingMenu, {
            props: {
                items,
                position: { top: 0, left: 0 },
                activeIndex: 0,
            },
            slots: {
                header: `<div class="my-header">Search: foo</div>`,
                default: `<template #default="{ item }">{{ item.label }}</template>`,
            },
            global: { stubs: { teleport: true } },
        });
        const header = wrapper.find(".my-header");
        expect(header.exists()).toBe(true);
        expect(header.text()).toBe("Search: foo");
    });

    it("does not render a header wrapper when the slot is not provided", () => {
        const wrapper = renderMenu();
        // No header div with border-b class should exist
        expect(wrapper.find(".border-b").exists()).toBe(false);
    });

    it("renders the empty slot when items is empty", () => {
        const wrapper = mount(AppFloatingMenu, {
            props: {
                items: [],
                position: { top: 0, left: 0 },
                activeIndex: 0,
            },
            slots: {
                empty: "No matches found",
                default: `<template #default="{ item }">{{ item.label }}</template>`,
            },
            global: { stubs: { teleport: true } },
        });
        expect(wrapper.findAll("button")).toHaveLength(0);
        expect(wrapper.text()).toContain("No matches found");
    });

    it("falls back to a generic empty message when slot is omitted", () => {
        const wrapper = mount(AppFloatingMenu, {
            props: {
                items: [],
                position: { top: 0, left: 0 },
                activeIndex: 0,
            },
            slots: {
                default: `<template #default="{ item }">{{ item.label }}</template>`,
            },
            global: { stubs: { teleport: true } },
        });
        expect(wrapper.text()).toBe("No results");
    });

    /**
     * The portal is the fix, so it gets its own test.
     *
     * Rendered inline the menu lived inside the markdown editor's
     * `overflow-auto` pane, which cropped it whenever it opened near an edge.
     * Mounted for real - no teleport stub - the node has to end up under
     * <body>, out of reach of any ancestor's overflow.
     */
    it("teleports the menu to the body, out of any clipping ancestor", () => {
        const wrapper = mount(AppFloatingMenu, {
            props: { items, position: { top: 5, left: 5 }, activeIndex: 0 },
            slots: {
                default: `<template #default="{ item }">{{ item.label }}</template>`,
            },
            attachTo: document.body,
        });

        const menu = document.body.querySelector("[data-floating-menu]");
        expect(menu).not.toBeNull();
        expect(wrapper.element.contains(menu)).toBe(false);

        wrapper.unmount();
        expect(document.body.querySelector("[data-floating-menu]")).toBeNull();
    });

    it("caps its height when given a maxHeight, and keeps the default otherwise", () => {
        const capped = renderMenu({ maxHeight: 120 });
        const cappedMenu = capped.find("[data-floating-menu]");
        expect(cappedMenu.attributes("style")).toContain("max-height: 120px");
        expect(cappedMenu.classes()).not.toContain("max-h-64");

        const uncapped = renderMenu();
        expect(uncapped.find("[data-floating-menu]").classes()).toContain(
            "max-h-64",
        );
    });
});
