<script setup>
import { useI18n } from "vue-i18n";
import { X } from "lucide-vue-next";

const { t } = useI18n();

defineProps({
    /** Currently picked colour name (null = use the section's default). */
    modelValue: { type: String, default: null },
});

const emit = defineEmits(["update:modelValue"]);

// Same palette as `useSidemenuSectionTheme` - keep in sync.
const PALETTE = [
    { name: "slate",   swatch: "bg-slate-500" },
    { name: "stone",   swatch: "bg-stone-500" },
    { name: "zinc",    swatch: "bg-zinc-500" },
    { name: "red",     swatch: "bg-red-500" },
    { name: "orange",  swatch: "bg-orange-500" },
    { name: "amber",   swatch: "bg-amber-500" },
    { name: "yellow",  swatch: "bg-yellow-500" },
    { name: "lime",    swatch: "bg-lime-500" },
    { name: "green",   swatch: "bg-green-500" },
    { name: "emerald", swatch: "bg-emerald-500" },
    { name: "teal",    swatch: "bg-teal-500" },
    { name: "cyan",    swatch: "bg-cyan-500" },
    { name: "sky",     swatch: "bg-sky-500" },
    { name: "blue",    swatch: "bg-blue-500" },
    { name: "indigo",  swatch: "bg-indigo-500" },
    { name: "violet",  swatch: "bg-violet-500" },
    { name: "purple",  swatch: "bg-purple-500" },
    { name: "fuchsia", swatch: "bg-fuchsia-500" },
    { name: "pink",    swatch: "bg-pink-500" },
    { name: "rose",    swatch: "bg-rose-500" },
];

function pick(colorName) {
    emit("update:modelValue", colorName);
}

function clear() {
    emit("update:modelValue", null);
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <!-- The button is the hit area, the swatch inside it the drawing: on a
             phone the button grows by its padding and the swatch keeps its
             size, its corners and its ring. -->
        <button
            v-for="colour in PALETTE"
            :key="colour.name"
            type="button"
            class="group shrink-0 p-[0.1875rem] -m-[0.1875rem] sm:p-0 sm:m-0"
            :title="colour.name"
            v-on:click="pick(colour.name)"
        >
            <span
                class="block w-6 h-6 rounded ring-offset-2 ring-offset-surface transition-all group-hover:scale-110"
                :class="[colour.swatch, modelValue === colour.name ? 'ring-2 ring-white scale-110' : 'opacity-60 group-hover:opacity-100']"
            />
        </button>
        <button
            type="button"
            class="ml-1 p-1 rounded text-muted hover:text-primary hover:bg-surface-2 transition-colors shrink-0 inline-flex min-h-7.5 min-w-7.5 items-center justify-center sm:min-h-0 sm:min-w-0"
            :title="t('suite.profile.sidemenu.color_reset')"
            :disabled="!modelValue"
            :class="{ 'opacity-30 cursor-not-allowed': !modelValue }"
            v-on:click="clear"
        >
            <X class="w-3.5 h-3.5" :stroke-width="2.5" />
        </button>
    </div>
</template>
