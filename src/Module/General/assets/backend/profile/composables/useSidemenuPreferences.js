import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { SIDEMENU_PREFS_EVENT } from "@core/backend/sidemenu/composables/useSidemenuLiveColors.js";

function collectItemKeys(items, out) {
    for (const item of items ?? []) {
        if (item.key) out.push(item.key);
        if (item.children?.length) collectItemKeys(item.children, out);
    }
}

export function useSidemenuPreferences({
    navPreferences,
    sectionAliases,
    itemAliases,
    initialHiddenSections,
    initialHiddenItems,
    initialSectionColors,
    savePath,
    resetPath,
}) {
    const { t } = useI18n();

    const hiddenSections = ref(new Set(initialHiddenSections ?? []));
    const hiddenItems = ref(new Set(initialHiddenItems ?? []));
    const sectionColors = ref({ ...(initialSectionColors ?? {}) });
    const search = ref("");

    const { loading: saving, request: saveRequest } = useRequest();
    const { loading: resetting, request: resetRequest } = useRequest();

    function resolveItemLabel(item) {
        return itemAliases?.[item.key]?.trim() || t(item.labelKey);
    }

    const sections = computed(() =>
        (navPreferences ?? []).map((section) => ({
            id: section.id,
            label:
                sectionAliases?.[section.id]?.trim() ||
                t(`backend.nav.sections.${section.id}`),
            items: section.items ?? [],
        })),
    );

    const filteredSections = computed(() => {
        const query = search.value.trim().toLowerCase();
        if (!query) return sections.value;

        const results = [];
        for (const section of sections.value) {
            const sectionMatches = section.label.toLowerCase().includes(query);
            const matchingItems = section.items.filter((item) =>
                resolveItemLabel(item).toLowerCase().includes(query),
            );
            if (sectionMatches || matchingItems.length) {
                results.push({
                    ...section,
                    items: sectionMatches ? section.items : matchingItems,
                });
            }
        }
        return results;
    });

    function isSectionHidden(id) {
        return hiddenSections.value.has(id);
    }
    function isItemHidden(key) {
        return hiddenItems.value.has(key);
    }

    function toggleSection(id) {
        const next = new Set(hiddenSections.value);
        next.has(id) ? next.delete(id) : next.add(id);
        hiddenSections.value = next;
    }

    function toggleItem(key) {
        const next = new Set(hiddenItems.value);
        next.has(key) ? next.delete(key) : next.add(key);
        hiddenItems.value = next;
    }

    function hideAllInSection(section) {
        const keys = [];
        collectItemKeys(section.items, keys);
        const next = new Set(hiddenItems.value);
        keys.forEach((k) => next.add(k));
        hiddenItems.value = next;
    }

    function showAllInSection(section) {
        const keys = [];
        collectItemKeys(section.items, keys);
        const next = new Set(hiddenItems.value);
        keys.forEach((k) => next.delete(k));
        hiddenItems.value = next;
    }

    function setSectionColor(sectionId, colorName) {
        const next = { ...sectionColors.value };
        if (colorName) {
            next[sectionId] = colorName;
        } else {
            delete next[sectionId];
        }
        sectionColors.value = next;
    }

    function clearSectionColor(sectionId) {
        setSectionColor(sectionId, null);
    }

    function getSectionColor(sectionId) {
        return sectionColors.value[sectionId] ?? null;
    }

    /**
     * Broadcasts the new prefs so the sidemenu - which lives in a
     * separate Vue mount point fed by Twig props - can update its
     * colour overrides without a page reload. Hidden sections/items
     * are server-filtered on the next navigation, so we only push the
     * colour map live; the rest naturally syncs on next request.
     */
    function broadcastPrefs(navSectionColors) {
        window.dispatchEvent(
            new CustomEvent(SIDEMENU_PREFS_EVENT, {
                detail: { navSectionColors: { ...navSectionColors } },
            }),
        );
    }

    async function save() {
        const data = await saveRequest(savePath, {
            hiddenNavSections: [...hiddenSections.value],
            hiddenNavItems: [...hiddenItems.value],
            navSectionColors: sectionColors.value,
        });
        if (!data) return;
        if (data.success) {
            const normalisedColors =
                data.navSectionColors && !Array.isArray(data.navSectionColors)
                    ? data.navSectionColors
                    : {};
            hiddenSections.value = new Set(data.hiddenNavSections ?? []);
            hiddenItems.value = new Set(data.hiddenNavItems ?? []);
            sectionColors.value = { ...normalisedColors };
            broadcastPrefs(normalisedColors);
            toast.success(t("backend.profile.sidemenu.saved"));
        } else {
            toast.error(t("shared.common.error"));
        }
    }

    async function reset() {
        const data = await resetRequest(resetPath, {});
        if (!data) return;
        if (data.success) {
            hiddenSections.value = new Set();
            hiddenItems.value = new Set();
            sectionColors.value = {};
            broadcastPrefs({});
            toast.success(t("backend.profile.sidemenu.reset_done"));
        }
    }

    const hiddenCount = computed(
        () => hiddenSections.value.size + hiddenItems.value.size,
    );

    const customColorCount = computed(
        () => Object.keys(sectionColors.value).length,
    );

    return {
        sections,
        filteredSections,
        resolveItemLabel,
        search,
        hiddenSections,
        hiddenItems,
        hiddenCount,
        sectionColors,
        customColorCount,
        getSectionColor,
        setSectionColor,
        clearSectionColor,
        isSectionHidden,
        isItemHidden,
        toggleSection,
        toggleItem,
        hideAllInSection,
        showAllInSection,
        save,
        reset,
        saving,
        resetting,
    };
}
