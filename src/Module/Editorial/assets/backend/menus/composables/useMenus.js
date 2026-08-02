import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";

/**
 * The menu list on the left, and the name/description form for the one
 * selected. Entries are the other composable's job.
 */
export function useMenus(props) {
    const { t } = useI18n();

    const items = ref([...props.menus]);
    const selectedId = ref(items.value[0]?.id ?? null);
    const selected = computed(
        () => items.value.find((menu) => menu.id === selectedId.value) ?? null,
    );

    /**
     * Every endpoint answers with the whole menu, so the screen replaces
     * rather than patches: an entry moved, deleted or re-parented changes
     * more rows than the one that was acted on.
     */
    function upsert(menu) {
        if (!menu) return;

        const index = items.value.findIndex((item) => item.id === menu.id);
        if (index === -1) items.value.push(menu);
        else items.value[index] = menu;

        selectedId.value = menu.id;
    }

    const showEdit = ref(false);
    const editForm = ref({ name: "", description: "" });

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        url: () =>
            buildPath(props.updatePathTemplate, { id: selected.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("backend.menus.updated"));
            upsert(data?.menu);
        },
    });

    function openEdit() {
        editForm.value = {
            name: selected.value?.name ?? "",
            description: selected.value?.description ?? "",
        };
        clearEdit();
        showEdit.value = true;
    }

    return {
        items,
        selectedId,
        selected,
        upsert,
        showEdit,
        editForm,
        editErrors,
        editLoading,
        openEdit,
        submitEdit,
    };
}
