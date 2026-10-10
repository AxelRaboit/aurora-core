<script setup>
import AppHelp from "@/shared/components/overlay/AppHelp.vue";

defineProps({
    label: { type: String, default: '' },
    required: { type: Boolean, default: false },
    /**
     * Topic id from `helpTopics.js`. Given one, a small button sits next to
     * the label and opens what a hint underneath could not hold.
     *
     * It lives here rather than in each control because eleven of them share
     * this label: adding it once gives every field in the application the
     * option, and nobody has to remember which components support it.
     */
    help: { type: String, default: '' },
});
</script>

<template>
    <!-- The layout only changes when there is a button to place, so the eleven
         controls already using this label render exactly as before.

         Written as a sentence, not in capitals (visual redesign of the suite,
         10/10/2026): a label is read, field after field, and small capitals
         read as a row of section headings - they are kept for those. -->
    <label
        v-if="label"
        class="text-[0.8125rem] font-medium text-primary"
        :class="help ? 'flex items-center gap-1.5' : 'block'"
    >
        <span>{{ label }}<span v-if="required" class="text-red-500 ml-0.5">*</span></span>
        <AppHelp v-if="help" :topic="help" :label="label" />
    </label>
</template>
