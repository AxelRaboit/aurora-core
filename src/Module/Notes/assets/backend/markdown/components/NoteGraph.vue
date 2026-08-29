<script setup>
import { ref, toRef } from "vue";
import { useI18n } from "vue-i18n";
import { useNoteGraph } from "@notes/backend/markdown/composables/useNoteGraph.js";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppNoData from "@shared/components/feedback/AppNoData.vue";
import { Network, Loader2 } from "lucide-vue-next";

/**
 * Wiki-link graph for the user's notes. Pure presentation: provides a
 * canvas inside the modal, hands its ref + pointer handlers off to
 * `useNoteGraph` which owns the force simulation, the draw loop, and
 * the open/close lifecycle bound to `show`.
 */

const props = defineProps({
    show: { type: Boolean, default: false },
    fetchGraph: { type: Function, required: true },
});

const emit = defineEmits(["close", "navigate"]);

const { t } = useI18n();

const canvasRef = ref(null);

const { loading, empty, onMouseDown, onMouseMove, onMouseUp, onClick } = useNoteGraph({
    fetchGraph: props.fetchGraph,
    canvasRef,
    untitledLabel: t("notes.markdown.untitled"),
    onNavigate: (id) => emit("navigate", id),
    showRef: toRef(props, "show"),
});
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
            class="relative w-full bg-surface overflow-hidden h-[calc(100vh-4rem)] md:h-[80vh]"
        >
            <canvas
                ref="canvasRef"
                class="block w-full h-full"
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
                :title="t('notes.markdown.graph.empty.title')"
                :description="t('notes.markdown.graph.empty.description')"
                :icon="Network"
            />
        </div>
    </AppModal>
</template>
