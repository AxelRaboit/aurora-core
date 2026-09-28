<script setup>
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Layers, Palette, Plus } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { labelSwatch } from "../utils/familyLabels.js";
import DocumentFamilyUsage from "./DocumentFamilyUsage.vue";

/**
 * A document's family, whole, wherever the document is shown: its original
 * and every alternate side by side, the one on screen marked, each a click
 * away. And the way to add one more, from here rather than by importing a
 * file and then declaring it in the edit form.
 *
 * Drawn from any member: an alternate asks for its original's family.
 */
const props = defineProps({
    doc: { type: Object, default: null },
    alternatesPath: { type: String, default: "" },
    canAdd: { type: Boolean, default: false },
    // A still image can also be declined in another colour from here.
    canRecolor: { type: Boolean, default: false },
});

const emit = defineEmits(["open", "add", "recolor"]);

const { t } = useI18n();
const { request } = useRequest();

const original = ref(null);
const alternates = ref([]);
const loading = ref(false);

const familyId = computed(() => props.doc?.originalId ?? props.doc?.id ?? null);
const members = computed(() => (original.value ? [original.value, ...alternates.value] : []));

async function load() {
    original.value = null;
    alternates.value = [];
    if (!familyId.value || !props.alternatesPath) return;

    loading.value = true;
    const data = await request(buildPath(props.alternatesPath, { id: familyId.value }), null, {
        method: HttpMethod.Get,
        noGuard: true,
    });
    loading.value = false;
    original.value = data?.original ?? null;
    alternates.value = data?.alternates ?? [];
}

watch(() => [props.doc?.id, props.doc?.originalId], load, { immediate: true });

function thumbnailOf(member) {
    return member.renditions?.thumbnail ?? member.thumbnailUrl ?? null;
}

function labelOf(member) {
    return member.id === original.value?.id ? t("backend.ged.documents.family.chip_original") : member.alternateLabel || member.title;
}
</script>

<template>
    <div v-if="alternates.length || canAdd || canRecolor" class="space-y-2">
        <p class="text-xs text-muted uppercase tracking-wide flex items-center gap-1.5">
            <Layers class="w-3.5 h-3.5" :stroke-width="2" />
            {{ alternates.length ? t("backend.ged.documents.family.strip_title", { count: alternates.length }) : t("backend.ged.documents.family.strip_empty") }}
        </p>
        <DocumentFamilyUsage
            v-if="alternates.length"
            :members="members"
            :original-id="original?.id ?? null"
        />
        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
            <button
                v-for="member in alternates.length ? members : []"
                :key="member.id"
                type="button"
                class="group space-y-1 text-left"
                :aria-current="member.id === doc?.id ? 'true' : undefined"
                v-on:click="member.id !== doc?.id && emit('open', member)"
            >
                <span
                    class="block aspect-square overflow-hidden rounded-lg bg-surface-2 border"
                    :class="member.id === doc?.id ? 'border-primary ring-2 ring-primary' : 'border-line group-hover:border-accent-400'"
                >
                    <img v-if="thumbnailOf(member)" :src="thumbnailOf(member)" alt="" class="h-full w-full object-cover">
                    <Layers v-else class="m-auto mt-6 w-5 h-5 text-muted" :stroke-width="2" />
                </span>
                <span class="flex items-center gap-1 text-xs text-secondary truncate">
                    <span
                        v-if="labelSwatch(member.alternateLabel)"
                        class="h-2 w-2 shrink-0 rounded-full"
                        :style="{ backgroundColor: labelSwatch(member.alternateLabel) }"
                        aria-hidden="true"
                    />
                    {{ labelOf(member) }}
                </span>
            </button>
            <button
                v-if="canAdd && !doc?.originalId"
                type="button"
                class="flex aspect-square flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-line text-xs text-muted hover:text-primary hover:border-accent-400"
                v-on:click="emit('add', original ?? doc)"
            >
                <Plus class="w-4 h-4" :stroke-width="2" />
                {{ t("backend.ged.documents.family.add_variant") }}
            </button>
            <button
                v-if="canRecolor"
                type="button"
                class="flex aspect-square flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-line text-xs text-muted hover:text-primary hover:border-accent-400"
                v-on:click="emit('recolor', original ?? doc)"
            >
                <Palette class="w-4 h-4" :stroke-width="2" />
                {{ t("backend.ged.documents.recolor.open") }}
            </button>
        </div>
        <p v-if="loading" class="text-xs text-muted">{{ t("shared.common.loading") }}</p>
    </div>
</template>
