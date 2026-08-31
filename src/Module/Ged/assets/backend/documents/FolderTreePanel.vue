<script setup>
/**
 * The folder tree, mounted by the side menu under the GED's links.
 *
 * The first thing to use `ModuleNavView::$panelComponent`, and the case it was
 * built for: a tree is hierarchical, as long as the reader made it, and its
 * rows are rows of data - none of which a list of `NavItem`s declared in PHP
 * can express.
 *
 * **It fetches its own data.** The menu mounts the panel with no props, which
 * is the right arrangement - the menu has no business knowing what a folder is
 * - but it means the tree cannot arrive with the page payload. One small GET
 * per GED page, against an endpoint that returns the same shape and the same
 * counts as the documents page's own `folders` key.
 *
 * **Rows are links, not clicks.** `/backend/ged/documents?folderId=42` is a
 * real address that the documents page already reads on arrival, so the panel
 * works from the tags page and the categories page - places that have no
 * document listing to filter. A click handler would only have worked on the
 * one page that already has this tree in its sidebar.
 *
 * What it deliberately does not do: create, rename, move or delete a folder,
 * and mark one as a favourite. Those belong to the pages that own folders, and
 * a 280 px column is the wrong place to confirm a deletion. The reader gets
 * there in one click - the "Dossiers" row is right above.
 */
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown, ChevronRight, Folder } from "lucide-vue-next";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import { useSidemenuSectionTheme } from "@/backend/sidemenu/composables/useSidemenuSectionTheme.js";
import { useDocumentSidebarTree } from "./composables/useDocumentSidebarTree.js";

const FOLDERS_ENDPOINT = "/backend/ged/documents/folders";
const DOCUMENTS_PATH = "/backend/ged/documents";

const { t } = useI18n();
const { itemClasses, iconClasses } = useSidemenuSectionTheme();

const folders = ref([]);
const loading = ref(true);
const failed = ref(false);

/**
 * The documents page draws this tree itself, and draws it better: it creates,
 * renames and deletes folders, it takes a document dropped onto one, and it
 * carries "Tous les documents" and "Racine", which are filters the panel has no
 * business owning. Two trees thirty centimetres apart answering the same
 * question is worse than one.
 *
 * So the panel steps aside there - and only there. Every other GED page keeps
 * it, which is the whole reason it exists: before this, the tags page had no
 * way to reach a folder at all.
 *
 * Matched exactly rather than by prefix: `/backend/ged/documents/42` is a
 * document, and that page has no tree of its own.
 */
const ownedByThePage =
    window.location.pathname.replace(/\/+$/, "") === DOCUMENTS_PATH;

/**
 * No current-folder highlight, and none is missing.
 *
 * The reader is only ever looking at *a* folder on the documents page, which is
 * the one page this panel does not draw on. A highlight here would be a state
 * that can never be true - the kind of code that looks like a feature and is
 * really a leftover.
 */
const { flatFolders, collapsedFolderIds, toggleCollapse } =
    useDocumentSidebarTree(folders, ref(null));

const isEmpty = computed(() => !loading.value && 0 === folders.value.length);

function folderHref(id) {
    return `${DOCUMENTS_PATH}?folderId=${id}`;
}

/**
 * A failure here is silent on purpose - no toast.
 *
 * The panel is furniture next to the navigation, not something the reader
 * asked for. A red toast on every GED page because one auxiliary GET failed
 * would be louder than the thing it reports, and the links above the panel -
 * which are the navigation - are unaffected.
 */
onMounted(async () => {
    if (ownedByThePage) {
        loading.value = false;

        return;
    }

    try {
        const response = await fetch(FOLDERS_ENDPOINT, {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) throw new Error(String(response.status));
        const payload = await response.json();
        folders.value = payload.folders ?? [];
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div
        v-if="!failed && !ownedByThePage"
        class="mt-2 border-t border-line pt-2"
    >
        <p
            class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted"
        >
            {{ t("backend.ged.documents.folder_tree") }}
        </p>

        <p v-if="loading" class="px-3 py-1 text-xs text-muted">
            {{ t("shared.common.loading") }}
        </p>

        <p v-else-if="isEmpty" class="px-3 py-1 text-xs text-muted">
            {{ t("backend.ged.documents.folder_tree_empty") }}
        </p>

        <div v-else class="flex flex-col gap-0.5">
            <div
                v-for="folder in flatFolders"
                :key="folder.id"
                class="flex items-center"
                :data-folder-depth="folder.depth"
                :style="{ paddingLeft: `${folder.depth * 0.75}rem` }"
            >
                <!-- The chevron is a button inside the row rather than part of
                     the link: unfolding a branch is not going somewhere, and a
                     reader who wants to see what is inside a folder without
                     leaving the page they are on must be able to say so. -->
                <button
                    v-if="folder.childCount > 0"
                    type="button"
                    class="shrink-0 rounded p-0.5 text-muted hover:text-primary"
                    :title="
                        collapsedFolderIds.has(folder.id)
                            ? t('backend.ged.documents.expand')
                            : t('backend.ged.documents.collapse')
                    "
                    v-on:click.stop="toggleCollapse(folder.id)"
                >
                    <ChevronRight
                        v-if="collapsedFolderIds.has(folder.id)"
                        class="h-3 w-3"
                        :stroke-width="2"
                    />
                    <ChevronDown v-else class="h-3 w-3" :stroke-width="2" />
                </button>
                <span v-else class="w-4 shrink-0" />

                <!-- The wrapper carries the width, not `AppNavLink`: its
                     root is an `AppTooltip`, which renders `display: contents`,
                     so a class handed to the component is dropped on the floor
                     and Vue says so only as a dev warning. Same constraint that
                     makes `AppSidemenuNav` space its rows with `gap`. -->
                <div class="min-w-0 flex-1">
                    <AppNavLink
                        :href="folderHref(folder.id)"
                        :link-classes-override="
                            itemClasses('ged', { isActive: false })
                        "
                    >
                        <Folder
                            class="h-4 w-4 shrink-0"
                            :class="iconClasses('ged', { isActive: false })"
                            :stroke-width="2"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            folder.name
                        }}</span>
                        <span
                            v-if="folder.documentCount > 0"
                            class="font-mono text-xs text-muted"
                        >
                            {{ folder.documentCount }}
                        </span>
                    </AppNavLink>
                </div>
            </div>
        </div>
    </div>
</template>
