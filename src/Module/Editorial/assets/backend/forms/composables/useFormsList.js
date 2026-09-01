import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

function emptyForm(locales) {
    return {
        notifyEmail: "",
        webhookUrl: "",
        crmSync: false,
        active: true,
        steps: null,
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                { title: "", slug: "", description: "" },
            ]),
        ),
    };
}

function formFrom(form, locales) {
    return {
        notifyEmail: form.notifyEmail ?? "",
        webhookUrl: form.webhookUrl ?? "",
        crmSync: form.crmSync ?? false,
        active: form.active ?? true,
        steps: form.steps ? [...form.steps] : null,
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                {
                    title: form.translations?.[locale]?.title ?? "",
                    slug: form.translations?.[locale]?.slug ?? "",
                    description: form.translations?.[locale]?.description ?? "",
                },
            ]),
        ),
    };
}

export function useFormsList(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const items = ref([...props.forms]);
    /**
     * Which form is on screen, and it is the address that says so. The first of
     * the list is only a fallback for the empty case the redirect cannot cover.
     */
    const selectedId = ref(props.activeId ?? items.value[0]?.id ?? null);
    const selected = computed(
        () => items.value.find((form) => form.id === selectedId.value) ?? null,
    );

    /**
     * Every endpoint answers with the whole form. Adding a field changes the
     * order of the others and can drop conditions elsewhere, so the screen
     * replaces rather than patches.
     */
    function upsert(form) {
        if (!form) return;

        const index = items.value.findIndex((item) => item.id === form.id);
        if (index === -1) items.value.push(form);
        else items.value[index] = form;

        selectedId.value = form.id;
    }

    const showEditor = ref(false);
    const editing = ref(null);
    const editorForm = ref(emptyForm(props.locales));

    const { errors, loading, submit, clearErrors } = useFormAction({
        url: () =>
            editing.value
                ? buildPath(props.updatePathTemplate, { id: editing.value.id })
                : props.createPath,
        body: () => editorForm.value,
        onSuccess: (data) => {
            showEditor.value = false;
            toast.success(
                t(
                    editing.value
                        ? "backend.forms.updated"
                        : "backend.forms.created",
                ),
            );
            upsert(data?.form);
        },
    });

    function openCreate() {
        editing.value = null;
        editorForm.value = emptyForm(props.locales);
        clearErrors();
        showEditor.value = true;
    }

    function openEdit(form) {
        editing.value = form;
        editorForm.value = formFrom(form, props.locales);
        clearErrors();
        showEditor.value = true;
    }

    function addStep() {
        editorForm.value.steps = [
            ...(editorForm.value.steps ?? []),
            { title: "" },
        ];
    }

    function removeStep(index) {
        const steps = [...(editorForm.value.steps ?? [])];
        steps.splice(index, 1);
        // Back to null rather than an empty list: no steps at all is a
        // single-page form, which is not the same as a multi-step form with
        // none named.
        editorForm.value.steps = steps.length ? steps : null;
    }

    const pendingDelete = ref(null);
    const deleteLoading = ref(false);

    async function doDelete() {
        if (deleteLoading.value || !pendingDelete.value) return;

        deleteLoading.value = true;
        try {
            const data = await request(
                buildPath(props.deletePathTemplate, {
                    id: pendingDelete.value.id,
                }),
            );
            if (data?.success) {
                toast.success(t("backend.forms.deleted"));
                items.value = items.value.filter(
                    (form) => form.id !== pendingDelete.value.id,
                );
                selectedId.value = items.value[0]?.id ?? null;
                pendingDelete.value = null;
            }
        } finally {
            deleteLoading.value = false;
        }
    }

    return {
        items,
        selectedId,
        selected,
        upsert,
        showEditor,
        editing,
        editorForm,
        errors,
        loading,
        openCreate,
        openEdit,
        submit,
        addStep,
        removeStep,
        pendingDelete,
        deleteLoading,
        doDelete,
    };
}
