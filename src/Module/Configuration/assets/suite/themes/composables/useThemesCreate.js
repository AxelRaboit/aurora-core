import { reactive } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * @typedef {Object} ExtraField
 * @property {*} default - Initial/reset value for this field.
 */

export function useThemesCreate(themeList, createPath, options = {}) {
    const { t } = useI18n();
    const extraFields = options.extraFields ?? {};

    const createModal = reactive({ open: false, saving: false, errors: {} });
    const createForm = reactive({
        name: "",
        slug: "",
        description: "",
        ...Object.fromEntries(
            Object.entries(extraFields).map(([key, def]) => [key, def.default]),
        ),
    });

    const { request } = useRequest();

    function openCreate() {
        createModal.errors = {};
        createForm.name = "";
        createForm.slug = "";
        createForm.description = "";
        for (const [key, def] of Object.entries(extraFields)) {
            createForm[key] = def.default;
        }
        createModal.open = true;
    }

    async function submitCreate() {
        createModal.saving = true;
        createModal.errors = {};
        const data = await request(createPath, createForm);
        createModal.saving = false;
        if (!data) return;
        if (!data.success) {
            createModal.errors = data.errors ?? {};
            return;
        }
        themeList.value.push(data.theme);
        createModal.open = false;
        toast.success(t("suite.themes.created"));
    }

    return { createModal, createForm, openCreate, submitCreate };
}
