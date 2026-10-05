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

function mountApp() {
    return mount(InstancesApp, {
        props: {
            instances: [instance],
            forgetPath: "/dev/beacon/__id__/forget",
        },
        global: {
            plugins: [createTestI18n({}, "fr")],
            stubs: {
                AppRowActions: {
                    name: "AppRowActions",
                    props: ["actions", "label"],
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
});
