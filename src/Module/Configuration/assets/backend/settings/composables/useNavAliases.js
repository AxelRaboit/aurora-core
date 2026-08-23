import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

function parseJsonMap(raw) {
    try {
        const decoded = JSON.parse(raw ?? "{}");
        return decoded && typeof decoded === "object" && !Array.isArray(decoded)
            ? decoded
            : {};
    } catch {
        return {};
    }
}

function parseJsonList(raw) {
    try {
        const decoded = JSON.parse(raw ?? "[]");
        return Array.isArray(decoded)
            ? decoded.filter((v) => typeof v === "string")
            : [];
    } catch {
        return [];
    }
}

function findGroupValue(groups, key, fallback = "{}") {
    return groups?.navigation?.find?.((s) => s.key === key)?.value ?? fallback;
}

export function useNavAliases({ groups, navSections, updatePath }) {
    const { t } = useI18n();

    // Rename overrides.
    const sectionAliases = reactive(
        parseJsonMap(findGroupValue(groups, "nav_section_aliases")),
    );
    const itemAliases = reactive(
        parseJsonMap(findGroupValue(groups, "nav_item_aliases")),
    );

    // Order overrides - persisted state. `sectionOrder` is the list of
    // section IDs in the admin's chosen order; `itemOrder` maps section ID
    // to ordered list of NavItem route names.
    const sectionOrder = ref(
        parseJsonList(findGroupValue(groups, "nav_section_order", "[]")),
    );
    const itemOrder = reactive(
        parseJsonMap(findGroupValue(groups, "nav_item_order")),
    );

    const expandedSections = reactive(new Set());

    const { request } = useRequest();
    const saving = ref(false);

    // Live-preview ordering: reflect drag-drop reorders before save.
    // Returns a NEW array each call so VueDraggable's mutations on the
    // previous instance don't leak (we capture the new order via the
    // @update:model-value handler instead).
    const orderedSections = computed(() => {
        const sections = navSections ?? [];
        if (sectionOrder.value.length === 0) {
            return [...sections];
        }
        const byId = new Map(sections.map((s) => [s.id, s]));
        const result = [];
        for (const id of sectionOrder.value) {
            const section = byId.get(id);
            if (section) {
                result.push(section);
                byId.delete(id);
            }
        }
        // Append unmentioned sections in their natural priority order.
        for (const section of byId.values()) {
            result.push(section);
        }
        return result;
    });

    function orderedItems(section) {
        const items = section.items ?? [];
        const order = itemOrder[section.id];
        if (!Array.isArray(order) || order.length === 0) {
            return [...items];
        }
        const key = items[0]?.key ? "key" : "route";
        const byKey = new Map(items.map((i) => [i[key], i]));
        const result = [];
        for (const k of order) {
            const item = byKey.get(k);
            if (item) {
                result.push(item);
                byKey.delete(k);
            }
        }
        for (const item of byKey.values()) {
            result.push(item);
        }
        return result;
    }

    function toggleSection(sectionId) {
        if (expandedSections.has(sectionId)) {
            expandedSections.delete(sectionId);
        } else {
            expandedSections.add(sectionId);
        }
    }

    function isSectionExpanded(sectionId) {
        return expandedSections.has(sectionId);
    }

    function sectionDefaultLabel(section) {
        return t(`backend.nav.sections.${section.id}`);
    }

    function sectionLabel(section) {
        return (
            sectionAliases[section.id]?.trim() || sectionDefaultLabel(section)
        );
    }

    function itemDefaultLabel(item) {
        return t(item.labelKey);
    }

    function resetItem(route) {
        delete itemAliases[route];
    }

    function resetSection(sectionId) {
        delete sectionAliases[sectionId];
    }

    function resetAll() {
        Object.keys(sectionAliases).forEach((id) => delete sectionAliases[id]);
        Object.keys(itemAliases).forEach((route) => delete itemAliases[route]);
        sectionOrder.value = [];
        Object.keys(itemOrder).forEach((id) => delete itemOrder[id]);
    }

    /**
     * Updates the persisted section order when VueDraggable emits the new
     * order. The order is saved only when `saveAll()` runs.
     */
    function applySectionOrder(sections) {
        sectionOrder.value = sections.map((s) => s.id);
    }

    function applyItemOrder(sectionId, items) {
        const key = items[0]?.key ? "key" : "route";
        itemOrder[sectionId] = items.map((i) => i[key]);
    }

    async function saveAll() {
        if (saving.value) return;

        const cleanedSections = Object.fromEntries(
            Object.entries(sectionAliases).filter(
                ([, value]) => typeof value === "string" && value.trim() !== "",
            ),
        );
        const cleanedItems = Object.fromEntries(
            Object.entries(itemAliases).filter(
                ([, value]) => typeof value === "string" && value.trim() !== "",
            ),
        );
        const cleanedItemOrder = Object.fromEntries(
            Object.entries(itemOrder).filter(
                ([, value]) => Array.isArray(value) && value.length > 0,
            ),
        );

        saving.value = true;
        try {
            const results = await Promise.all([
                request(
                    updatePath,
                    {
                        key: "nav_section_aliases",
                        value: JSON.stringify(cleanedSections),
                    },
                    { noGuard: true },
                ),
                request(
                    updatePath,
                    {
                        key: "nav_item_aliases",
                        value: JSON.stringify(cleanedItems),
                    },
                    { noGuard: true },
                ),
                request(
                    updatePath,
                    {
                        key: "nav_section_order",
                        value: JSON.stringify(sectionOrder.value),
                    },
                    { noGuard: true },
                ),
                request(
                    updatePath,
                    {
                        key: "nav_item_order",
                        value: JSON.stringify(cleanedItemOrder),
                    },
                    { noGuard: true },
                ),
            ]);

            if (results.every((r) => r?.success)) {
                toast.success(t("backend.settings.saved"));
            }
        } finally {
            saving.value = false;
        }
    }

    return {
        sectionAliases,
        itemAliases,
        sectionOrder,
        itemOrder,
        orderedSections,
        orderedItems,
        saving,
        toggleSection,
        isSectionExpanded,
        sectionDefaultLabel,
        sectionLabel,
        itemDefaultLabel,
        resetItem,
        resetSection,
        resetAll,
        applySectionOrder,
        applyItemOrder,
        saveAll,
    };
}
