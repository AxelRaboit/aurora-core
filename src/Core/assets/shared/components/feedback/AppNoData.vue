<script setup>
import { Inbox } from "lucide-vue-next";

defineProps({
    message: { type: String, default: "Aucune donnée à afficher." },
    /** Secondary line under the message — usually what to do about the emptiness. */
    hint: { type: String, default: "" },
});
</script>

<template>
    <div class="w-full flex flex-col items-center justify-center gap-3 py-10 text-muted text-center">
        <Inbox class="w-8 h-8 opacity-40" :stroke-width="1.5" />
        <!-- Message and hint share a tighter group of their own: they are one
             statement, and the outer gap-3 would read as two. -->
        <div class="flex flex-col gap-1">
            <p class="text-sm">{{ message }}</p>
            <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        </div>
        <!-- The way out of the emptiness, when there is one. A page whose whole
             content is "nothing here yet" is the right place for the button that
             creates the first thing, rather than making the reader hunt for it
             in a sidebar that holds nothing else. -->
        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
