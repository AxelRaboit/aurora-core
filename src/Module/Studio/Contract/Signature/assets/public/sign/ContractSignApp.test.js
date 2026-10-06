import { describe, it, expect, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const request = vi.fn();

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

import ContractSignApp from "./ContractSignApp.vue";

const i18n = createTestI18n({}, "fr");

/** The product's guard: no code as long as the identity and the stroke are missing. */
function ready(wrapper) {
    Object.assign(wrapper.vm.form, {
        firstName: "Marie",
        lastName: "Dupont",
        email: "marie@societe.fr",
        place: "Lyon",
        date: "2026-11-15",
    });
    wrapper.vm.hasDrawn = true;
}

function build() {
    return mount(ContractSignApp, {
        props: {
            codePath: "/contracts/x/y/code",
            signPath: "/contracts/x/y/sign",
            refusePath: "/contracts/x/y/refuse",
        },
        global: {
            plugins: [i18n],
            stubs: {
                AppSignaturePad: true,
                AppModal: true,
                AppModalFooter: true,
            },
        },
    });
}

/**
 * What the client reads when sending the code fails.
 *
 * The screen switched to the "code sent" state whatever happened, and showed
 * "Code envoyé à ." with no address: the client then waited for a code nobody
 * had sent. Only the address returned by the server proves the sending took
 * place.
 */
describe("ContractSignApp, demande de code", () => {
    it("annonce l'envoi quand le serveur rend l'adresse", async () => {
        request.mockReset();
        request.mockResolvedValue({
            success: true,
            sentTo: "ma****@societe.fr",
        });

        const wrapper = build();
        ready(wrapper);
        await wrapper.vm.requestCode();
        await flushPromises();

        expect(wrapper.vm.codeSentTo).toBe("ma****@societe.fr");
        expect(wrapper.vm.errors.code).toBeUndefined();
    });

    it("signale l'échec plutôt que d'annoncer un envoi qui n'a pas eu lieu", async () => {
        request.mockReset();
        // What the helper returns when the request failed.
        request.mockResolvedValue(null);

        const wrapper = build();
        ready(wrapper);
        await wrapper.vm.requestCode();
        await flushPromises();

        expect(wrapper.vm.codeSentTo).toBeNull();
        expect(wrapper.vm.errors.code).toBeTruthy();
    });
});

/**
 * The attempt limit is spelled out.
 *
 * A 429 became a "Une erreur est survenue" toast: the client tried again, and
 * hit the same limit without knowing they had to wait.
 */
describe("ContractSignApp, limite de tentatives", () => {
    const LIMITED = {
        success: false,
        error: "studio.public.sign.errors.too_many_requests",
    };

    it("laisse passer le 429 jusqu'à l'écran", async () => {
        request.mockReset();
        request.mockResolvedValue(LIMITED);

        const wrapper = build();
        ready(wrapper);
        await wrapper.vm.requestCode();

        expect(request.mock.calls[0][2].accept).toContain(429);
        expect(wrapper.vm.errors.code).toBeTruthy();
        expect(wrapper.vm.codeSentTo).toBeNull();
    });

    it("le dit au-dessus du formulaire quand la signature est refusée", async () => {
        request.mockReset();
        request.mockResolvedValueOnce({
            success: true,
            sentTo: "ma****@societe.fr",
        });

        const wrapper = build();
        ready(wrapper);
        await wrapper.vm.requestCode();
        Object.assign(wrapper.vm.form, { code: "123456", consent: true });
        wrapper.vm.hasRead = true;

        request.mockResolvedValueOnce(LIMITED);
        await wrapper.vm.sign();

        expect(request.mock.calls[1][2].accept).toContain(429);
        expect(wrapper.vm.errors.status).toBeTruthy();
        expect(wrapper.vm.signed).toBe(false);
    });
});
