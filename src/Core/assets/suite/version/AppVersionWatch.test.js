import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises, enableAutoUnmount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const requestMock = vi.fn();
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request: requestMock, loading: { value: false } }),
}));

import AppVersionWatch from "./AppVersionWatch.vue";

enableAutoUnmount(afterEach);

function render(props = {}) {
    return mount(AppVersionWatch, {
        props: { version: "v3.8.3", versionPath: "/suite/version", ...props },
        global: { plugins: [createTestI18n()] },
    });
}

/** Lets the minimum gap since the page was rendered go by. */
function later() {
    vi.setSystemTime(Date.now() + 61 * 1000);
}

describe("AppVersionWatch", () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ["Date", "setInterval", "clearInterval"] });
        requestMock.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("says nothing while the server runs the page's version", async () => {
        requestMock.mockResolvedValue({ success: true, version: "v3.8.3" });
        const wrapper = render();

        later();
        await wrapper.vm.check();
        await flushPromises();

        expect(requestMock).toHaveBeenCalledWith(
            "/suite/version",
            null,
            expect.objectContaining({ method: "GET", silent: true }),
        );
        expect(wrapper.find("[data-new-version]").exists()).toBe(false);
    });

    it("offers to reload once the server runs a newer version", async () => {
        requestMock.mockResolvedValue({ success: true, version: "v3.8.4" });
        const wrapper = render();

        later();
        await wrapper.vm.check();
        await flushPromises();

        const banner = wrapper.find("[data-new-version]");
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain("suite.version.available");
        expect(wrapper.find("[data-new-version-reload]").exists()).toBe(true);
    });

    it("does not ask again right after the page was rendered", async () => {
        requestMock.mockResolvedValue({ success: true, version: "v3.8.4" });
        const wrapper = render();

        await wrapper.vm.check();

        expect(requestMock).not.toHaveBeenCalled();
    });

    it("asks when the tab comes back to the front", async () => {
        requestMock.mockResolvedValue({ success: true, version: "v3.8.4" });
        const wrapper = render();

        later();
        document.dispatchEvent(new Event("visibilitychange"));
        await flushPromises();

        expect(requestMock).toHaveBeenCalledOnce();
        expect(wrapper.find("[data-new-version]").exists()).toBe(true);
    });

    it("stays silent on a development checkout", async () => {
        const wrapper = render({ version: "dev" });

        later();
        await wrapper.vm.check();

        expect(requestMock).not.toHaveBeenCalled();
    });

    it("stays silent when the answer is not a version", async () => {
        requestMock.mockResolvedValue(null);
        const wrapper = render();

        later();
        await wrapper.vm.check();
        await flushPromises();

        expect(wrapper.find("[data-new-version]").exists()).toBe(false);
    });

    it("goes away for the visit when set aside", async () => {
        requestMock.mockResolvedValue({ success: true, version: "v3.8.4" });
        const wrapper = render();

        later();
        await wrapper.vm.check();
        await flushPromises();
        await wrapper.find("[data-new-version-dismiss]").trigger("click");

        expect(wrapper.find("[data-new-version]").exists()).toBe(false);
    });
});
