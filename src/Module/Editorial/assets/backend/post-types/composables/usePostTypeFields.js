import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { required } from "@/shared/utils/validation/validators.js";

function emptyField() {
    return {
        name: "",
        label: "",
        type: "text",
        required: false,
        translatable: false,
        choices: "",
    };
}

/**
 * The `options` JSON column carries whatever a field type needs. The form
 * edits those extras as flat inputs and they are folded back in on submit,
 * so the wire format stays one shape per type rather than a union.
 */
function toOptions(form) {
    if ("select" === form.type) {
        return {
            choices: form.choices
                .split("\n")
                .map((choice) => choice.trim())
                .filter(Boolean),
        };
    }

    return {};
}

function fromOptions(field) {
    const options = field.options ?? {};

    return {
        name: field.name,
        label: field.label,
        type: field.type,
        required: field.required,
        translatable: field.translatable,
        choices: Array.isArray(options.choices)
            ? options.choices.join("\n")
            : "",
    };
}

export function usePostTypeFields(props, selected, upsert) {
    const { t } = useI18n();
    const { request } = useRequest();

    const showField = ref(false);
    const editingField = ref(null);
    const fieldForm = ref(emptyField());

    const {
        errors: fieldErrors,
        loading: fieldLoading,
        submit: submitField,
        clearErrors: clearField,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(t("backend.post_types.errors.field_name_required"))(
                    fieldForm.value.name,
                ),
            label: () =>
                required(t("backend.post_types.errors.field_label_required"))(
                    fieldForm.value.label,
                ),
        }),
        url: () =>
            editingField.value
                ? buildPath(props.fieldEditPathTemplate, {
                      id: selected.value.id,
                      fieldId: editingField.value.id,
                  })
                : buildPath(props.fieldCreatePathTemplate, {
                      id: selected.value.id,
                  }),
        body: () => ({
            name: fieldForm.value.name,
            label: fieldForm.value.label,
            type: fieldForm.value.type,
            required: fieldForm.value.required,
            translatable: fieldForm.value.translatable,
            options: toOptions(fieldForm.value),
        }),
        onSuccess: (data) => {
            showField.value = false;
            toast.success(
                t(
                    editingField.value
                        ? "backend.post_types.fields.updated"
                        : "backend.post_types.fields.created",
                ),
            );
            upsert(data?.postType);
        },
    });

    function openFieldCreate() {
        editingField.value = null;
        fieldForm.value = emptyField();
        clearField();
        showField.value = true;
    }

    function openFieldEdit(field) {
        editingField.value = field;
        fieldForm.value = fromOptions(field);
        clearField();
        showField.value = true;
    }

    const pendingFieldDelete = ref(null);
    const fieldDeleteLoading = ref(false);

    async function deleteField() {
        if (fieldDeleteLoading.value || !pendingFieldDelete.value) return;

        fieldDeleteLoading.value = true;
        try {
            const data = await request(
                buildPath(props.fieldDeletePathTemplate, {
                    id: selected.value.id,
                    fieldId: pendingFieldDelete.value.id,
                }),
            );
            if (data?.success) {
                toast.success(t("backend.post_types.fields.deleted"));
                upsert(data.postType);
                pendingFieldDelete.value = null;
            }
        } finally {
            fieldDeleteLoading.value = false;
        }
    }

    async function reorderFields(orderedIds) {
        const data = await request(
            buildPath(props.fieldReorderPathTemplate, {
                id: selected.value.id,
            }),
            { orderedIds },
        );
        if (data?.success) upsert(data.postType);
    }

    return {
        showField,
        editingField,
        fieldForm,
        fieldErrors,
        fieldLoading,
        openFieldCreate,
        openFieldEdit,
        submitField,
        pendingFieldDelete,
        fieldDeleteLoading,
        deleteField,
        reorderFields,
    };
}
