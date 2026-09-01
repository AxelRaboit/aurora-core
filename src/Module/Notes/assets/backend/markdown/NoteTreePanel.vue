<script setup>
/**
 * The notes, in the side menu.
 *
 * This was a 280 px aside inside the notes page - the widest of the six the
 * module system set out to move, and the one `ModuleNavView::$panelComponent`
 * was invented for: nine hundred notes cannot be nine hundred menu entries, and
 * a tree with a search field and a tag filter is not a list of links.
 *
 * **Rows are real addresses.** A note is a page now
 * (`/backend/notes/markdown/42`), so it can be sent to somebody, and
 * middle-click behaves. On a plain click the panel asks the page first through
 * `modulePanelBridge`: the editor is mounted, so it takes the click and swaps
 * the note in place, exactly as the aside's own handler used to. Nobody
 * listening means the reader is elsewhere in the module, and the link
 * navigates.
 *
 * What it deliberately leaves behind: the graph button and the tag manager,
 * which open dialogs belonging to the page, and the mobile drawer - the menu
 * has its own, and two drawers is two gestures to learn.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown, ChevronRight, FileText, Plus, X } from "lucide-vue-next";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { askPage } from "@/shared/nav/modulePanelBridge.js";
import { useModulePanelData } from "@/shared/nav/useModulePanelData.js";
import { useSidemenuSectionTheme } from "@/backend/sidemenu/composables/useSidemenuSectionTheme.js";
import { useNoteTree } from "./composables/useNoteTree.js";
import { useNoteTagFilter } from "./composables/useNoteTagFilter.js";

const NOTES_PATH = "/backend/notes/markdown";
const LIST_ENDPOINT = "/backend/notes/markdown/list";

const { t } = useI18n();
const { itemClasses, iconClasses } = useSidemenuSectionTheme();

const {
    data: notes,
    loading,
    failed,
} = useModulePanelData(LIST_ENDPOINT, { key: "notes" });

const treeQuery = ref("");
const { availableTags, selectedTags, toggleTag, clearTags } =
    useNoteTagFilter(notes);
const { tree } = useNoteTree(notes, treeQuery, selectedTags);

/**
 * Which note the page is showing, read from the address once and then kept in
 * step by our own asks - the page swaps notes without navigating, so nothing
 * else would tell us.
 */
const currentId = ref(
    Number(window.location.pathname.match(/\/markdown\/(\d+)$/)?.[1]) || null,
);

const collapsed = ref(new Set());
const isEmpty = computed(() => 0 === notes.value.length);

function toggleCollapse(id) {
    const next = new Set(collapsed.value);
    next.has(id) ? next.delete(id) : next.add(id);
    collapsed.value = next;
}

/** The tree flattened for drawing, hiding whatever the reader folded away. */
const rows = computed(() => {
    const out = [];
    const walk = (nodes, depth) => {
        for (const node of nodes) {
            out.push({ node, depth });
            if (node.children?.length && !collapsed.value.has(node.id)) {
                walk(node.children, depth + 1);
            }
        }
    };
    walk(tree.value, 0);

    return out;
});

const hrefFor = (id) => `${NOTES_PATH}/${id}`;

function onRowClick(event, id) {
    if (askPage("notes:note", { id })) {
        currentId.value = id;
        event.preventDefault();
    }
}

function onCreate() {
    // Creating belongs to the page: it makes the note, names it and puts the
    // cursor in it. If nobody is listening we go to the notes and let the page
    // take it from there.
    if (!askPage("notes:create", {})) window.location.href = NOTES_PATH;
}
</script>

<template>
    <AppModulePanel
        :title="t('notes.markdown.title')"
        :loading="loading"
        :failed="failed"
    >
        <template #action>
            <AppIconButton
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.create_root')"
                v-on:click="onCreate"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
        </template>

        <div class="px-3 pb-1">
            <AppSearchInput
                v-model="treeQuery"
                :placeholder="t('notes.markdown.search_placeholder')"
            />
        </div>

        <div v-if="availableTags.length" class="flex flex-wrap gap-1 px-3 pb-1">
            <button
                v-for="tag in availableTags"
                :key="tag"
                type="button"
                class="rounded-full border px-2 py-0.5 text-xs transition-colors"
                :class="
                    selectedTags.includes(tag)
                        ? 'border-violet-500 text-violet-300'
                        : 'border-line text-muted hover:text-primary'
                "
                v-on:click="toggleTag(tag)"
            >
                {{ tag }}
            </button>
            <AppIconButton
                v-if="selectedTags.length"
                size="sm"
                variant="ghost"
                :title="t('notes.markdown.tags.clear')"
                v-on:click="clearTags"
            >
                <X class="h-3 w-3" :stroke-width="2" />
            </AppIconButton>
        </div>

        <p v-if="isEmpty" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.tree_empty") }}
        </p>

        <div
            v-for="{ node, depth } in rows"
            :key="node.id"
            class="flex items-center"
            :data-note-depth="depth"
            :style="{ paddingLeft: `${depth * 0.75}rem` }"
        >
            <button
                v-if="node.children?.length"
                type="button"
                class="shrink-0 rounded p-0.5 text-muted hover:text-primary"
                :title="t('notes.markdown.title')"
                v-on:click.stop="toggleCollapse(node.id)"
            >
                <ChevronRight
                    v-if="collapsed.has(node.id)"
                    class="h-3 w-3"
                    :stroke-width="2"
                />
                <ChevronDown v-else class="h-3 w-3" :stroke-width="2" />
            </button>
            <span v-else class="w-4 shrink-0" />

            <div class="min-w-0 flex-1" v-on:click="onRowClick($event, node.id)">
                <AppNavLink
                    :href="hrefFor(node.id)"
                    :active="currentId === node.id"
                    :link-classes-override="
                        itemClasses('notes', { isActive: currentId === node.id })
                    "
                >
                    <FileText
                        class="h-4 w-4 shrink-0"
                        :class="
                            iconClasses('notes', {
                                isActive: currentId === node.id,
                            })
                        "
                        :stroke-width="2"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ node.title }}</span>
                </AppNavLink>
            </div>
        </div>
    </AppModulePanel>
</template>
