import { describe, expect, it, vi } from "vitest";
import { useSettingsForm } from "@configuration/suite/settings/composables/useSettingsForm.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

vi.mock("vue-sonner", () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request: vi.fn() }),
}));

/**
 * A setting whose "off" is a decision stops on the way down.
 *
 * The value must not move while the question is open: a modal that changes
 * the thing it is asking about has already answered for you, and closing it
 * would leave the toggle off with nobody having said yes.
 */
function form(parameters) {
    return useSettingsForm({ media: parameters }, ["media"], "/suite/settings");
}

const guarded = {
    key: "media_credit_visible",
    type: "bool",
    value: "1",
    label: "Afficher le crédit des photos",
    offWarning: "Les conditions d'usage de l'API demandent ce crédit.",
};

const ordinary = {
    key: "comments_enabled",
    type: "bool",
    value: "1",
    label: "Commentaires",
};

describe("useSettingsForm - switching a guarded setting off", () => {
    it("holds the value until the warning is answered", () => {
        const { fieldValues, onBoolChange, pendingOff } = form([guarded]);

        onBoolChange(guarded, false);

        expect(pendingOff.value).toStrictEqual(guarded);
        expect(fieldValues.media_credit_visible).toBe("1");
    });

    it("applies the change once confirmed", () => {
        const { fieldValues, onBoolChange, confirmOff, pendingOff } = form([
            guarded,
        ]);

        onBoolChange(guarded, false);
        confirmOff();

        expect(pendingOff.value).toBeNull();
        expect(fieldValues.media_credit_visible).toBe("0");
    });

    it("leaves it on when the warning is dismissed", () => {
        const { fieldValues, onBoolChange, cancelOff, pendingOff } = form([
            guarded,
        ]);

        onBoolChange(guarded, false);
        cancelOff();

        expect(pendingOff.value).toBeNull();
        expect(fieldValues.media_credit_visible).toBe("1");
    });

    /** Putting something back the way it was needs no confirmation. */
    it("asks nothing on the way back up", () => {
        const { fieldValues, onBoolChange, pendingOff } = form([guarded]);

        onBoolChange(guarded, false);
        onBoolChange(guarded, true);

        expect(pendingOff.value).toBeNull();
        expect(fieldValues.media_credit_visible).toBe("1");
    });
});

describe("useSettingsForm - ordinary settings", () => {
    it("switches off without asking", () => {
        const { fieldValues, onBoolChange, pendingOff } = form([ordinary]);

        onBoolChange(ordinary, false);

        expect(pendingOff.value).toBeNull();
        expect(fieldValues.comments_enabled).toBe("0");
    });
});
