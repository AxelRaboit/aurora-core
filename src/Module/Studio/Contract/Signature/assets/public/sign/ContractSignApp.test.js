import { describe, it, expect, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const request = vi.fn();

vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

import ContractSignApp from "./ContractSignApp.vue";

const i18n = createTestI18n({}, "fr");

/** La garde du produit : pas de code tant que l'identité et le trait manquent. */
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
 * Ce que le client lit quand l'envoi du code échoue.
 *
 * L'écran basculait dans l'état « code envoyé » quoi qu'il arrive, et
 * affichait « Code envoyé à . » sans adresse : le client attendait alors un
 * code que personne n'avait expédié. Seule l'adresse rendue par le serveur
 * prouve que l'envoi a eu lieu.
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
        // Ce que rend le helper quand la requête a échoué.
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
 * La limite de tentatives se dit en clair.
 *
 * Un 429 devenait un toast « Une erreur est survenue » : le client
 * réessayait, et retombait sur la même limite sans savoir qu'il fallait
 * attendre.
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
