<script setup>
/**
 * A client space's Notes section: the notes editor, on this space's notes.
 *
 * **Written here, by the team** (10/10/2026). The section draws the Notes
 * module's own editor - folders, links between notes, history, search,
 * comments - confined to the client space's notes space, and never leaves
 * the client space: opening a note writes the space's address
 * (`?view=notes&note=12`). The Notes module does not show these notes, and
 * the client sees none of them.
 *
 * The notes space only exists from the first note on: before that, the
 * section says so, and its first gesture opens it, then reloads the section
 * on it.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Plus } from "lucide-vue-next";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import MarkdownNotesApp from "@notes/suite/markdown/MarkdownNotesApp.vue";

const props = defineProps({
    /** What `SpaceNotesViewBuilder::payload()` returns. */
    state: { type: Object, required: true },
});

const { t } = useI18n();
const { request } = useRequest();

const busy = ref(false);

const noteSpace = computed(() => props.state.noteSpace ?? null);
const app = computed(() => props.state.app ?? null);

/** Open, but closed to whoever is looking: the editor is not drawn, and that is said. */
const closed = computed(() => null !== noteSpace.value && !noteSpace.value.readable);

/**
 * The first gesture: open the notes space with the team, then show the
 * section on it. A reload rather than a patch of the state - the editor's
 * props are the page's, built on the server inside the space's scope.
 */
async function openNoteSpace() {
    if (busy.value) return;

    busy.value = true;

    try {
        const payload = await request(props.state.paths.open);

        if (payload?.noteSpace) window.location.assign(props.state.paths.library);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-[var(--aurora-page-margin)]">
        <!-- The screen's how-to guide, next to what it explains; collapsed
             or expanded, the choice applies to every guide. -->
        <AppGuide :title="t('suite.studio.space_notes.guide.title')" storage-key="space-notes" class="shrink-0">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.studio.space_notes.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <MarkdownNotesApp v-if="app" v-bind="app" fill />

        <AppNoData
            v-else-if="closed"
            :message="t('suite.studio.space_notes.closed')"
            :hint="t('suite.studio.space_notes.closed_hint')"
        />

        <AppNoData
            v-else
            :message="t('suite.studio.space_notes.empty')"
            :hint="t('suite.studio.space_notes.empty_hint')"
        >
            <template #action>
                <AppButton
                    variant="primary"
                    size="md"
                    data-space-note-open
                    :loading="busy"
                    v-on:click="openNoteSpace"
                >
                    <Plus class="h-4 w-4" :stroke-width="2" />
                    {{ t("suite.studio.space_notes.start") }}
                </AppButton>
            </template>
        </AppNoData>
    </div>
</template>
