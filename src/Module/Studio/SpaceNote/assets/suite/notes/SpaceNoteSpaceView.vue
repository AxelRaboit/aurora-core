<script setup>
/**
 * A client space's Notes tab: the door to its notes space.
 *
 * **Notes are written in the Notes module.** Each client space has its notes
 * space, open to its team; the tab shows its list, most recently touched
 * first, and each row opens the note in the notes editor. "Nouvelle note"
 * leads there too, on a new note filed in this space. The client sees none of
 * this.
 *
 * The notes space only exists from the first note on: before that, the tab
 * says so, and the first gesture opens it.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ExternalLink, FileInput, NotebookPen, Plus } from "lucide-vue-next";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import NoteCraftImportModal from "@notes/suite/markdown/components/NoteCraftImportModal.vue";

const props = defineProps({
    /** What `SpaceNotesViewBuilder::payload()` returns. */
    state: { type: Object, required: true },
});

const { t } = useI18n();
const { request } = useRequest();
const { formatDateShort } = useDateFormat();

const current = ref(props.state);
const busy = ref(false);
const craftOpen = ref(false);

const noteSpace = computed(() => current.value.noteSpace ?? null);
const notes = computed(() => current.value.notes ?? []);
const paths = computed(() => current.value.paths ?? {});

/** Not open yet, it can be opened; once open, one must be able to write in it. */
const canWrite = computed(() => null === noteSpace.value || true === noteSpace.value.canWrite);

/** Open, but closed to whoever is looking: nothing to list, and that is said. */
const closed = computed(() => null !== noteSpace.value && !noteSpace.value.readable);

function noteHref(id) {
    return String(paths.value.show ?? "").replace("__id__", String(id));
}

/** The notes space, opened if it was not: its id, or null. */
async function ensureNoteSpace() {
    if (noteSpace.value) return noteSpace.value.id;

    const payload = await request(paths.value.open);

    if (!payload?.noteSpace) return null;

    current.value = payload;

    return payload.noteSpace.id;
}

/** A new note in the notes space, opened right away in the editor. */
async function createNote() {
    if (busy.value) return;

    busy.value = true;

    try {
        const spaceId = await ensureNoteSpace();
        if (null === spaceId) return;

        const payload = await request(paths.value.create, {
            title: t("suite.studio.space_notes.untitled"),
            spaceId,
        });

        if (payload?.note?.id) window.location.assign(noteHref(payload.note.id));
    } finally {
        busy.value = false;
    }
}

async function openCraft() {
    if (busy.value) return;

    busy.value = true;

    try {
        if (null !== (await ensureNoteSpace())) craftOpen.value = true;
    } finally {
        busy.value = false;
    }
}

function onCraftImported(payload) {
    if (payload?.note?.id) window.location.assign(noteHref(payload.note.id));
}
</script>

<template>
    <div class="aurora-stack">
        <!-- The screen's how-to guide, next to what it explains; collapsed
             or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.space_notes.guide.title')" storage-key="space-notes">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.studio.space_notes.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <div class="flex flex-wrap items-center gap-3">
            <AppButton
                v-if="canWrite && !closed"
                class="w-full sm:w-auto"
                variant="ghost"
                size="sm"
                data-space-note-create
                :loading="busy"
                v-on:click="createNote"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("suite.studio.space_notes.add") }}
            </AppButton>

            <!-- Second, and secondary: writing a note is the screen's
                 gesture, importing one is the exception. Absent as long as
                 the installation has not opened a Craft connection. -->
            <AppButton
                v-if="canWrite && !closed && state.craftEnabled"
                class="w-full sm:w-auto"
                variant="secondary"
                size="sm"
                data-space-note-craft
                v-on:click="openCraft"
            >
                <FileInput class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("notes.craft.import.action") }}
            </AppButton>

            <a
                v-if="noteSpace && !closed"
                class="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary sm:ml-auto"
                :href="paths.library"
                data-space-note-library
            >
                <ExternalLink class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("suite.studio.space_notes.open_in_notes") }}
            </a>
        </div>

        <AppNoData
            v-if="closed"
            :message="t('suite.studio.space_notes.closed')"
            :hint="t('suite.studio.space_notes.closed_hint')"
        />

        <AppNoData
            v-else-if="!notes.length"
            :message="t('suite.studio.space_notes.empty')"
            :hint="t('suite.studio.space_notes.empty_hint')"
        />

        <ul v-else class="aurora-card divide-y divide-line/40" data-space-note-list>
            <li v-for="note in notes" :key="note.id">
                <a
                    class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-surface-2/60"
                    :href="noteHref(note.id)"
                >
                    <NotebookPen class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-primary">
                            {{ note.title || t("suite.studio.space_notes.untitled") }}
                        </span>
                        <span v-if="note.folder" class="block truncate text-xs text-muted">{{ note.folder }}</span>
                    </span>
                    <span v-if="note.authorName" class="hidden shrink-0 text-xs text-muted sm:inline">{{ note.authorName }}</span>
                    <span v-if="note.updatedAt" class="shrink-0 text-xs text-muted">{{ formatDateShort(note.updatedAt) }}</span>
                </a>
            </li>
        </ul>

        <NoteCraftImportModal
            v-if="state.craftEnabled && noteSpace"
            :show="craftOpen"
            :documents-path="state.craftPaths?.documents ?? ''"
            :import-path="state.craftPaths?.import ?? ''"
            :space-id="Number(noteSpace.id)"
            v-on:close="craftOpen = false"
            v-on:imported="onCraftImported"
        />
    </div>
</template>
