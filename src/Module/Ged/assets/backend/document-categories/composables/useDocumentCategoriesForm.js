import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { required } from "@/shared/utils/validation/validators.js";

// `extraFields` is the client extension point: `{ color: { default: "",
// fromEntity: (cat) => cat.color ?? "" } }`. Merging the keys in here is what
// makes them travel — `body: () => form.value` submits the whole object, so a
// client field reaches the server without this file naming it.
function emptyForm(extraFields) {
    return {
        name: "",
        description: "",
        ...Object.fromEntries(
            Object.entries(extraFields).map(([key, field]) => [
                key,
                field.default ?? "",
            ]),
        ),
    };
}

export function useDocumentCategoriesForm(
    createPath,
    updatePath,
    deletePath,
    reset,
    extraFields = {},
) {
    const { t } = useI18n();

    // ── Create ───────────────────────────────────────────────────────────────
    const showCreate = ref(false);
    const newCategory = ref(emptyForm(extraFields));

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(t("backend.ged.categories.errors.name_required"))(
                    newCategory.value.name,
                ),
        }),
        url: () => createPath,
        body: () => newCategory.value,
        onSuccess: () => {
            showCreate.value = false;
            toast.success(t("backend.ged.categories.created"));
            reset();
        },
    });

    function openCreate() {
        newCategory.value = emptyForm();
        clearCreate();
        showCreate.value = true;
    }

    // ── Edit ─────────────────────────────────────────────────────────────────
    const showEdit = ref(false);
    const editingCategory = ref(null);
    const editForm = ref(emptyForm(extraFields));

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(t("backend.ged.categories.errors.name_required"))(
                    editForm.value.name,
                ),
        }),
        url: () => buildPath(updatePath, { id: editingCategory.value.id }),
        body: () => editForm.value,
        onSuccess: () => {
            showEdit.value = false;
            toast.success(t("backend.ged.categories.updated"));
            reset();
        },
    });

    function openEdit(category) {
        editingCategory.value = category;
        editForm.value = {
            name: category.name,
            description: category.description ?? "",
            ...Object.fromEntries(
                Object.entries(extraFields).map(([key, field]) => [
                    key,
                    field.fromEntity
                        ? field.fromEntity(category)
                        : (category[key] ?? ""),
                ]),
            ),
        };
        clearEdit();
        showEdit.value = true;
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    const {
        pendingDelete,
        loading: deleteLoading,
        confirm: confirmDelete,
        submit: doDelete,
    } = useDelete(deletePath, () => reset(), "backend.ged.categories.deleted");

    return {
        showCreate,
        newCategory,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        showEdit,
        editingCategory,
        editForm,
        editErrors,
        editLoading,
        openEdit,
        submitEdit,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
    };
}
