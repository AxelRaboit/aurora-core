import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { toast } from "vue-sonner";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DeliverableLinksModal from "./DeliverableLinksModal.vue";

const request = vi.fn();

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));
vi.mock("vue-sonner", () => ({
    toast: { error: vi.fn(), success: vi.fn(), message: vi.fn() },
}));

const i18n = createTestI18n();

/**
 * La fenêtre des liens de lecture, une seule pour toutes les lignes d'une
 * liste.
 *
 * Ce qui se cassait sans bruit : ouvrir « Liens » sur un livrable B montrait
 * d'abord ceux de A (et les gardait si la requête de B échouait), et un mot
 * de passe tapé pour A sans être validé partait avec le lien créé pour B.
 */
const link = (id, label) => ({
    id,
    label,
    url: `https://x.test/deliverables/${id}`,
    expiresAt: null,
    revokedAt: null,
    locked: false,
    openCount: 0,
    lastUsedAt: null,
});

const opened = (id, label) => ({
    ...link(id, label),
    openCount: 3,
    lastUsedAt: "2026-10-04T09:00:00+00:00",
});

function mountModal(props = {}) {
    return mount(DeliverableLinksModal, {
        props: { show: true, linksPath: "/a/links", ...props },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

const body = () => document.body.textContent;

describe("DeliverableLinksModal", () => {
    beforeEach(() => {
        request.mockReset();
        toast.error.mockReset();
        toast.success.mockReset();
    });

    afterEach(() => (document.body.innerHTML = ""));

    it("forgets the previous deliverable's links and secrets when it opens on another", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [link(1, "Pour Alice")],
        });
        const wrapper = mountModal();
        await flushPromises();
        expect(body()).toContain("Pour Alice");

        // A password typed for A, never submitted.
        const password = wrapper
            .findAllComponents({ name: "AppInput" })
            .find((input) => "password" === input.props("type"));
        await password.setValue("secret de A");
        expect(password.props("modelValue")).toBe("secret de A");
        await wrapper.setProps({ show: false });

        // B's request fails: A's links must not be left under B's title.
        request.mockResolvedValueOnce(null);
        await wrapper.setProps({ linksPath: "/b/links", show: true });
        await flushPromises();

        expect(body()).not.toContain("Pour Alice");
        expect(document.body.querySelector("input[type=password]").value).toBe(
            "",
        );
        wrapper.unmount();
    });

    it("ignores the answer of an opening that was superseded", async () => {
        let answerA;
        request.mockImplementationOnce(
            () => new Promise((resolve) => (answerA = resolve)),
        );
        const wrapper = mountModal();

        request.mockResolvedValueOnce({
            success: true,
            links: [link(2, "Pour Bob")],
        });
        await wrapper.setProps({ linksPath: "/b/links" });
        await flushPromises();

        // A's slow answer arrives after B's: it must not overwrite it.
        answerA({ success: true, links: [link(1, "Pour Alice")] });
        await flushPromises();

        expect(body()).toContain("Pour Bob");
        expect(body()).not.toContain("Pour Alice");
        wrapper.unmount();
    });

    it("shows why the server refused to create a link, in its words", async () => {
        request.mockResolvedValueOnce({ success: true, links: [] });
        const wrapper = mountModal();
        await flushPromises();

        request.mockResolvedValueOnce({
            success: false,
            errors: {
                expiresInDays: "suite.studio.sharing.errors.expiry_invalid",
            },
        });
        document.body
            .querySelector("form")
            .dispatchEvent(new Event("submit", { cancelable: true }));
        await flushPromises();

        expect(toast.error).toHaveBeenCalledWith(
            "suite.studio.sharing.errors.expiry_invalid",
        );
        wrapper.unmount();
    });

    it("creates the link when the form is submitted, as Enter does", async () => {
        request.mockResolvedValueOnce({ success: true, links: [] });
        const wrapper = mountModal();
        await flushPromises();

        request.mockResolvedValueOnce({
            success: true,
            links: [link(3, "Neuf")],
        });
        document.body
            .querySelector("form")
            .dispatchEvent(new Event("submit", { cancelable: true }));
        await flushPromises();

        expect(request).toHaveBeenLastCalledWith(
            "/a/links/create",
            expect.objectContaining({ expiresInDays: null }),
        );
        expect(body()).toContain("Neuf");
        wrapper.unmount();
    });

    it("warns of the blanks that would leave with the address", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [],
            placeholders: 4,
            withheldPictures: [],
        });
        const wrapper = mountModal();
        await flushPromises();

        expect(body()).toContain("links.placeholders_title");
        wrapper.unmount();
    });

    it("asks once more before revoking, and revokes on the second step only", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [opened(1, "Pour Alice")],
        });
        const wrapper = mountModal();
        await flushPromises();

        const first = [...document.body.querySelectorAll("button")].find(
            (button) =>
                "suite.studio.deliverables.links.revoke" ===
                button.getAttribute("title"),
        );
        first.click();
        await flushPromises();

        // Nothing was revoked by the first click: the row asks first.
        expect(request).toHaveBeenCalledTimes(1);
        expect(body()).toContain("links.revoke_confirm");

        request.mockResolvedValueOnce({
            success: true,
            links: [
                {
                    ...opened(1, "Pour Alice"),
                    revokedAt: "2026-10-05T10:00:00+00:00",
                },
            ],
        });
        const confirm = [
            ...document.body.querySelectorAll("[role=alert] button"),
        ].find((button) => button.textContent.includes("links.revoke"));
        confirm.click();
        await flushPromises();

        expect(request).toHaveBeenLastCalledWith("/a/links/1/revoke");
        expect(body()).not.toContain("links.revoke_confirm");
        wrapper.unmount();
    });

    it("lets the author back out of a revocation", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [opened(1, "Pour Alice")],
        });
        const wrapper = mountModal();
        await flushPromises();

        [...document.body.querySelectorAll("button")]
            .find(
                (button) =>
                    "suite.studio.deliverables.links.revoke" ===
                    button.getAttribute("title"),
            )
            .click();
        await flushPromises();
        [...document.body.querySelectorAll("[role=alert] button")]
            .find((button) => button.textContent.includes("Annuler"))
            .click();
        await flushPromises();

        expect(body()).not.toContain("links.revoke_confirm");
        expect(request).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    const titled = (title) =>
        [...document.body.querySelectorAll("button")].find(
            (button) => title === button.getAttribute("title"),
        );

    it("offers to delete a link nobody opened, not to revoke it", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [link(1, "Jamais ouvert")],
        });
        const wrapper = mountModal();
        await flushPromises();

        expect(titled("suite.studio.deliverables.links.delete")).toBeTruthy();
        expect(
            titled("suite.studio.deliverables.links.revoke"),
        ).toBeUndefined();
        wrapper.unmount();
    });

    it("offers only revoking on a link that was opened, and nothing on one already revoked", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [
                opened(1, "Ouvert"),
                {
                    ...opened(2, "Déjà retiré"),
                    revokedAt: "2026-10-05T10:00:00+00:00",
                },
            ],
        });
        const wrapper = mountModal();
        await flushPromises();

        expect(
            document.body.querySelectorAll(
                'button[title="suite.studio.deliverables.links.revoke"]',
            ),
        ).toHaveLength(1);
        expect(
            titled("suite.studio.deliverables.links.delete"),
        ).toBeUndefined();
        wrapper.unmount();
    });

    it("asks before deleting, then deletes on the second step", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [link(1, "Jamais ouvert")],
        });
        const wrapper = mountModal();
        await flushPromises();

        titled("suite.studio.deliverables.links.delete").click();
        await flushPromises();
        expect(request).toHaveBeenCalledTimes(1);
        expect(body()).toContain("links.delete_confirm");

        request.mockResolvedValueOnce({ success: true, links: [] });
        [...document.body.querySelectorAll("[role=alert] button")]
            .find((button) => button.textContent.includes("links.delete"))
            .click();
        await flushPromises();

        expect(request).toHaveBeenLastCalledWith("/a/links/1/delete");
        expect(body()).not.toContain("Jamais ouvert");
        expect(toast.success).toHaveBeenCalledWith(
            "suite.studio.deliverables.links.deleted_toast",
        );
        wrapper.unmount();
    });

    it("says so and reloads when the link was opened in the meantime", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [link(1, "Ouvert entre-temps")],
        });
        const wrapper = mountModal();
        await flushPromises();

        titled("suite.studio.deliverables.links.delete").click();
        await flushPromises();

        request.mockResolvedValueOnce({
            success: false,
            errors: { link: "suite.studio.sharing.errors.link_opened" },
        });
        request.mockResolvedValueOnce({
            success: true,
            links: [opened(1, "Ouvert entre-temps")],
        });
        [...document.body.querySelectorAll("[role=alert] button")]
            .find((button) => button.textContent.includes("links.delete"))
            .click();
        await flushPromises();

        expect(toast.error).toHaveBeenCalledWith(
            "suite.studio.sharing.errors.link_opened",
        );
        // Reloaded: it now shows as a link that can only be revoked.
        expect(titled("suite.studio.deliverables.links.revoke")).toBeTruthy();
        wrapper.unmount();
    });

    it("lets the address be selected on its own, without the line under it", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [link(1, "Pour Alice")],
        });
        const wrapper = mountModal();
        await flushPromises();

        // A pasted address that carries « Sans expiration · Jamais ouvert » is a 404: the address is
        // one selectable block.
        const address = [...document.body.querySelectorAll("p")].find((p) =>
            p.textContent.includes("https://x.test/deliverables/1"),
        );
        expect(address.classList.contains("select-all")).toBe(true);
        expect(address.textContent).not.toContain("Jamais ouvert");
        wrapper.unmount();
    });

    const retired = (id, label) => ({
        ...opened(id, label),
        revokedAt: "2026-10-05T10:00:00+00:00",
    });

    it("offers to hide a retired link that was opened, and nothing on a live one", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [opened(1, "Vivant"), retired(2, "Retiré")],
        });
        const wrapper = mountModal();
        await flushPromises();

        expect(
            document.body.querySelectorAll(
                'button[title="suite.studio.deliverables.links.hide"]',
            ),
        ).toHaveLength(1);
        wrapper.unmount();
    });

    it("hides a retired link, keeps it out of the list and brings it back on request", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [retired(1, "Retiré")],
        });
        const wrapper = mountModal();
        await flushPromises();

        request.mockResolvedValueOnce({
            success: true,
            links: [{ ...retired(1, "Retiré"), hidden: true }],
        });
        titled("suite.studio.deliverables.links.hide").click();
        await flushPromises();

        expect(request).toHaveBeenLastCalledWith("/a/links/1/hide");
        expect(toast.success).toHaveBeenCalledWith(
            "suite.studio.deliverables.links.hidden_toast",
        );
        expect(body()).not.toContain("Retiré");
        expect(body()).toContain("links.show_hidden");

        // The toggle brings it back, tagged as hidden, and a second click puts it away again.
        const toggle = [...document.body.querySelectorAll("button")].find((b) =>
            b.textContent.includes("links.show_hidden"),
        );
        toggle.click();
        await flushPromises();
        expect(body()).toContain("Retiré");
        expect(body()).toContain("links.hide_hidden");
        // Already hidden: no second hide button.
        expect(titled("suite.studio.deliverables.links.hide")).toBeUndefined();
        wrapper.unmount();
    });

    it("says why a link could not be hidden and reloads", async () => {
        request.mockResolvedValueOnce({
            success: true,
            links: [retired(1, "Retiré")],
        });
        const wrapper = mountModal();
        await flushPromises();

        request.mockResolvedValueOnce({
            success: false,
            errors: { link: "suite.studio.sharing.errors.link_active" },
        });
        request.mockResolvedValueOnce({
            success: true,
            links: [opened(1, "Retiré")],
        });
        titled("suite.studio.deliverables.links.hide").click();
        await flushPromises();

        expect(toast.error).toHaveBeenCalledWith(
            "suite.studio.sharing.errors.link_active",
        );
        wrapper.unmount();
    });
});
