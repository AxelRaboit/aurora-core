import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { required } from "@/shared/utils/validation/validators.js";

function emptyForm() {
    return { name: "", color: null };
}

export function useDocumentTagsForm(
    initialTags,
    createPath,
    updatePath,
    deletePath,
) {
    const { t } = useI18n();

    const {
        items,
        searchInput: search,
        filteredItems,
    } = useClientFilteredList(initialTags, null, (tag, query) =>
        (tag.name ?? "").toLowerCase().includes(query),
    );

    function applyUpdatedList(data) {
        if (Array.isArray(data?.tags)) items.value = data.tags;
    }

    const showCreate = ref(false);
    const newTag = ref(emptyForm());

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(t("suite.ged.tags.errors.name_required"))(
                    newTag.value.name,
                ),
        }),
        url: () => createPath,
        body: () => newTag.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("suite.ged.tags.created"));
            applyUpdatedList(data);
        },
    });

    function openCreate() {
        newTag.value = emptyForm();
        clearCreate();
        showCreate.value = true;
    }

    const showEdit = ref(false);
    const editingTag = ref(null);
    const editForm = ref(emptyForm());

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(t("suite.ged.tags.errors.name_required"))(
                    editForm.value.name,
                ),
        }),
        url: () => buildPath(updatePath, { id: editingTag.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("suite.ged.tags.updated"));
            applyUpdatedList(data);
        },
    });

    function openEdit(tag) {
        editingTag.value = tag;
        editForm.value = {
            name: tag.name,
            color: tag.color ?? null,
        };
        clearEdit();
        showEdit.value = true;
    }

    const {
        pendingDelete,
        loading: deleteLoading,
        confirm: confirmDelete,
        submit: doDelete,
    } = useDelete(
        deletePath,
        (id) => {
            items.value = items.value.filter((tag) => tag.id !== id);
        },
        "suite.ged.tags.deleted",
    );

    return {
        items,
        search,
        filteredItems,
        showCreate,
        newTag,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        showEdit,
        editingTag,
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
