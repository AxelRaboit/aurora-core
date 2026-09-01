<script setup>
/**
 * One folder in the side menu's tree.
 *
 * Extracted from the panel, where it was a hundred and forty-seven lines of
 * markup inside a `v-for`. That shape is what cost Notes its create, delete and
 * drag when its tree moved: a hand-written row only has what somebody
 * remembered to give it, and nothing says out loud what a row is supposed to
 * carry. Here the list is the props and the emits, and the panel cannot lose a
 * button by editing around it.
 *
 * Same arrangement as `NoteTreeItem`, deliberately - the two modules draw
 * different trees, but a reader who has understood one should recognise the
 * other.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import {
    ChevronDown,
    ChevronRight,
    Folder,
    GripVertical,
    Pencil,
    Star,
    Trash2,
} from "lucide-vue-next";
import AppNavLink from "@/shared/components/nav/AppNavLink.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { useSidemenuSectionTheme } from "@/backend/sidemenu/composables/useSidemenuSectionTheme.js";

const props = defineProps({
    folder: { type: Object, required: true },
    href: { type: String, required: true },
    current: { type: Boolean, default: false },
    /** `before` | `into` | `after`, or null when nothing hovers this row. */
    zone: { type: String, default: null },
    dragging: { type: Boolean, default: false },
    collapsed: { type: Boolean, default: false },
    favourite: { type: Boolean, default: false },
    /** Whether the reader may write - hides the handle and the two actions. */
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits([
    "select",
    "toggle-collapse",
    "toggle-favourite",
    "edit",
    "delete",
    "dragstart",
    "dragover",
    "dragleave",
    "dragend",
    "drop",
]);

const { t } = useI18n();
const { itemClasses, iconClasses } = useSidemenuSectionTheme();

const linkClasses = computed(() =>
    itemClasses("ged", { isActive: props.current }),
);
const folderIconClasses = computed(() =>
    iconClasses("ged", { isActive: props.current }),
);
</script>

<template>
    <div
        class="group relative flex min-h-8 items-center"
        :data-folder-depth="folder.depth"
        :class="[
            'into' === zone ? 'rounded-md ring-1 ring-lime-500' : '',
            dragging ? 'opacity-40' : '',
        ]"
        :style="{ paddingLeft: `${folder.depth * 0.75}rem` }"
        :draggable="canManage"
        v-on:dragstart="emit('dragstart', $event)"
        v-on:dragover="emit('dragover', $event)"
        v-on:dragleave="emit('dragleave', $event)"
        v-on:dragend="emit('dragend', $event)"
        v-on:drop="emit('drop', $event)"
    >
        <!-- The two ordering bands are the top and bottom 40 % of the row; the
             middle fifth reparents. `min-h-8` is what makes that middle band
             big enough to hit: the folders page had `py-3` rows, and a plain
             nav row is half that. -->
        <div
            v-if="'before' === zone"
            class="pointer-events-none absolute inset-x-0 top-0 h-0.5 rounded-full bg-lime-500"
        />
        <div
            v-if="'after' === zone"
            class="pointer-events-none absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-lime-500"
        />

        <!-- Says the row can be dragged, which nothing else does: a row that is
             also a link reads as clickable, not as movable. -->
        <GripVertical
            v-if="canManage"
            class="h-3 w-3 shrink-0 text-muted/40 transition-colors group-hover:text-muted"
            :stroke-width="2"
        />

        <!-- Unfolding is not going somewhere, so it is a button beside the link
             and not part of it: looking inside a folder must not cost the
             reader the page they are on. -->
        <button
            v-if="folder.childCount > 0"
            type="button"
            class="shrink-0 rounded p-0.5 text-muted hover:text-primary"
            :title="
                collapsed
                    ? t('backend.ged.documents.expand')
                    : t('backend.ged.documents.collapse')
            "
            v-on:click.stop="emit('toggle-collapse')"
        >
            <ChevronRight v-if="collapsed" class="h-3 w-3" :stroke-width="2" />
            <ChevronDown v-else class="h-3 w-3" :stroke-width="2" />
        </button>
        <span v-else class="w-4 shrink-0" />

        <!-- The wrapper carries the width, not `AppNavLink`: its root is an
             `AppTooltip` rendering `display: contents`, so a class handed to
             the component is dropped on the floor. -->
        <div class="min-w-0 flex-1" v-on:click="emit('select', $event)">
            <AppNavLink
                :href="href"
                :active="current"
                :link-classes-override="linkClasses"
            >
                <Folder
                    class="h-4 w-4 shrink-0"
                    :class="folderIconClasses"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ folder.name }}</span>
                <span
                    v-if="folder.documentCount > 0"
                    class="font-mono text-xs text-muted"
                >
                    {{ folder.documentCount }}
                </span>
            </AppNavLink>
        </div>

        <div
            class="flex gap-0.5 opacity-0 transition-opacity group-hover:opacity-100"
        >
            <AppIconButton
                size="sm"
                variant="ghost"
                :class="
                    favourite
                        ? 'text-amber-400'
                        : 'text-muted hover:text-amber-400'
                "
                :title="
                    favourite
                        ? t('backend.ged.documents.unfavourite')
                        : t('backend.ged.documents.favourite')
                "
                v-on:click.stop="emit('toggle-favourite')"
            >
                <Star
                    class="h-3 w-3"
                    :stroke-width="2"
                    :fill="favourite ? 'currentColor' : 'none'"
                />
            </AppIconButton>
            <AppIconButton
                v-if="canManage"
                size="sm"
                variant="ghost"
                :title="t('backend.ged.documents.edit_folder')"
                v-on:click.stop="emit('edit')"
            >
                <Pencil class="h-3 w-3" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                v-if="canManage"
                size="sm"
                variant="ghost"
                :title="t('shared.common.delete')"
                v-on:click.stop="emit('delete')"
            >
                <Trash2 class="h-3 w-3" :stroke-width="2" />
            </AppIconButton>
        </div>
    </div>
</template>
