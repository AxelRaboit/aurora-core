import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createI18n } from "vue-i18n";

vi.mock("@/shared/composables/usePrivileges.js", () => ({
    usePrivileges: () => ({ can: () => true }),
}));

const FormsApp = (await import("./FormsApp.vue")).default;

const i18n = createI18n({
    legacy: false,
    locale: "fr",
    messages: { fr: {} },
    missingWarn: false,
    fallbackWarn: false,
});

const TEMPLATES = [
    {
        value: "blank",
        labelKey: "b",
        descriptionKey: "bd",
        fieldCount: 0,
        stepCount: 0,
    },
    {
        value: "contact",
        labelKey: "c",
        descriptionKey: "cd",
        fieldCount: 4,
        stepCount: 0,
    },
];

function row(overrides = {}) {
    return {
        id: 1,
        reference: "FRM-1",
        title: "Contact",
        description: null,
        active: true,
        fieldCount: 4,
        stepCount: 0,
        submissionCount: 2,
        lastSubmittedAt: null,
        updatedAt: "2026-10-01T10:00:00+02:00",
        editPath: "/suite/editorial/forms/1",
        ...overrides,
    };
}

const render = (forms) =>
    mount(FormsApp, {
        props: {
            forms,
            templates: TEMPLATES,
            createPath: "/forms",
            deletePathTemplate: "/forms/__id__/delete",
        },
        global: { plugins: [i18n] },
    });

describe("FormsApp", () => {
    /**
     * The case that was broken once, and the one that matters on a fresh
     * installation: the create modal used to live inside a branch that only
     * rendered once a form existed, so the first form could never be created.
     */
    it("mounts the create modal even with no form yet", () => {
        expect(render([]).findComponent({ name: "AppModal" }).exists()).toBe(
            true,
        );
    });

    /** The title is the way into a form: a link, not a menu entry. */
    it("links each form's title to its own page", () => {
        const wrapper = render([
            row(),
            row({
                id: 2,
                title: "Devis",
                editPath: "/suite/editorial/forms/2",
            }),
        ]);
        const links = wrapper
            .findAll("a")
            .map((link) => link.attributes("href"));

        expect(links).toContain("/suite/editorial/forms/1");
        expect(links).toContain("/suite/editorial/forms/2");
    });

    it("offers every template as a starting point", async () => {
        const wrapper = mount(FormsApp, {
            props: {
                forms: [],
                templates: TEMPLATES,
                createPath: "/forms",
                deletePathTemplate: "/forms/__id__/delete",
            },
            global: { plugins: [i18n] },
            attachTo: document.body,
        });

        // The empty state's own button: the one a fresh installation sees.
        await wrapper
            .findComponent({ name: "AppNoData" })
            .find("button")
            .trigger("click");
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.body.querySelectorAll('[role="radio"]')).toHaveLength(
            TEMPLATES.length,
        );
        wrapper.unmount();
    });
});
