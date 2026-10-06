import { describe, it, expect, vi, beforeEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import PexelsTab from "./PexelsTab.vue";

const request = vi.fn();
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

const MESSAGES = {
    suite: {
        ged: {
            pexels: {
                settings: {
                    key_stored: "Une clé est enregistrée.",
                    key_hint: "Elle est enregistrée chiffrée.",
                    accepted_on: "Conditions acceptées le {date} par {user}.",
                    enabled_blocked:
                        "Acceptez les conditions et renseignez une clé.",
                },
            },
        },
    },
};

const DISABLED = {
    enabled: false,
    hasKey: false,
    acceptedAt: null,
    acceptedBy: null,
};
const ENABLED = {
    enabled: true,
    hasKey: true,
    acceptedAt: "2026-09-06T12:00:00+00:00",
    acceptedBy: "axel@example.com",
};

function mountTab() {
    return mount(PexelsTab, {
        global: {
            plugins: [createTestI18n(MESSAGES)],
            stubs: { AppLoader: true },
        },
    });
}

beforeEach(() => {
    request.mockReset();
});

describe("PexelsTab", () => {
    it("starts from what the server says, off by default", async () => {
        request.mockResolvedValue(DISABLED);

        const wrapper = mountTab();
        await flushPromises();

        expect(wrapper.vm.canEnable).toBe(false);
    });

    /**
     * The rule the whole tab exists for: nobody switches on an integration
     * whose terms they have not accepted.
     */
    it("cannot be enabled without both an acceptance and a key", async () => {
        request.mockResolvedValue(DISABLED);

        const wrapper = mountTab();
        await flushPromises();

        wrapper.vm.apply({
            ...DISABLED,
            acceptedAt: "2026-09-06T12:00:00+00:00",
        });
        expect(wrapper.vm.canEnable).toBe(false);

        wrapper.vm.apply({ ...DISABLED, hasKey: true });
        expect(wrapper.vm.canEnable).toBe(false);

        wrapper.vm.apply(ENABLED);
        expect(wrapper.vm.canEnable).toBe(true);
    });

    /**
     * The key is write-only from here: sending it back so a form could
     * pre-fill it would put it in the page source of every admin who opens
     * the tab.
     */
    it("never sends a key it was not given", async () => {
        request.mockResolvedValue(ENABLED);

        const wrapper = mountTab();
        await flushPromises();
        request.mockClear();
        request.mockResolvedValue(ENABLED);

        await wrapper.vm.save();

        expect(request).toHaveBeenCalledTimes(1);
        expect(request.mock.calls[0][1]).not.toHaveProperty("apiKey");
    });

    it("shows when the terms were accepted and by whom", async () => {
        request.mockResolvedValue(ENABLED);

        const wrapper = mountTab();
        await flushPromises();

        expect(wrapper.text()).toContain("axel@example.com");
    });
});
