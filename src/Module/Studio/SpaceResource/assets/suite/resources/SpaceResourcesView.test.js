import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
// `usePrivileges` reads `window.__isAdmin__` **when the module loads**, not
// on mount: set further down, the flag would arrive after the component that
// uses it, the screen would think it is read-only and the buttons would not
// exist - a test that would pass without testing anything.
vi.hoisted(() => {
    window.__isAdmin__ = true;
});

import SpaceResourcesView from "./SpaceResourcesView.vue";

const i18n = createTestI18n();

/**
 * The resources of a space, seen from the studio.
 *
 * **What can break silently here is the separation of the two lists.** The
 * screen files what is shared and what is not in the same place; a closed
 * item that ended up on the wrong side would look like it works, and would
 * only show when compared with the client's page.
 *
 * The server has its own tests for the guarantee itself: a closed item does
 * not leave the server. This checks what the studio reads, which is the
 * other half - it must be able to tell at a glance what it has published.
 */
const PATHS = {
    resourceCreatePath: "/workspace/1/resources/create",
    resourceUpdatePath: "/workspace/1/resources/__id__/update",
    resourceVisibilityPath: "/workspace/1/resources/__id__/visibility",
    resourceDeletePath: "/workspace/1/resources/__id__/delete",
    resourceReorderPath: "/workspace/1/resources/reorder",
};

const OUVERT = {
    id: 1,
    kind: "link",
    label: "Maquette Canva",
    url: "https://canva.example.com/x",
    body: null,
    email: null,
    phone: null,
    visibleToClient: true,
};
const FERME = {
    id: 2,
    kind: "text",
    label: "Facturation",
    url: null,
    body: "Mensuelle, le 5.",
    email: null,
    phone: null,
    visibleToClient: false,
};

function monter(props = {}) {
    return mount(SpaceResourcesView, {
        global: { plugins: [i18n], stubs: { teleport: true } },
        props: { resources: [], ...PATHS, ...props },
    });
}

describe("SpaceResourcesView", () => {
    beforeEach(() => {
        vi.stubGlobal(
            "fetch",
            vi.fn(() => Promise.resolve({ ok: false })),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("sépare ce que le client voit de ce qui reste entre nous", async () => {
        const wrapper = monter({ resources: [OUVERT, FERME] });
        await flushPromises();

        const titres = wrapper
            .findAll("section h3")
            .map((heading) => heading.text());
        expect(titres).toHaveLength(2);
        expect(titres[0]).toContain("group_shown");
        expect(titres[1]).toContain("group_hidden");

        // Each under its own heading, and not mixed with a badge to spot.
        const sections = wrapper.findAll("section");
        expect(sections[0].text()).toContain("Maquette Canva");
        expect(sections[0].text()).not.toContain("Facturation");
        expect(sections[1].text()).toContain("Facturation");
    });

    it("ne dessine aucun groupe tant que rien n'est épinglé", async () => {
        const wrapper = monter();
        await flushPromises();

        expect(wrapper.findAll("section")).toHaveLength(0);
        expect(wrapper.text()).toContain("empty");
    });

    it("nomme le geste d'après l'état de la ligne", async () => {
        const wrapper = monter({ resources: [OUVERT, FERME] });
        await flushPromises();

        const sections = wrapper.findAll("section");

        // An open row offers to withdraw it, a closed row to show it. A single
        // label would have forced reading the icon to know which way it goes, on
        // the only gesture of the screen that publishes.
        expect(sections[0].text()).toContain("space_resources.hide");
        expect(sections[1].text()).toContain("space_resources.show");
    });

    it("ne dessine qu'un groupe quand tout est du même côté", async () => {
        const wrapper = monter({ resources: [FERME] });
        await flushPromises();

        // A "what the client sees" heading above an empty list would announce a
        // publication that did not happen.
        const titres = wrapper
            .findAll("section h3")
            .map((heading) => heading.text());
        expect(titres).toHaveLength(1);
        expect(titres[0]).toContain("group_hidden");
    });
});
