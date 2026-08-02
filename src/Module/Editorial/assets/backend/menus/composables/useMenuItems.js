import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

function emptyItem(locales) {
    return {
        parentId: null,
        targetType: "custom_url",
        targetId: null,
        customUrl: "",
        openInNewTab: false,
        cssClass: "",
        visibility: "always",
        translations: Object.fromEntries(
            locales.map((locale) => [locale, { label: "" }]),
        ),
    };
}

function itemForm(item, locales) {
    return {
        parentId: item.parentId ?? null,
        targetType: item.targetType,
        targetId: item.targetId ?? null,
        customUrl: item.customUrl ?? "",
        openInNewTab: item.openInNewTab ?? false,
        cssClass: item.cssClass ?? "",
        visibility: item.visibility,
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                { label: item.translations?.[locale]?.label ?? "" },
            ]),
        ),
    };
}

/**
 * Flattens a menu's entries into display order — parents immediately
 * followed by their children — carrying the depth so the template can
 * indent without recursing.
 */
function flatten(items) {
    const byParent = new Map();
    for (const item of items) {
        const key = item.parentId ?? null;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key).push(item);
    }

    for (const siblings of byParent.values()) {
        siblings.sort((a, b) => a.position - b.position || a.id - b.id);
    }

    const rows = [];
    const walk = (parentId, depth) => {
        for (const item of byParent.get(parentId) ?? []) {
            rows.push({ ...item, depth });
            walk(item.id, depth + 1);
        }
    };
    walk(null, 0);

    return rows;
}

export function useMenuItems(props, selected, upsert) {
    const { t } = useI18n();
    const { request } = useRequest();

    const rows = computed(() => flatten(selected.value?.items ?? []));

    const showItem = ref(false);
    const editingItem = ref(null);
    const form = ref(emptyItem(props.locales));

    const targetTypeMeta = computed(
        () =>
            props.targetTypes.find(
                (type) => type.value === form.value.targetType,
            ) ?? null,
    );

    const targetTypeOptions = computed(() =>
        props.targetTypes.map((type) => ({
            value: type.value,
            label: t(type.labelKey),
        })),
    );

    const visibilityOptions = computed(() =>
        props.visibilities.map((visibility) => ({
            value: visibility.value,
            label: t(visibility.labelKey),
        })),
    );

    /** An entry cannot be its own parent, nor sit under one of its children. */
    const parentOptions = computed(() => {
        const excluded = new Set();
        if (editingItem.value) {
            const collect = (id) => {
                excluded.add(id);
                for (const row of rows.value) {
                    if (row.parentId === id) collect(row.id);
                }
            };
            collect(editingItem.value.id);
        }

        return rows.value
            .filter((row) => !excluded.has(row.id))
            .map((row) => ({
                value: row.id,
                label: `${"— ".repeat(row.depth)}${labelOf(row)}`,
            }));
    });

    function labelOf(item) {
        return (
            item.translations?.[props.locales[0]]?.label ||
            item.targetLabel ||
            `#${item.id}`
        );
    }

    // The picker's options belong to the target type, so switching type
    // reloads them and drops a selection that no longer means anything.
    const targetOptions = ref([]);
    const targetSearch = ref("");
    const targetLoading = ref(false);

    async function loadTargets() {
        if (!targetTypeMeta.value?.requiresTarget) {
            targetOptions.value = [];

            return;
        }

        targetLoading.value = true;
        try {
            // noGuard: typing in the search box fires one call per keystroke,
            // and the shared loading guard would drop all but the first.
            const data = await request(
                `${props.targetsPath}?type=${encodeURIComponent(form.value.targetType)}&q=${encodeURIComponent(targetSearch.value)}`,
                null,
                { method: HttpMethod.Get, noGuard: true },
            );
            targetOptions.value = (data?.options ?? []).map((option) => ({
                value: option.id,
                label: option.hint
                    ? `${option.label} — ${option.hint}`
                    : option.label,
            }));
        } finally {
            targetLoading.value = false;
        }
    }

    watch(
        () => form.value.targetType,
        (next, previous) => {
            if (undefined !== previous && next !== previous) {
                form.value.targetId = null;
            }
            targetSearch.value = "";
            void loadTargets();
        },
    );

    watch(targetSearch, () => void loadTargets());

    const {
        errors: itemErrors,
        loading: itemLoading,
        submit: submitItem,
        clearErrors: clearItem,
    } = useFormAction({
        url: () =>
            editingItem.value
                ? buildPath(props.itemEditPathTemplate, {
                      id: selected.value.id,
                      itemId: editingItem.value.id,
                  })
                : buildPath(props.itemCreatePathTemplate, {
                      id: selected.value.id,
                  }),
        body: () => form.value,
        onSuccess: (data) => {
            showItem.value = false;
            toast.success(
                t(
                    editingItem.value
                        ? "backend.menus.item_updated"
                        : "backend.menus.item_created",
                ),
            );
            upsert(data?.menu);
        },
    });

    function openItemCreate() {
        editingItem.value = null;
        form.value = emptyItem(props.locales);
        clearItem();
        showItem.value = true;
        void loadTargets();
    }

    function openItemEdit(item) {
        editingItem.value = item;
        form.value = itemForm(item, props.locales);
        clearItem();
        showItem.value = true;
        void loadTargets();
    }

    const pendingItemDelete = ref(null);
    const itemDeleteLoading = ref(false);

    async function deleteItem() {
        if (itemDeleteLoading.value || !pendingItemDelete.value) return;

        itemDeleteLoading.value = true;
        try {
            const data = await request(
                buildPath(props.itemDeletePathTemplate, {
                    id: selected.value.id,
                    itemId: pendingItemDelete.value.id,
                }),
            );
            if (data?.success) {
                toast.success(t("backend.menus.item_deleted"));
                upsert(data.menu);
                pendingItemDelete.value = null;
            }
        } finally {
            itemDeleteLoading.value = false;
        }
    }

    /**
     * Swaps an entry with the sibling above or below it and posts the whole
     * sibling set — the endpoint takes a tree, not a single move, so that a
     * reorder and a re-parent travel the same path.
     */
    async function move(item, offset) {
        const siblings = rows.value.filter(
            (row) => (row.parentId ?? null) === (item.parentId ?? null),
        );
        const index = siblings.findIndex((row) => row.id === item.id);
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
            buildPath(props.itemReorderPathTemplate, { id: selected.value.id }),
            { entries },
        );
        if (data?.success) upsert(data.menu);
    }

    return {
        rows,
        labelOf,
        showItem,
        editingItem,
        form,
        itemErrors,
        itemLoading,
        parentOptions,
        targetTypeMeta,
        targetTypeOptions,
        visibilityOptions,
        targetOptions,
        targetSearch,
        targetLoading,
        openItemCreate,
        openItemEdit,
        submitItem,
        pendingItemDelete,
        itemDeleteLoading,
        deleteItem,
        move,
    };
}
