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

function propsWith(forms) {
    return {
        forms,
        locales: ["fr", "en"],
        fieldTypes: [{ value: "text", labelKey: "t", hasOptions: false }],
        conditionLogics: [{ value: "and", labelKey: "l" }],
        createPath: "/forms",
        updatePathTemplate: "/forms/__id__/update",
        deletePathTemplate: "/forms/__id__/delete",
        fieldCreatePathTemplate: "/forms/__id__/fields",
        fieldEditPathTemplate: "/forms/__id__/fields/__fieldId__/edit",
        fieldDeletePathTemplate: "/forms/__id__/fields/__fieldId__/delete",
        fieldReorderPathTemplate: "/forms/__id__/fields/reorder",
        submissionsPathTemplate: "/forms/__id__/submissions",
        exportPathTemplate: "/forms/__id__/submissions/export",
    };
}

const render = (forms) =>
    mount(FormsApp, { props: propsWith(forms), global: { plugins: [i18n] } });

describe("FormsApp", () => {
    /**
     * The case that was broken, and the only one that mattered: a fresh
     * installation. The editor modal sat inside the `v-else` branch that only
     * renders once a form exists, so the empty state's button set the flag and
     * nothing was mounted to read it. The first form could never be created.
     */
    it("mounts the editor modal even with no form yet", () => {
        expect(render([]).findComponent({ name: "AppModal" }).exists()).toBe(
            true,
        );
    });

    it("mounts it with forms too", () => {
        const wrapper = render([
            { id: 1, translations: {}, active: true, submissionCount: 0 },
        ]);
        expect(wrapper.findComponent({ name: "AppModal" }).exists()).toBe(true);
    });
});
