import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { nextTick } from "vue";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

vi.mock("@/shared/composables/http/suite/useRequest.js", async () => {
    const { ref } = await import("vue");

    return { useRequest: () => ({ request: vi.fn(), loading: ref(false) }) };
});

const { default: SpaceSettingsView } = await import("./SpaceSettingsView.vue");

/**
 * A space's Settings tab gathers the space form and the Drive. What would
 * break quietly: an editor who is not the lead offered the team (the server
 * refuses the change after the fact) or the Drive (its routes answer 404),
 * and the lead losing either.
 */
const SPACE_SETTINGS = {
    updatePath: "/suite/studio/spaces/7/update",
    customers: [],
    users: [],
    statuses: [],
    roles: [],
    timezones: ["Europe/Paris"],
    canCreateCustomer: false,
    space: {
        id: 7,
        name: "Atelier",
        customerId: 3,
        status: "active",
        colourSlot: 1,
        timezone: "Europe/Paris",
        members: [],
    },
};

const FIELDS = {
    name: "CustomerSpaceFormFields",
    props: {
        withIdentity: Boolean,
        withTeam: Boolean,
        canEditTeam: Boolean,
        modelValue: Object,
    },
    template: "<div />",
};

function mountView(props) {
    return mount(SpaceSettingsView, {
        props: { settingsPath: "/workspace/7/settings", ...props },
        global: {
            plugins: [createTestI18n()],
            stubs: {
                CustomerSpaceFormFields: FIELDS,
                SpaceDriveSettings: {
                    name: "SpaceDriveSettings",
                    props: ["settingsPath"],
                    template: "<div />",
                },
            },
        },
    });
}

function tabs(wrapper) {
    return wrapper.findAll("[aria-pressed]").map((tab) => tab.text());
}

describe("SpaceSettingsView", () => {
    it("shows an editor the space form, without the team or the Drive", () => {
        const wrapper = mountView({
            spaceSettings: SPACE_SETTINGS,
            canConfigure: false,
        });

        expect(tabs(wrapper)).toEqual([]);
        const fields = wrapper.findComponent({
            name: "CustomerSpaceFormFields",
        });
        expect(fields.props("withIdentity")).toBe(true);
        expect(fields.props("withTeam")).toBe(false);
        expect(
            wrapper.findComponent({ name: "SpaceDriveSettings" }).exists(),
        ).toBe(false);
    });

    it("gives the lead the space, the team and the Drive", async () => {
        const wrapper = mountView({
            spaceSettings: SPACE_SETTINGS,
            canConfigure: true,
        });

        expect(tabs(wrapper)).toEqual([
            "suite.studio.spaces.settings.section_general",
            "suite.studio.spaces.settings.section_team",
            "suite.studio.spaces.settings.section_drive",
        ]);

        await wrapper.findAll("[aria-pressed]")[1].trigger("click");
        const fields = wrapper.findComponent({
            name: "CustomerSpaceFormFields",
        });
        expect(fields.props("withIdentity")).toBe(false);
        expect(fields.props("withTeam")).toBe(true);
        expect(fields.props("canEditTeam")).toBe(true);

        await wrapper.findAll("[aria-pressed]")[2].trigger("click");
        await nextTick();
        expect(
            wrapper
                .findComponent({ name: "SpaceDriveSettings" })
                .props("settingsPath"),
        ).toBe("/workspace/7/settings");
        expect(
            wrapper.findComponent({ name: "CustomerSpaceFormFields" }).exists(),
        ).toBe(false);
    });

    it("keeps the Drive for a lead who may not edit the space", () => {
        const wrapper = mountView({ spaceSettings: null, canConfigure: true });

        expect(tabs(wrapper)).toEqual([]);
        expect(
            wrapper.findComponent({ name: "SpaceDriveSettings" }).exists(),
        ).toBe(true);
        expect(
            wrapper.findComponent({ name: "CustomerSpaceFormFields" }).exists(),
        ).toBe(false);
    });
});
