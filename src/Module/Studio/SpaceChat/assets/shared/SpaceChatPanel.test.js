import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import SpaceChatPanel from "./SpaceChatPanel.vue";

const i18n = createTestI18n();

/**
 * Two days of conversation, out of order on purpose.
 *
 * The server sends them oldest first; the panel is not allowed to depend on
 * that, because a message arriving live is appended to whatever is already
 * there and a reconnection replaces the lot.
 */
const MESSAGES = [
    {
        id: 2,
        body: "Parfait pour jeudi.",
        author: "camille@societe.test",
        fromClient: true,
        createdAt: "2026-09-17T08:30:00+00:00",
    },
    {
        id: 1,
        body: "Le brief est prêt.",
        author: "Admin",
        fromClient: false,
        createdAt: "2026-09-16T09:00:00+00:00",
    },
];

function render(props = {}) {
    return mount(SpaceChatPanel, {
        props: {
            messages: MESSAGES,
            reloadPath: "/workspace/1/chat/messages",
            postPath: "/workspace/1/chat",
            ...props,
        },
        global: { plugins: [i18n] },
    });
}

/** The message bubbles, ignoring the day separators between them. */
function bodies(panel) {
    return panel.findAll("p.whitespace-pre-line").map((p) => p.text());
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe("SpaceChatPanel", () => {
    it("reads oldest first, whatever order it was handed", () => {
        expect(bodies(render())).toEqual([
            "Le brief est prêt.",
            "Parfait pour jeudi.",
        ]);
    });

    it("separates the days, one line per day and not one per message", () => {
        // Two messages on two days: two separators. A conversation running
        // over weeks is unreadable without them, and unreadable with one on
        // every message.
        const separators = render().findAll(".flex-1.bg-line\\/60");

        expect(separators.length).toBe(4); // two rules per separator
    });

    it("puts the reader's own messages on their side, whichever side that is", () => {
        // One stream, two points of view. The studio wrote message 1 and the
        // client message 2, so the alignment has to swap between the two
        // surfaces - a component that picked a side by "fromClient" alone
        // would put the client's own words on the far side of their own page.
        const studio = render();
        const rows = studio.findAll(".flex.justify-end, .flex.justify-start");

        expect(rows[0].classes()).toContain("justify-end"); // du studio
        expect(rows[1].classes()).toContain("justify-start"); // du client

        const client = render({ ownSide: "client" });
        const flipped = client.findAll(
            ".flex.justify-end, .flex.justify-start",
        );

        expect(flipped[0].classes()).toContain("justify-start");
        expect(flipped[1].classes()).toContain("justify-end");
    });

    it("only tags a message as the client's when that says something", () => {
        // On their own page the client does not need telling they are the
        // client.
        expect(render().text()).toContain("client");
        expect(render({ ownSide: "client" }).text()).not.toContain("client");
    });

    it("opens no connection when no hub is configured", () => {
        const source = vi.fn();
        vi.stubGlobal("EventSource", source);

        const panel = render({ streamUrl: null });

        // The null address is the instruction not to connect. Without this the
        // panel would retry for ever against a port nobody is listening on.
        expect(source).not.toHaveBeenCalled();
        // And it says nothing about being live, because it is not claiming to be.
        expect(panel.text()).not.toContain("direct");
    });

    it("draws no box for a reader who may not write", () => {
        // A client link that may only read is handed no posting address, so
        // there is nothing to type into rather than a button the server would
        // refuse.
        expect(render({ postPath: null }).find("textarea").exists()).toBe(
            false,
        );
    });

    it("folds a message that arrives twice into one row", async () => {
        const listeners = {};
        vi.stubGlobal(
            "EventSource",
            class {
                constructor() {
                    Object.defineProperty(this, "onmessage", {
                        set: (fn) => (listeners.message = fn),
                    });
                    Object.defineProperty(this, "onopen", { set: () => {} });
                    Object.defineProperty(this, "onerror", { set: () => {} });
                }

                close() {}
            },
        );

        const panel = render({
            streamUrl: "https://hub.test/.well-known/mercure",
        });

        const arriving = {
            id: 3,
            body: "Une question.",
            author: "camille@societe.test",
            fromClient: true,
            createdAt: "2026-09-17T09:00:00+00:00",
        };

        listeners.message({ data: JSON.stringify(arriving) });
        listeners.message({ data: JSON.stringify(arriving) });
        await flushPromises();

        // The same message down two roads - the hub and a reload - is one row.
        // That is the whole reason the server sends one shape down both.
        expect(bodies(panel).filter((b) => "Une question." === b)).toHaveLength(
            1,
        );
    });

    it("removes a message the studio deleted", async () => {
        const listeners = {};
        vi.stubGlobal(
            "EventSource",
            class {
                constructor() {
                    Object.defineProperty(this, "onmessage", {
                        set: (fn) => (listeners.message = fn),
                    });
                    Object.defineProperty(this, "onopen", { set: () => {} });
                    Object.defineProperty(this, "onerror", { set: () => {} });
                }

                close() {}
            },
        );

        const panel = render({
            streamUrl: "https://hub.test/.well-known/mercure",
        });

        listeners.message({ data: JSON.stringify({ id: 1, deleted: true }) });
        await flushPromises();

        expect(bodies(panel)).toEqual(["Parfait pour jeudi."]);
    });
});
