import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

function emptyTerm(locales) {
    return {
        parentId: null,
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                { name: "", slug: "", description: "" },
            ]),
        ),
    };
}

function termForm(term, locales) {
    return {
        parentId: term.parentId ?? null,
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                {
                    name: term.translations?.[locale]?.name ?? "",
                    slug: term.translations?.[locale]?.slug ?? "",
                    description: term.translations?.[locale]?.description ?? "",
                },
            ]),
        ),
    };
}

/**
 * Flattens the terms of the selected taxonomy into display order -
 * parents immediately followed by their children - carrying the depth so
 * the template can indent without recursing.
 */
function flatten(terms) {
    const byParent = new Map();
    for (const term of terms) {
        const key = term.parentId ?? null;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key).push(term);
    }

    for (const siblings of byParent.values()) {
        siblings.sort((a, b) => a.position - b.position || a.id - b.id);
    }

    const rows = [];
    const walk = (parentId, depth) => {
        for (const term of byParent.get(parentId) ?? []) {
            rows.push({ ...term, depth });
            walk(term.id, depth + 1);
        }
    };
    walk(null, 0);

    return rows;
}

export function useTaxonomyTerms(props, selected, upsert) {
    const { t } = useI18n();
    const { request } = useRequest();

    const rows = computed(() => flatten(selected.value?.terms ?? []));

    const showTerm = ref(false);
    const editingTerm = ref(null);
    const form = ref(emptyTerm(props.locales));

    /** A term cannot be its own parent, nor sit under one of its children. */
    const parentOptions = computed(() => {
        const excluded = new Set();
        if (editingTerm.value) {
            const collect = (id) => {
                excluded.add(id);
                for (const row of rows.value) {
                    if (row.parentId === id) collect(row.id);
                }
            };
            collect(editingTerm.value.id);
        }

        return rows.value
            .filter((row) => !excluded.has(row.id))
            .map((row) => ({
                value: row.id,
                label: `${"\u00a0\u00a0".repeat(row.depth)}${row.translations?.[props.locales[0]]?.name ?? row.id}`,
            }));
    });

    const {
        errors: termErrors,
        loading: termLoading,
        submit: submitTerm,
        clearErrors: clearTerm,
    } = useFormAction({
        url: () =>
            editingTerm.value
                ? buildPath(props.termEditPathTemplate, {
                      id: selected.value.id,
                      termId: editingTerm.value.id,
                  })
                : buildPath(props.termCreatePathTemplate, {
                      id: selected.value.id,
                  }),
        body: () => form.value,
        onSuccess: (data) => {
            showTerm.value = false;
            toast.success(
                t(
                    editingTerm.value
                        ? "backend.taxonomies.terms.updated"
                        : "backend.taxonomies.terms.created",
                ),
            );
            upsert(data?.taxonomy);
        },
    });

    function openTermCreate() {
        editingTerm.value = null;
        form.value = emptyTerm(props.locales);
        clearTerm();
        showTerm.value = true;
    }

    function openTermEdit(term) {
        editingTerm.value = term;
        form.value = termForm(term, props.locales);
        clearTerm();
        showTerm.value = true;
    }

    const pendingTermDelete = ref(null);
    const termDeleteLoading = ref(false);

    async function deleteTerm() {
        if (termDeleteLoading.value || !pendingTermDelete.value) return;

        termDeleteLoading.value = true;
        try {
            const data = await request(
                buildPath(props.termDeletePathTemplate, {
                    id: selected.value.id,
                    termId: pendingTermDelete.value.id,
                }),
            );
            if (data?.success) {
                toast.success(t("backend.taxonomies.terms.deleted"));
                upsert(data.taxonomy);
                pendingTermDelete.value = null;
            }
        } finally {
            termDeleteLoading.value = false;
        }
    }

    /**
     * Swaps a term with the sibling above or below it and posts the whole
     * sibling set - the endpoint takes a tree, not a single move, so that
     * a reorder and a re-parent travel the same path.
     */
    async function move(term, offset) {
        const siblings = rows.value.filter(
            (row) => (row.parentId ?? null) === (term.parentId ?? null),
        );
        const index = siblings.findIndex((row) => row.id === term.id);
        const target = index + offset;
        if (target < 0 || target >= siblings.length) return;

        const reordered = [...siblings];
        [reordered[index], reordered[target]] = [
            reordered[target],
            reordered[index],
        ];

        const entries = reordered.map((row, position) => ({
            id: row.id,
            parentId: row.parentId ?? null,
            position,
        }));

        const data = await request(
            buildPath(props.termReorderPathTemplate, { id: selected.value.id }),
            { entries },
        );
        if (data?.success) upsert(data.taxonomy);
    }

    return {
        rows,
        showTerm,
        editingTerm,
        form,
        termErrors,
        termLoading,
        parentOptions,
        openTermCreate,
        openTermEdit,
        submitTerm,
        pendingTermDelete,
        termDeleteLoading,
        deleteTerm,
        move,
    };
}
