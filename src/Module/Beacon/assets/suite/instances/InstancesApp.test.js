import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request: vi.fn(async () => ({ success: true })) }),
}));

import InstancesApp from "./InstancesApp.vue";

const instance = {
    id: 7,
    instanceId: "abc12345",
    domain: "copie.example",
    hostname: "srv1",
    appVersion: "v1.17.1",
    phpVersion: "8.4",
    signatureValid: false,
    known: false,
    pingCount: 3,
    lastIp: "203.0.113.9",
    firstSeenAt: "2026-10-05T10:00:00+00:00",
    lastSeenAt: "2026-10-05T12:00:00+00:00",
};

function mountApp(instances = [instance]) {
    return mount(InstancesApp, {
        props: {
            instances,
            forgetPath: "/dev/beacon/__id__/forget",
        },
        global: {
            plugins: [createTestI18n({}, "fr")],
            stubs: {
                AppRowActions: {
                    name: "AppRowActions",
                    props: { actions: Array, label: String, iconOnly: Boolean },
                    template: "<div />",
                },
                AppModal: {
                    name: "AppModal",
                    props: ["show"],
                    template:
                        '<div v-if="show"><slot /><slot name="footer" /></div>',
                },
            },
        },
    });
}

describe("InstancesApp", () => {
    it("shows the address the instance called from", () => {
        expect(mountApp().find("[data-beacon-ip]").text()).toBe("203.0.113.9");
    });

    it("forgets an instance through the house menu and modal, never the browser's confirm", async () => {
        const nativeConfirm = vi.spyOn(window, "confirm");
        const wrapper = mountApp();
        const [action] = wrapper
            .findComponent({ name: "AppRowActions" })
            .props("actions");

        expect(action.color).toBe("rose");
        action.onSelect();
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent({ name: "AppModal" }).props("show")).toBe(
            true,
        );
        expect(nativeConfirm).not.toHaveBeenCalled();
    });

    it("draws the forget gesture as its bare icon", () => {
        expect(
            mountApp()
                .findComponent({ name: "AppRowActions" })
                .props("iconOnly"),
        ).toBe(true);
    });

    it("pages the instances five at a time", async () => {
        const instances = Array.from({ length: 7 }, (_, index) => ({
            ...instance,
            id: index + 1,
            domain: `copie-${index + 1}.example`,
        }));
        const wrapper = mountApp(instances);

        expect(wrapper.findAll("tbody tr")).toHaveLength(5);

        wrapper.findComponent({ name: "AppPagination" }).vm.$emit("change", 2);
        await wrapper.vm.$nextTick();

        const domains = wrapper
            .findAll("tbody tr")
            .map((row) => row.findAll("td")[1].text());
        expect(domains).toEqual(["copie-6.example", "copie-7.example"]);
    });

    it("shows no pagination for five instances or fewer", () => {
        expect(
            mountApp().findComponent({ name: "AppPagination" }).exists(),
        ).toBe(false);
    });
});
