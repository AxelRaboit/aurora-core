import { afterEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, ref } from "vue";
import { flushPromises, mount } from "@vue/test-utils";
import { useNoteCoedit } from "./useNoteCoedit.js";

/**
 * Two clients on one note, joined by a bus that can lose a message.
 *
 * The bus delivers to everybody but the sender, as the hub does, and drops the
 * next message on demand: what a laptop asleep, a tab put to sleep or a hub
 * connection that dropped all look like from the other side.
 */
function bus() {
    const clients = [];
    let dropNext = false;

    return {
        drop() {
            dropNext = true;
        },
        channelFor(selfUserId) {
            const handlers = [];
            const client = { selfUserId, handlers };
            clients.push(client);

            return {
                selfUserId: () => selfUserId,
                onMessage(handler) {
                    handlers.push(handler);

                    return () => handlers.splice(handlers.indexOf(handler), 1);
                },
                publish(message) {
                    if (dropNext) {
                        dropNext = false;

                        return;
                    }
                    const sent = { ...message, from: selfUserId };
                    for (const other of clients) {
                        if (other === client) continue;
                        for (const handler of [...other.handlers])
                            handler(sent);
                    }
                },
            };
        },
    };
}

const mounted = [];

function client(channel, room, initial, writeBack = vi.fn()) {
    const text = ref(initial);
    const live = ref(false);
    const roomRef = ref(room);

    const wrapper = mount(
        defineComponent({
            setup() {
                const { enter } = useNoteCoedit({
                    noteId: ref(5),
                    allowed: ref(true),
                    text,
                    applyText: (value) => {
                        text.value = value;
                    },
                    room: roomRef,
                    channel,
                    writeBack,
                    live,
                });
                enter();

                return () => h("div");
            },
        }),
    );
    mounted.push(wrapper);

    return { text, live, room: roomRef };
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

async function settle() {
    await nextTick();
    await flushPromises();
    await nextTick();
}

describe("useNoteCoedit", () => {
    it("carries a keystroke from one client to the other", async () => {
        const line = bus();
        const first = client(line.channelFor(1), [], "hello");
        await settle();
        const second = client(line.channelFor(2), [{ userId: 1 }], "");
        first.room.value = [{ userId: 2 }];
        await settle();

        expect(second.live.value).toBe(true);
        expect(second.text.value).toBe("hello");

        first.text.value = "hello world";
        await settle();

        expect(second.text.value).toBe("hello world");
    });

    /**
     * A lost update used to freeze the other side for good: every later one
     * builds on it, so it was kept aside, and nothing typed afterwards showed
     * until a reload (09/10/2026, a paste that never reached a guest).
     */
    it("asks for what it missed and catches up", async () => {
        const line = bus();
        const first = client(line.channelFor(1), [], "hello");
        await settle();
        const second = client(line.channelFor(2), [{ userId: 1 }], "");
        first.room.value = [{ userId: 2 }];
        await settle();

        line.drop();
        first.text.value = "hello world";
        await settle();
        expect(second.text.value).toBe("hello");

        first.text.value = "hello world!";
        await settle();

        expect(second.text.value).toBe("hello world!");
    });

    it("catches up when the tab comes back to the front", async () => {
        const line = bus();
        const first = client(line.channelFor(1), [], "hello");
        await settle();
        const second = client(line.channelFor(2), [{ userId: 1 }], "");
        first.room.value = [{ userId: 2 }];
        await settle();

        line.drop();
        first.text.value = "hello again";
        await settle();
        expect(second.text.value).toBe("hello");

        document.dispatchEvent(new Event("visibilitychange"));
        await settle();

        expect(second.text.value).toBe("hello again");
    });
});

describe("useNoteCoedit write-back", () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    /**
     * A room that saved for nothing reloaded every screen on the echo, which
     * changed the text, which saved again: a blink every three and a half
     * seconds (09/10/2026).
     */
    it("does not write back what is already written", async () => {
        vi.useFakeTimers({
            toFake: [
                "setTimeout",
                "clearTimeout",
                "setInterval",
                "clearInterval",
            ],
        });
        const writeBack = vi.fn().mockResolvedValue(true);
        const alone = client(bus().channelFor(1), [], "hello", writeBack);
        await settle();

        alone.text.value = "hello world";
        await settle();
        vi.advanceTimersByTime(3_100);
        await settle();
        expect(writeBack).toHaveBeenCalledTimes(1);

        // Typed and taken back before the pause: the text is what is stored.
        alone.text.value = "hello world!";
        await settle();
        alone.text.value = "hello world";
        await settle();
        vi.advanceTimersByTime(3_100);
        await settle();

        expect(writeBack).toHaveBeenCalledTimes(1);
    });

    it("writes again after a write that failed", async () => {
        vi.useFakeTimers({
            toFake: [
                "setTimeout",
                "clearTimeout",
                "setInterval",
                "clearInterval",
            ],
        });
        const writeBack = vi
            .fn()
            .mockResolvedValueOnce(false)
            .mockResolvedValue(true);
        const alone = client(bus().channelFor(1), [], "hello", writeBack);
        await settle();

        alone.text.value = "hello world";
        await settle();
        vi.advanceTimersByTime(3_100);
        await settle();

        alone.text.value = "hello world!";
        await settle();
        alone.text.value = "hello world";
        await settle();
        vi.advanceTimersByTime(3_100);
        await settle();

        expect(writeBack).toHaveBeenCalledTimes(2);
    });

    /** Nothing typed since the note was opened: nothing to write. */
    it("does not write back the note it was opened with", async () => {
        vi.useFakeTimers({
            toFake: [
                "setTimeout",
                "clearTimeout",
                "setInterval",
                "clearInterval",
            ],
        });
        const writeBack = vi.fn().mockResolvedValue(true);
        const alone = client(bus().channelFor(1), [], "hello", writeBack);
        await settle();

        alone.text.value = "hello!";
        await settle();
        alone.text.value = "hello";
        await settle();
        vi.advanceTimersByTime(3_100);
        await settle();

        expect(writeBack).not.toHaveBeenCalled();
    });
});

describe("useNoteCoedit, the elected client", () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it("writes back what somebody else typed", async () => {
        vi.useFakeTimers({
            toFake: [
                "setTimeout",
                "clearTimeout",
                "setInterval",
                "clearInterval",
            ],
        });
        const line = bus();
        const writeBack = vi.fn().mockResolvedValue(true);
        const account = client(line.channelFor(1), [], "hello", writeBack);
        await settle();
        const guest = client(
            line.channelFor(1_000_000_001),
            [{ userId: 1 }],
            "",
        );
        account.room.value = [{ userId: 1_000_000_001 }];
        await settle();
        expect(guest.text.value).toBe("hello");
        // The write the room schedules when it changes goes by first: the
        // keystroke below must be what schedules the next one.
        vi.advanceTimersByTime(3_100);
        await settle();
        writeBack.mockClear();

        guest.text.value = "hello from the guest";
        await settle();
        vi.advanceTimersByTime(3_100);
        await settle();

        expect(writeBack).toHaveBeenCalledWith("hello from the guest", null);
    });
});
