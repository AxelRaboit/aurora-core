import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { required } from "@/shared/utils/validation/validators.js";

function emptyForm(supportOptions) {
    return {
        slug: "",
        label: "",
        icon: "",
        hasArchive: false,
        supports: [...supportOptions],
    };
}

/**
 * Post types are a short list, so the whole set stays client-side and
 * every write hands back the serialized type it touched - the list is
 * patched from that rather than refetched.
 */
export function usePostTypesForm(props) {
    const { t } = useI18n();

    const items = ref([...props.postTypes]);
    const selectedId = ref(items.value[0]?.id ?? null);

    const selected = computed(
        () =>
            items.value.find((postType) => postType.id === selectedId.value) ??
            null,
    );

    function upsert(postType) {
        if (!postType) return;

        const index = items.value.findIndex((item) => item.id === postType.id);
        if (index === -1) {
            items.value = [...items.value, postType];
        } else {
            items.value = items.value.map((item) =>
                item.id === postType.id ? postType : item,
            );
        }

        selectedId.value = postType.id;
    }

    const showCreate = ref(false);
    const createForm = ref(emptyForm(props.supportOptions));

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => ({
            slug: () =>
                required(t("backend.post_types.errors.slug_required"))(
                    createForm.value.slug,
                ),
            label: () =>
                required(t("backend.post_types.errors.label_required"))(
                    createForm.value.label,
                ),
        }),
        url: () => props.createPath,
        body: () => createForm.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("backend.post_types.created"));
            upsert(data?.postType);
        },
    });

    function openCreate() {
        createForm.value = emptyForm(props.supportOptions);
        clearCreate();
        showCreate.value = true;
    }

    const showEdit = ref(false);
    const editing = ref(null);
    const editForm = ref(emptyForm(props.supportOptions));

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => ({
            label: () =>
                required(t("backend.post_types.errors.label_required"))(
                    editForm.value.label,
                ),
        }),
        url: () =>
            buildPath(props.updatePathTemplate, { id: editing.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("backend.post_types.updated"));
            upsert(data?.postType);
        },
    });

    function openEdit(postType) {
        editing.value = postType;
        editForm.value = {
            slug: postType.slug,
            label: postType.label,
            icon: postType.icon ?? "",
            hasArchive: postType.hasArchive,
            supports: [...(postType.supports ?? [])],
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
        props.deletePathTemplate,
        (id) => {
            items.value = items.value.filter((postType) => postType.id !== id);
            if (selectedId.value === id) {
                selectedId.value = items.value[0]?.id ?? null;
            }
        },
        "backend.post_types.deleted",
    );

    function toggleSupport(form, support) {
        form.supports = form.supports.includes(support)
            ? form.supports.filter((item) => item !== support)
            : [...form.supports, support];
    }

    return {
        items,
        selectedId,
        selected,
        upsert,
        showCreate,
        createForm,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        showEdit,
        editing,
        editForm,
        editErrors,
        editLoading,
        openEdit,
        submitEdit,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
        toggleSupport,
    };
}
