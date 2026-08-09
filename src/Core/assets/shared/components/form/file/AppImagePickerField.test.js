import { describe, it, expect, vi, beforeEach } from "vitest";
import { ref } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppImagePickerField from "./AppImagePickerField.vue";

// Stub the document picker utility to avoid DOM-level modal interactions
vi.mock("@/shared/utils/documentPicker.js", () => ({
    openDocumentPicker: vi.fn().mockResolvedValue(null),
}));

// usePrivileges reads window globals once at import, so it is mocked rather
// than driven: the point here is what the component does with the answer.
const can = vi.fn(() => false);
vi.mock("@/shared/composables/usePrivileges.js", () => ({
    usePrivileges: () => ({ can, isDev: false, isAdmin: false }),
}));

// Captures the callbacks the component hands the uploader, so a success and a
// failure can both be played back without a network.
let uploadHandlers = {};
vi.mock("@/shared/composables/http/backend/useImageUpload.js", () => ({
    useImageUpload: (handlers) => {
        uploadHandlers = handlers;

        return {
            uploading: ref(false),
            inputRef: ref(null),
            uploadFromEvent: vi.fn(),
        };
    },
}));

const UPLOAD_PRIVILEGE = "ged.documents.create";

function findUploadButton(wrapper) {
    return wrapper
        .findAll("button")
        .find((button) => button.text().includes("Parcourir"));
}

const i18n = createTestI18n({}, "en");

const globalConfig = {
    plugins: [i18n],
    stubs: {
        AppImage: {
            template: '<img :src="src" />',
            props: ["src", "alt", "objectFit"],
        },
    },
};

describe("AppImagePickerField", () => {
    beforeEach(() => {
        can.mockReturnValue(false);
        uploadHandlers = {};
    });

    it("renders label when label prop is set", () => {
        const wrapper = mount(AppImagePickerField, {
            props: {
                label: "Cover image",
                modelValue: { id: null, url: null },
            },
            global: globalConfig,
        });
        expect(wrapper.find("p").text()).toBe("Cover image");
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppImagePickerField, {
            props: {
                modelValue: { id: null, url: null },
                hint: "A square image crops best here",
            },
            global: globalConfig,
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "A square image crops best here",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("shows image when modelValue.url is set", () => {
        const wrapper = mount(AppImagePickerField, {
            props: {
                modelValue: { id: 1, url: "https://example.com/img.jpg" },
            },
            global: globalConfig,
        });
        expect(wrapper.find("img").exists()).toBe(true);
        expect(wrapper.find("img").attributes("src")).toBe(
            "https://example.com/img.jpg",
        );
    });

    it("shows at least two buttons when image url is set (change + remove)", () => {
        const wrapper = mount(AppImagePickerField, {
            props: {
                modelValue: { id: 1, url: "https://example.com/img.jpg" },
            },
            global: globalConfig,
        });
        const buttons = wrapper.findAll("button");
        expect(buttons.length).toBeGreaterThanOrEqual(2);
    });

    it("shows choose button (no image) when modelValue.url is null", () => {
        const wrapper = mount(AppImagePickerField, {
            props: { modelValue: { id: null, url: null } },
            global: globalConfig,
        });
        expect(wrapper.find("img").exists()).toBe(false);
        expect(wrapper.find("button").exists()).toBe(true);
    });

    it("emits update:modelValue with null values when remove is clicked", async () => {
        const wrapper = mount(AppImagePickerField, {
            props: {
                modelValue: { id: 1, url: "https://example.com/img.jpg" },
            },
            global: globalConfig,
        });
        // The ghost/remove button is the last button in the actions block
        const buttons = wrapper.findAll("button");
        const removeBtn = buttons[buttons.length - 1];
        await removeBtn.trigger("click");
        const emitted = wrapper.emitted("update:modelValue");
        expect(emitted).toBeTruthy();
        expect(emitted[0][0]).toEqual({ id: null, url: null });
    });

    // ── Browsing from the machine ─────────────────────────────────────────

    /**
     * The endpoint behind Browse is guarded by GED's own permission, not the
     * one that opened the form. An editor who may write posts but not file
     * documents would otherwise meet a 403 with nothing explaining it.
     */
    it("hides browse from someone who may not file documents", () => {
        const wrapper = mount(AppImagePickerField, {
            props: { modelValue: { id: null, url: null } },
            global: globalConfig,
        });

        expect(findUploadButton(wrapper)).toBeUndefined();
        expect(can).toHaveBeenCalledWith(UPLOAD_PRIVILEGE);
    });

    it("offers browse to someone who may", () => {
        can.mockReturnValue(true);

        const wrapper = mount(AppImagePickerField, {
            props: { modelValue: { id: null, url: null } },
            global: globalConfig,
        });

        expect(findUploadButton(wrapper)).toBeDefined();
    });

    it("offers browse next to an image that is already set", () => {
        can.mockReturnValue(true);

        const wrapper = mount(AppImagePickerField, {
            props: {
                modelValue: { id: 1, url: "https://example.com/img.jpg" },
            },
            global: globalConfig,
        });

        expect(findUploadButton(wrapper)).toBeDefined();
    });

    it("takes the uploaded image as its value", async () => {
        can.mockReturnValue(true);

        const wrapper = mount(AppImagePickerField, {
            props: { modelValue: { id: null, url: null } },
            global: globalConfig,
        });

        uploadHandlers.onSuccess({
            file: { id: 7, url: "/uploads/ged/2026/08/pixel.png" },
            media: { focalPositionCss: "50% 50%" },
        });

        expect(wrapper.emitted("update:modelValue")[0][0]).toEqual({
            id: 7,
            url: "/uploads/ged/2026/08/pixel.png",
        });
    });

    /** A failed upload must not clear the image that was already chosen. */
    it("keeps the current image when the upload fails", () => {
        can.mockReturnValue(true);

        const wrapper = mount(AppImagePickerField, {
            props: {
                modelValue: { id: 1, url: "https://example.com/img.jpg" },
            },
            global: globalConfig,
        });

        uploadHandlers.onError();

        expect(wrapper.emitted("update:modelValue")).toBeUndefined();
    });
});
