<script setup>
import { computed, ref, toRef } from "vue";
import { useI18n } from "vue-i18n";
import { useNoteGraph } from "@notes/suite/markdown/composables/useNoteGraph.js";
import { DEFAULT_NODE_COLOR } from "@notes/suite/markdown/composables/noteGraphFamilies.js";
import { spaceLabel } from "@notes/suite/markdown/composables/noteSpaces.js";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppNoData from "@shared/components/feedback/AppNoData.vue";
import AppSelect from "@shared/components/form/select/AppSelect.vue";
import { Network, Loader2 } from "lucide-vue-next";

/**
 * Wiki-link graph for the user's notes. Pure presentation: provides a
 * canvas inside the modal, hands its ref + pointer handlers off to
 * `useNoteGraph` which owns the force simulation, the draw loop, and
 * the open/close lifecycle bound to `show`.
 *
 * Notes are coloured by family (folder, else space). With more than one
 * space in the graph, a select above the canvas shows one space at a time,
 * from the graph already loaded, and a legend names each space's colour.
 */

const props = defineProps({
    show: { type: Boolean, default: false },
    fetchGraph: { type: Function, required: true },
});

const emit = defineEmits(["close", "navigate"]);

const { t } = useI18n();

const canvasRef = ref(null);

const {
    loading,
    empty,
    spaces,
    spaceFilter,
    onMouseDown,
    onMouseMove,
    onMouseUp,
    onClick,
} = useNoteGraph({
    fetchGraph: props.fetchGraph,
    canvasRef,
    untitledLabel: t("notes.markdown.untitled"),
    onNavigate: (id) => emit("navigate", id),
    showRef: toRef(props, "show"),
});

const hasSeveralSpaces = computed(() => spaces.value.length > 1);

const spaceOptions = computed(() => [
    { value: "", label: t("notes.markdown.graph.all_spaces") },
    ...spaces.value.map((space) => ({
        value: String(space.id),
        label: spaceLabel(space, t),
    })),
]);

/** The legend follows the select: one space shown, one entry. */
const legend = computed(() =>
    spaces.value
        .filter(
            (space) =>
                "" === spaceFilter.value ||
                String(space.id) === spaceFilter.value,
        )
        .map((space) => ({
            id: space.id,
            label: spaceLabel(space, t),
            color: space.color || DEFAULT_NODE_COLOR,
        })),
);
</script>

<template>
    <AppModal
        :show="show"
        max-width="6xl"
        mobile-fullscreen
        :title="t('notes.markdown.graph.title')"
        :icon="Network"
        no-padding
        :scrollable="false"
        v-on:close="emit('close')"
    >
        <div
            class="flex w-full flex-col bg-surface overflow-hidden h-[calc(100dvh-4rem)] md:h-[80vh]"
        >
            <div
                v-if="hasSeveralSpaces"
                class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-line px-4 py-2"
                data-graph-spaces
            >
                <AppSelect
                    v-model="spaceFilter"
                    class="w-56 max-w-full"
                    :options="spaceOptions"
                />
                <ul class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                    <li
                        v-for="entry in legend"
                        :key="entry.id"
                        class="flex min-w-0 items-center gap-1.5"
                    >
                        <span
                            class="h-2.5 w-2.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: entry.color }"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ entry.label }}</span>
                    </li>
                </ul>
            </div>

            <div class="relative min-h-0 flex-1">
                <canvas
                    ref="canvasRef"
                    class="block w-full h-full text-muted"
                    v-on:mousedown="onMouseDown"
                    v-on:mousemove="onMouseMove"
                    v-on:mouseup="onMouseUp"
                    v-on:click="onClick"
                />

                <div
                    v-if="loading"
                    class="absolute inset-0 flex items-center justify-center text-muted text-sm gap-2 bg-surface-2/30 backdrop-blur-sm"
                >
                    <Loader2 class="w-4 h-4 animate-spin" :stroke-width="2" />
                    {{ t('notes.markdown.graph.loading') }}
                </div>

                <AppNoData
                    v-else-if="empty"
                    class="absolute inset-0 flex items-center justify-center bg-surface-2/30"
                    :message="t('notes.markdown.graph.empty.title')"
                    :hint="t('notes.markdown.graph.empty.description')"
                    :icon="Network"
                />
            </div>
        </div>
    </AppModal>
</template>
