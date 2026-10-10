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

/**
 * The tab's foot says how many changes wait, and "Cancel" puts the tab back
 * to what is saved - images included, whose preview lives beside the value.
 */
describe("useSettingsForm - changes waiting to be saved", () => {
    const name = {
        key: "site_name",
        type: "string",
        value: "Aurora",
        label: "Nom",
    };
    const logo = {
        key: "site_logo",
        type: "media",
        value: "12",
        mediaUrl: "/logo.png",
        label: "Logo",
    };

    it("counts nothing on a tab nobody touched", () => {
        const { pendingCount } = form([name, ordinary]);

        expect(pendingCount("media")).toBe(0);
    });

    it("counts each field that differs from what is saved", () => {
        const { fieldValues, onBoolChange, pendingCount } = form([
            name,
            ordinary,
        ]);

        fieldValues.site_name = "Aurora test";
        onBoolChange(ordinary, false);

        expect(pendingCount("media")).toBe(2);
    });

    it("stops counting a field typed back to its saved value", () => {
        const { fieldValues, pendingCount } = form([name]);

        fieldValues.site_name = "Aurora test";
        fieldValues.site_name = "Aurora";

        expect(pendingCount("media")).toBe(0);
    });

    it("puts every field back, image preview included", () => {
        const {
            fieldValues,
            mediaState,
            onMediaChange,
            resetGroup,
            pendingCount,
        } = form([name, logo]);

        fieldValues.site_name = "Aurora test";
        onMediaChange(logo, { id: 40, url: "/other.png" });
        resetGroup("media");

        expect(fieldValues.site_name).toBe("Aurora");
        expect(fieldValues.site_logo).toBe("12");
        expect(mediaState.site_logo).toStrictEqual({
            id: 12,
            url: "/logo.png",
        });
        expect(pendingCount("media")).toBe(0);
    });
});
