import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

function emptyField(locales, type = "text", step = null) {
    return {
        type,
        required: false,
        step,
        conditions: [],
        conditionsLogic: "and",
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                { label: "", placeholder: "", options: "" },
            ]),
        ),
    };
}

function fieldFrom(field, locales) {
    return {
        type: field.type,
        required: field.required,
        step: field.step,
        conditions: (field.conditions ?? []).map((condition) => ({
            ...condition,
        })),
        conditionsLogic: field.conditionsLogic ?? "and",
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                {
                    label: field.translations?.[locale]?.label ?? "",
                    placeholder:
                        field.translations?.[locale]?.placeholder ?? "",
                    // Options are edited one per line, the same as a post
                    // type's select choices.
                    options: (field.translations?.[locale]?.options ?? []).join(
                        "\n",
                    ),
                },
            ]),
        ),
    };
}

/** One choice per line, blank lines dropped: what the textarea means. */
export function splitOptions(text) {
    return (text ?? "")
        .split("\n")
        .map((option) => option.trim())
        .filter(Boolean);
}

function toPayload(draft, hasSteps) {
    return {
        ...draft,
        // Sans étapes, un champ n'en porte aucune : le serveur refuserait un
        // numéro d'étape sur un formulaire qui n'en compte pas.
        step: hasSteps ? (draft.step ?? 1) : null,
        // Une condition à moitié remplie n'en est pas une.
        conditions: draft.conditions.filter((condition) => condition.fieldId),
        translations: Object.fromEntries(
            Object.entries(draft.translations).map(([locale, translation]) => [
                locale,
                { ...translation, options: splitOptions(translation.options) },
            ]),
        ),
    };
}

/**
 * The questions of one form: their order, the one being edited, and the
 * requests that change them. Every endpoint answers with the whole form, which
 * `upsert` puts back on screen.
 */
export function useFormFields(props, form, upsert) {
    const { t } = useI18n();
    const { request } = useRequest();

    const fields = computed(() =>
        [...(form.value?.fields ?? [])].sort((a, b) => a.position - b.position),
    );

    const hasSteps = computed(() => (form.value?.steps?.length ?? 0) > 0);

    /** Les questions d'une étape, dans leur ordre ; toutes quand il n'y a pas d'étape. */
    function fieldsOfStep(number) {
        if (!hasSteps.value) return fields.value;

        return fields.value.filter((field) => (field.step ?? 1) === number);
    }

    // ── Édition ─────────────────────────────────────────────────────────────

    /** Vrai tant qu'une question est ouverte, nouvelle ou non. */
    const editorOpen = ref(false);
    const editingField = ref(null);
    const draft = ref(emptyField(props.locales));

    const typeMeta = computed(
        () =>
            props.fieldTypes.find((type) => type.value === draft.value.type) ??
            null,
    );

    /**
     * A question can only depend on one placed before it: a condition on a
     * later one could never be answered in time, and two questions depending
     * on each other would hide both for good. "Before" is the order the
     * visitor meets them in - step first, then position.
     */
    const conditionSources = computed(() => {
        const ordered = hasSteps.value
            ? [...fields.value].sort(
                  (a, b) =>
                      (a.step ?? 1) - (b.step ?? 1) || a.position - b.position,
              )
            : fields.value;

        const limit = editingField.value
            ? ordered.findIndex((field) => field.id === editingField.value.id)
            : ordered.length;

        return ordered
            .slice(0, limit === -1 ? ordered.length : limit)
            .filter(
                (field) =>
                    !editingField.value || field.id !== editingField.value.id,
            );
    });

    const {
        errors: fieldErrors,
        loading: fieldLoading,
        submit: submitField,
        clearErrors: clearField,
    } = useFormAction({
        url: () =>
            editingField.value
                ? buildPath(props.fieldEditPathTemplate, {
                      fieldId: editingField.value.id,
                  })
                : props.fieldCreatePath,
        body: () => toPayload(draft.value, hasSteps.value),
        onSuccess: (data) => {
            toast.success(
                t(
                    editingField.value
                        ? "backend.forms.fields.updated"
                        : "backend.forms.fields.created",
                ),
            );
            upsert(data?.form);
            closeEditor();
        },
    });

    function openFieldCreate(type, step = null) {
        editingField.value = null;
        draft.value = emptyField(
            props.locales,
            type,
            hasSteps.value ? (step ?? 1) : null,
        );
        clearField();
        editorOpen.value = true;
    }

    function openFieldEdit(field) {
        editingField.value = field;
        draft.value = fieldFrom(field, props.locales);
        clearField();
        editorOpen.value = true;
    }

    function closeEditor() {
        editorOpen.value = false;
        editingField.value = null;
    }

    function addCondition() {
        draft.value.conditions.push({ fieldId: null, value: "" });
    }

    function removeCondition(index) {
        draft.value.conditions.splice(index, 1);
    }

    // ── Suppression ─────────────────────────────────────────────────────────

    const pendingFieldDelete = ref(null);
    const fieldDeleteLoading = ref(false);

    async function deleteField() {
        if (fieldDeleteLoading.value || !pendingFieldDelete.value) return;

        fieldDeleteLoading.value = true;
        try {
            const data = await request(
                buildPath(props.fieldDeletePathTemplate, {
                    fieldId: pendingFieldDelete.value.id,
                }),
            );
            if (data?.success) {
                toast.success(t("backend.forms.fields.deleted"));
                if (editingField.value?.id === pendingFieldDelete.value.id)
                    closeEditor();
                upsert(data.form);
                pendingFieldDelete.value = null;
            }
        } finally {
            fieldDeleteLoading.value = false;
        }
    }

    // ── Ordre ───────────────────────────────────────────────────────────────

    /**
     * Swaps with the neighbour *in the same step* and posts the whole order.
     *
     * The list is drawn step by step: swapping with the global neighbour would
     * trade places with a question of another step, and on screen nothing
     * would move.
     */
    async function move(field, offset) {
        const group = fieldsOfStep(field.step ?? 1);
        const index = group.findIndex((item) => item.id === field.id);
        const neighbour = group[index + offset];
        if (!neighbour) return;

        const ordered = [...fields.value];
        const from = ordered.findIndex((item) => item.id === field.id);
        const to = ordered.findIndex((item) => item.id === neighbour.id);
        [ordered[from], ordered[to]] = [ordered[to], ordered[from]];

        const data = await request(props.fieldReorderPath, {
            entries: ordered.map((item, position) => ({
                id: item.id,
                position,
            })),
        });
        if (data?.success) upsert(data.form);
    }

    function canMove(field, offset) {
        const group = fieldsOfStep(field.step ?? 1);
        const index = group.findIndex((item) => item.id === field.id);

        return index + offset >= 0 && index + offset < group.length;
    }

    return {
        fields,
        hasSteps,
        fieldsOfStep,
        editorOpen,
        editingField,
        draft,
        typeMeta,
        conditionSources,
        fieldErrors,
        fieldLoading,
        submitField,
        openFieldCreate,
        openFieldEdit,
        closeEditor,
        addCondition,
        removeCondition,
        pendingFieldDelete,
        fieldDeleteLoading,
        deleteField,
        move,
        canMove,
    };
}
