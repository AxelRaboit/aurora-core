import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import NoteSpaceSettingsModal from "./NoteSpaceSettingsModal.vue";

const i18n = createTestI18n();

const SHARED = {
    id: 7,
    name: "Équipe",
    color: null,
    personal: false,
    access: "members",
    defaultRole: "reader",
    isOwner: true,
    ownerName: "Axel",
};

function fakeApi(
    space = SHARED,
    members = [{ userId: 3, name: "Marie", role: "editor" }],
    canPublish = false,
) {
    return {
        show: vi.fn().mockResolvedValue({
            ok: true,
            payload: { space, members, canPublish },
        }),
        publish: vi.fn().mockResolvedValue({
            ok: true,
            payload: {
                space: {
                    ...space,
                    published: true,
                    slug: "equipe",
                    publicUrl: "https://site.test/p/equipe",
                },
            },
        }),
        people: vi.fn().mockResolvedValue({
            ok: true,
            payload: {
                people: [
                    { id: 3, name: "Marie" },
                    { id: 4, name: "Jean" },
                ],
            },
        }),
        update: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        setMember: vi.fn().mockResolvedValue({
            ok: true,
            payload: {
                member: { userId: 4, name: "Jean", role: "reader" },
            },
        }),
        removeMember: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
    };
}

const mounted = [];

async function render(api) {
    const wrapper = mount(NoteSpaceSettingsModal, {
        props: { spaceId: 7, api },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe("NoteSpaceSettingsModal", () => {
    it("lists the members, and offers only the people not yet added", async () => {
        const wrapper = await render(fakeApi());

        expect(
            document.body.querySelector('[data-space-member="3"]').textContent,
        ).toContain("Marie");
        expect(wrapper.vm.$.setupState.candidates).toEqual([
            { value: 4, label: "Jean" },
        ]);
    });

    /** Adding a member applies right away, without waiting for "Enregistrer". */
    it("adds a member at once", async () => {
        const api = fakeApi();
        const wrapper = await render(api);

        wrapper.vm.$.setupState.newMemberId = 4;
        await flushPromises();
        document.body.querySelector("[data-space-add-member]").click();
        await flushPromises();

        expect(api.setMember).toHaveBeenCalledWith(7, 4, "reader");
        expect(
            document.body.querySelector('[data-space-member="4"]'),
        ).not.toBeNull();
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    it("saves the settings", async () => {
        const api = fakeApi();
        const wrapper = await render(api);

        wrapper.vm.$.setupState.access = "backoffice";
        await flushPromises();
        document.body.querySelector("[data-space-save]").click();
        await flushPromises();

        expect(api.update).toHaveBeenCalledWith(7, {
            name: "Équipe",
            color: null,
            access: "backoffice",
            defaultRole: "reader",
            // Sent even when off: the server tells an absent field from a
            // false one, and leaves the setting alone for the first.
            coediting: false,
        });
        expect(wrapper.emitted("close")).toBeTruthy();
    });

    /** Removing asks for a second click: a space takes everything it holds with it. */
    it("asks twice before removing a space", async () => {
        const api = fakeApi();
        await render(api);

        document.body.querySelector("[data-space-delete]").click();
        await flushPromises();

        expect(api.remove).not.toHaveBeenCalled();
        expect(
            document.body.querySelector("[data-space-confirm-delete]"),
        ).not.toBeNull();

        document.body.querySelector("[data-space-delete]").click();
        await flushPromises();

        expect(api.remove).toHaveBeenCalledWith(7);
    });

    /** One's own space neither opens nor is removed: the dialog does not offer it. */
    it("shows only the colour for one's personal space", async () => {
        await render(
            fakeApi(
                { ...SHARED, personal: true, name: null, access: "private" },
                [],
            ),
        );

        expect(document.body.querySelector("[data-space-name]")).toBeNull();
        expect(document.body.querySelector("[data-space-access]")).toBeNull();
        expect(document.body.querySelector("[data-space-members]")).toBeNull();
        expect(document.body.querySelector("[data-space-delete]")).toBeNull();
    });

    /** Publishing is only offered to whoever is allowed to; the address shows right away. */
    it("offers no publication without the right", async () => {
        await render(fakeApi());

        expect(
            document.body.querySelector("[data-space-publication]"),
        ).toBeNull();
    });

    it("publishes on the web for whoever may", async () => {
        const api = fakeApi(SHARED, [], true);
        await render(api);

        document.body.querySelector("[data-space-publish]").click();
        await flushPromises();

        expect(api.publish).toHaveBeenCalledWith(7, {
            published: true,
            slug: "",
            indexable: false,
        });
        expect(
            document.body.querySelector("[data-space-public-url]").textContent,
        ).toContain("https://site.test/p/equipe");
    });
});
