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

function client(channel, room, initial) {
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
                    writeBack: vi.fn(),
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
