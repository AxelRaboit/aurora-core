import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

function emptyField(locales) {
    return {
        type: "text",
        required: false,
        step: null,
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

function toPayload(form) {
    return {
        ...form,
        translations: Object.fromEntries(
            Object.entries(form.translations).map(([locale, translation]) => [
                locale,
                {
                    ...translation,
                    options: translation.options
                        .split("\n")
                        .map((option) => option.trim())
                        .filter(Boolean),
                },
            ]),
        ),
    };
}

export function useFormFields(props, selected, upsert) {
    const { t } = useI18n();
    const { request } = useRequest();

    const fields = computed(() =>
        [...(selected.value?.fields ?? [])].sort(
            (a, b) => a.position - b.position,
        ),
    );

    const showField = ref(false);
    const editingField = ref(null);
    const fieldForm = ref(emptyField(props.locales));

    const typeMeta = computed(
        () =>
            props.fieldTypes.find(
                (type) => type.value === fieldForm.value.type,
            ) ?? null,
    );

    const typeOptions = computed(() =>
        props.fieldTypes.map((type) => ({
            value: type.value,
            label: t(type.labelKey),
        })),
    );

    const logicOptions = computed(() =>
        props.conditionLogics.map((logic) => ({
            value: logic.value,
            label: t(logic.labelKey),
        })),
    );

    /**
     * A field can only depend on one that comes before it: a condition on a
     * later field could never be answered in time, and two fields depending
     * on each other would hide both for good.
     */
    const conditionSources = computed(() => {
        const limit = editingField.value
            ? fields.value.findIndex(
                  (field) => field.id === editingField.value.id,
              )
            : fields.value.length;

        return fields.value
            .slice(0, limit === -1 ? fields.value.length : limit)
            .map((field) => ({
                value: field.id,
                label:
                    field.translations?.[props.locales[0]]?.label ??
                    `#${field.id}`,
            }));
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
                      id: selected.value.id,
                      fieldId: editingField.value.id,
                  })
                : buildPath(props.fieldCreatePathTemplate, {
                      id: selected.value.id,
                  }),
        body: () => toPayload(fieldForm.value),
        onSuccess: (data) => {
            showField.value = false;
            toast.success(
                t(
                    editingField.value
                        ? "backend.forms.fields.updated"
                        : "backend.forms.fields.created",
                ),
            );
            upsert(data?.form);
        },
    });

    function openFieldCreate() {
        editingField.value = null;
        fieldForm.value = emptyField(props.locales);
        clearField();
        showField.value = true;
    }

    function openFieldEdit(field) {
        editingField.value = field;
        fieldForm.value = fieldFrom(field, props.locales);
        clearField();
        showField.value = true;
    }

    function addCondition() {
        fieldForm.value.conditions.push({ fieldId: null, value: "" });
    }

    function removeCondition(index) {
        fieldForm.value.conditions.splice(index, 1);
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
                toast.success(t("backend.forms.fields.deleted"));
                upsert(data.form);
                pendingFieldDelete.value = null;
            }
        } finally {
            fieldDeleteLoading.value = false;
        }
    }

    /** Swaps with the neighbour and posts the whole order, like every other list. */
    async function move(field, offset) {
        const ordered = [...fields.value];
        const index = ordered.findIndex((item) => item.id === field.id);
        const target = index + offset;
        if (target < 0 || target >= ordered.length) return;

        [ordered[index], ordered[target]] = [ordered[target], ordered[index]];

        const data = await request(
            buildPath(props.fieldReorderPathTemplate, {
                id: selected.value.id,
            }),
            {
                entries: ordered.map((item, position) => ({
                    id: item.id,
                    position,
                })),
            },
        );
        if (data?.success) upsert(data.form);
    }

    return {
        fields,
        showField,
        editingField,
        fieldForm,
        fieldErrors,
        fieldLoading,
        typeMeta,
        typeOptions,
        logicOptions,
        conditionSources,
        openFieldCreate,
        openFieldEdit,
        submitField,
        addCondition,
        removeCondition,
        pendingFieldDelete,
        fieldDeleteLoading,
        deleteField,
        move,
    };
}
