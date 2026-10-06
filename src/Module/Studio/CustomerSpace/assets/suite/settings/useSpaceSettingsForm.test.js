import { afterEach, describe, expect, it, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { ref } from "vue";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const request = vi.fn();
const queueFlash = vi.fn();

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request, loading: ref(false) }),
}));
vi.mock("@/shared/utils/flash.js", () => ({
    queueFlash: (...args) => queueFlash(...args),
}));

const { useSpaceSettingsForm } = await import("./useSpaceSettingsForm.js");

/**
 * The space form of the Settings tab. What would break quietly: a save sent
 * elsewhere than the route the list's modal used, a form that drops the team
 * (the server would read it as a change and refuse a member), or a header left
 * stale after a rename.
 */
const SETTINGS = {
    updatePath: "/suite/studio/spaces/7/update",
    customers: [{ id: 3, name: "Atelier Dupont" }],
    space: {
        id: 7,
        name: "Atelier Dupont - Réseaux sociaux",
        description: "",
        customerId: 3,
        status: "active",
        colourSlot: 2,
        timezone: "Europe/Paris",
        members: [
            { userId: 1, role: "lead", name: "Axel", email: "a@example.test" },
        ],
    },
};

function mountWith(reload) {
    let api;
    mount(
        {
            setup() {
                api = useSpaceSettingsForm(SETTINGS, { reload });

                return () => null;
            },
        },
        { global: { plugins: [createTestI18n()] } },
    );

    return api;
}

afterEach(() => {
    request.mockReset();
    queueFlash.mockReset();
});

describe("useSpaceSettingsForm", () => {
    it("starts from the space, team included, and saves it through the update route", async () => {
        const reload = vi.fn();
        const api = mountWith(reload);
        request.mockResolvedValue({ success: true });

        expect(api.form.value).toMatchObject({
            name: "Atelier Dupont - Réseaux sociaux",
            customerId: 3,
            colourSlot: 2,
        });
        expect(api.form.value.members).toEqual([{ userId: 1, role: "lead" }]);
        expect(api.customerOptions.value).toEqual([
            { value: "3", label: "Atelier Dupont" },
        ]);

        api.form.value.name = "Atelier Dupont - Instagram";
        await api.submit();
        await flushPromises();

        expect(request).toHaveBeenCalledWith(
            "/suite/studio/spaces/7/update",
            expect.objectContaining({
                name: "Atelier Dupont - Instagram",
                members: [{ userId: 1, role: "lead" }],
            }),
        );
        // The shell's header is drawn by the server: the page reloads, the toast waits for it.
        expect(queueFlash).toHaveBeenCalledWith(
            "success",
            "suite.studio.spaces.settings.saved",
        );
        expect(reload).toHaveBeenCalled();
    });

    it("refuses an empty name before sending, and keeps the server's errors", async () => {
        const reload = vi.fn();
        const api = mountWith(reload);

        api.form.value.name = "";
        await api.submit();
        expect(request).not.toHaveBeenCalled();
        expect(api.errors.value.name).toBeTruthy();

        api.form.value.name = "Atelier";
        request.mockResolvedValue({
            success: false,
            errors: { members: "suite.studio.spaces.errors.team_lead_only" },
        });
        await api.submit();
        await flushPromises();
        expect(api.errors.value.members).toBe(
            "suite.studio.spaces.errors.team_lead_only",
        );
        expect(reload).not.toHaveBeenCalled();
    });
});
