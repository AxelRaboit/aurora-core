import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { required } from "@/shared/utils/validation/validators.js";

function emptyForm(locales) {
    return {
        slug: "",
        hierarchical: false,
        postTypeIds: [],
        translations: Object.fromEntries(
            locales.map((locale) => [locale, { label: "", description: "" }]),
        ),
    };
}

function formFrom(taxonomy, locales) {
    return {
        slug: taxonomy.slug,
        hierarchical: taxonomy.hierarchical,
        postTypeIds: [...(taxonomy.postTypeIds ?? [])],
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                {
                    label: taxonomy.translations?.[locale]?.label ?? "",
                    description:
                        taxonomy.translations?.[locale]?.description ?? "",
                },
            ]),
        ),
    };
}

export function useTaxonomiesForm(props) {
    const { t } = useI18n();

    const items = ref([...props.taxonomies]);
    /**
     * Which taxonomy is on screen, and it is the address that says so. The
     * first of the list is only a fallback for the empty case the redirect
     * cannot cover.
     */
    const selectedId = ref(props.activeId ?? items.value[0]?.id ?? null);

    const selected = computed(
        () =>
            items.value.find((taxonomy) => taxonomy.id === selectedId.value) ??
            null,
    );

    function upsert(taxonomy) {
        if (!taxonomy) return;

        const known = items.value.some((item) => item.id === taxonomy.id);
        items.value = known
            ? items.value.map((item) =>
                  item.id === taxonomy.id ? taxonomy : item,
              )
            : [...items.value, taxonomy];

        selectedId.value = taxonomy.id;
    }

    const showCreate = ref(false);
    const createForm = ref(emptyForm(props.locales));

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => ({
            slug: () =>
                required(t("backend.taxonomies.errors.slug_required"))(
                    createForm.value.slug,
                ),
        }),
        url: () => props.createPath,
        body: () => createForm.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("backend.taxonomies.created"));
            upsert(data?.taxonomy);
        },
    });

    function openCreate() {
        createForm.value = emptyForm(props.locales);
        clearCreate();
        showCreate.value = true;
    }

    const showEdit = ref(false);
    const editing = ref(null);
    const editForm = ref(emptyForm(props.locales));

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        url: () =>
            buildPath(props.updatePathTemplate, { id: editing.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("backend.taxonomies.updated"));
            upsert(data?.taxonomy);
        },
    });

    function openEdit(taxonomy) {
        editing.value = taxonomy;
        editForm.value = formFrom(taxonomy, props.locales);
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
            items.value = items.value.filter((taxonomy) => taxonomy.id !== id);
            if (selectedId.value === id) {
                selectedId.value = items.value[0]?.id ?? null;
            }
        },
        "backend.taxonomies.deleted",
    );

    function togglePostType(form, postTypeId) {
        form.postTypeIds = form.postTypeIds.includes(postTypeId)
            ? form.postTypeIds.filter((id) => id !== postTypeId)
            : [...form.postTypeIds, postTypeId];
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
        togglePostType,
    };
}
