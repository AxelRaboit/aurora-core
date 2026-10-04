import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import EventModal from "./EventModal.vue";

// The modal reads the viewport when it is imported; jsdom has no matchMedia.
vi.hoisted(() => {
    window.matchMedia ??= () => ({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
        addListener() {},
        removeListener() {},
    });
});

vi.mock("vue-i18n", () => ({
    useI18n: () => ({
        t: (key) => key,
        d: (value) => String(value),
        locale: { value: "fr" },
    }),
}));

/**
 * The read view offers only what the server will accept: without
 * `planning.events.edit` the event arrives read-only and has no edit button,
 * without `planning.events.delete` there is no delete button.
 */
const EVENT = {
    id: 3,
    title: "Atelier",
    planningId: 1,
    start: "2026-10-06T09:00:00+02:00",
    end: "2026-10-06T10:00:00+02:00",
    allDay: false,
    attendees: [],
    alerts: [],
};

function mountModal(event, props = {}) {
    return mount(EventModal, {
        props: {
            event,
            calendars: [{ id: 1, name: "Pro", colourSlot: 1 }],
            ...props,
        },
        global: {
            stubs: {
                AppModal: {
                    template: '<div><slot /><slot name="footer" /></div>',
                },
            },
        },
    });
}

function buttons(wrapper) {
    return wrapper.findAll("button").map((button) => button.text());
}

describe("EventModal rights", () => {
    it("offers edit and delete to who may do both", () => {
        const labels = buttons(
            mountModal({ ...EVENT, readOnly: false, lockedBySource: false }),
        );

        expect(
            labels.some((label) => label.includes("shared.common.edit")),
        ).toBe(true);
        expect(
            labels.some((label) => label.includes("shared.common.delete")),
        ).toBe(true);
    });

    it("hides delete without the right to delete", () => {
        const labels = buttons(
            mountModal(
                { ...EVENT, readOnly: false, lockedBySource: false },
                { canDelete: false },
            ),
        );

        expect(
            labels.some((label) => label.includes("shared.common.delete")),
        ).toBe(false);
    });

    it("hides edit for an event read-only to the reader, and keeps delete when allowed", () => {
        const labels = buttons(
            mountModal({ ...EVENT, readOnly: true, lockedBySource: false }),
        );

        expect(
            labels.some((label) => label.includes("shared.common.edit")),
        ).toBe(false);
        expect(
            labels.some((label) => label.includes("shared.common.delete")),
        ).toBe(true);
    });
});
