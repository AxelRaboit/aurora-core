<script setup>
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown, ChevronRight } from "lucide-vue-next";
import { buildTermTree, flattenTreeWithDepth } from "@editorial/shared/termTree.js";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";

const { t } = useI18n();

const props = defineProps({
    taxonomies: { type: Array, required: true },
    selectedTermIds: { type: Array, required: true },
    activeLocale: { type: String, required: true },
    defaultLocale: { type: String, required: true },
    collapsible: { type: Boolean, default: false },
});

const emit = defineEmits(["toggle-term"]);

const STORAGE_KEY = "aurora-posts-taxonomy-collapsed";

function loadCollapsed() {
    try {
        return new Set(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "[]"));
    } catch {
        return new Set();
    }
}

const collapsed = ref(loadCollapsed());
const searches  = ref({});

function toggleCollapse(slug) {
    const next = new Set(collapsed.value);
    if (next.has(slug)) {
        next.delete(slug);
    } else {
        next.add(slug);
        searches.value[slug] = "";
    }
    collapsed.value = next;
    localStorage.setItem(STORAGE_KEY, JSON.stringify([...next]));
}

function isCollapsed(slug) {
    return props.collapsible && collapsed.value.has(slug);
}

function hasActiveTerms(taxonomy) {
    return (taxonomy.terms ?? []).some((term) => props.selectedTermIds.includes(term.id));
}

function taxonomyLabel(taxonomy) {
    return (
        taxonomy.translations?.[props.activeLocale]?.label ??
        taxonomy.translations?.[props.defaultLocale]?.label ??
        taxonomy.slug
    );
}

function termLabel(term) {
    return (
        term.translations?.[props.activeLocale]?.name ??
        term.translations?.[props.defaultLocale]?.name ??
        "(—)"
    );
}

function filteredTerms(taxonomy) {
    const query = (searches.value[taxonomy.slug] ?? "").toLowerCase().trim();
    const flat = taxonomy.hierarchical
        ? flattenTreeWithDepth(buildTermTree(taxonomy.terms))
        : taxonomy.terms;
    if (!query) return flat;
    return flat.filter((term) => termLabel(term).toLowerCase().includes(query));
}
</script>

<template>
    <div class="space-y-4">
        <div v-for="taxonomy in taxonomies" :key="taxonomy.id" class="space-y-1.5">
            <div
                class="flex items-center gap-2"
                :class="collapsible ? 'cursor-pointer select-none group' : ''"
                v-on:click="collapsible ? toggleCollapse(taxonomy.slug) : undefined"
            >
                <component
                    :is="isCollapsed(taxonomy.slug) ? ChevronRight : ChevronDown"
                    v-if="collapsible"
                    class="w-3 h-3 text-muted shrink-0 transition-transform"
                />
                <span class="text-xs font-medium text-muted uppercase tracking-wide shrink-0 group-hover:text-secondary transition-colors">
                    {{ taxonomyLabel(taxonomy) }}
                </span>
                <span v-if="hasActiveTerms(taxonomy)" class="w-1.5 h-1.5 rounded-full bg-accent-500 shrink-0" />
            </div>

            <template v-if="!isCollapsed(taxonomy.slug)">
                <AppSearchInput
                    v-model="searches[taxonomy.slug]"
                    :placeholder="t('backend.posts.search_terms')"
                />

                <div class="max-h-52 overflow-y-auto scrollbar-thin border border-line/60 rounded-md bg-surface-2 p-2 space-y-1">
                    <AppNoData v-if="!filteredTerms(taxonomy).length" :message="t('backend.posts.terms_picker_empty')" />
                    <AppCheckbox
                        v-for="term in filteredTerms(taxonomy)"
                        :key="term.id"
                        class="hover:bg-surface-3 rounded px-1.5 py-0.5"
                        :style="taxonomy.hierarchical ? { paddingLeft: `${0.375 + (term.depth ?? 0) * 1.25}rem` } : {}"
                        :model-value="selectedTermIds.includes(term.id)"
                        v-on:update:model-value="emit('toggle-term', term.id)"
                    >
                        <span class="text-primary truncate">{{ termLabel(term) }}</span>
                    </AppCheckbox>
                </div>
            </template>
        </div>
    </div>
</template>
