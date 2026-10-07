<script setup>
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ExternalLink, Layers, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";

/**
 * The part of the edit form about a document's family, and whether it is
 * kept on purpose.
 *
 * A family is one original and its alternates, never deeper. So the form
 * shows one of two things: on an original that already has alternates, the
 * list of them, each one a click away; on anything else, which document it
 * is a variant of, if any. The server holds the same rule
 * (`DocumentFamilyRule`); the form only avoids offering what it would refuse.
 */
const props = defineProps({
    doc: { type: Object, default: null },
    alternatesPath: { type: String, default: "" },
    showPath: { type: String, default: "" },
    error: { type: String, default: "" },
    // The labels already in use across the library, offered as one-click
    // suggestions: free text stays allowed, but "jaune" and "Jaune" are
    // better not both invented.
    labelSuggestions: { type: Array, default: () => [] },
});

const kept = defineModel("kept", { type: Boolean, default: false });
const originalId = defineModel("originalId", { type: Number, default: null });
const originalTitle = defineModel("originalTitle", { type: String, default: null });
const label = defineModel("label", { type: String, default: "" });

const emit = defineEmits(["open"]);

const { t } = useI18n();
const { request } = useRequest();

const alternates = ref([]);
const loadingAlternates = ref(false);

// An original even when all its alternates are in the trash: the server
// counts them (`DocumentFamilyRule`), so offering "Variante de" here would
// only lead to a refusal on save.
const isOriginal = computed(() => (props.doc?.alternateCount ?? 0) > 0 || props.doc?.familyLocked === true);

async function loadAlternates() {
    alternates.value = [];
    if (!isOriginal.value || !props.alternatesPath) return;

    loadingAlternates.value = true;
    const data = await request(buildPath(props.alternatesPath, { id: props.doc.id }), null, {
        method: HttpMethod.Get,
        noGuard: true,
    });
    loadingAlternates.value = false;
    alternates.value = data?.alternates ?? [];
}

watch(() => props.doc?.id, loadAlternates, { immediate: true });

async function chooseOriginal() {
    // Originals only, and whatever their status: a draft visual can have
    // its yellow copy long before either is published.
    const picked = await openDocumentPicker({ query: { originalsOnly: "1", status: "" } });
    if (!picked || picked.id === props.doc?.id) return;

    originalId.value = picked.id;
    originalTitle.value = picked.title;
}

function removeOriginal() {
    originalId.value = null;
    originalTitle.value = null;
    label.value = "";
}

function thumbnailOf(gedDocument) {
    return gedDocument.renditions?.thumbnail ?? gedDocument.thumbnailUrl ?? null;
}
</script>

<template>
    <div class="space-y-3 border-t border-line/40 pt-4">
        <p class="text-xs text-muted uppercase tracking-wide">{{ t("suite.ged.documents.family.section") }}</p>

        <AppToggle
            v-model="kept"
            :label="t('suite.ged.documents.family.kept')"
            :hint="t('suite.ged.documents.family.kept_hint')"
        />

        <template v-if="isOriginal">
            <div class="space-y-2">
                <span class="text-xs text-muted uppercase tracking-wide">{{ t("suite.ged.documents.family.alternates") }}</span>
                <p v-if="!loadingAlternates && !alternates.length" class="text-sm text-muted">{{ t("suite.ged.documents.family.alternates_empty") }}</p>
                <ul class="space-y-1.5">
                    <li v-for="alternate in alternates" :key="alternate.id">
                        <button
                            type="button"
                            class="w-full flex items-center gap-2 rounded-lg border border-line/40 px-2 py-1.5 text-left hover:bg-surface-2/60 transition-colors"
                            v-on:click="emit('open', alternate)"
                        >
                            <img v-if="thumbnailOf(alternate)" :src="thumbnailOf(alternate)" alt="" class="w-10 h-10 rounded object-cover shrink-0">
                            <Layers v-else class="w-4 h-4 text-muted shrink-0" :stroke-width="2" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-primary truncate">{{ alternate.title }}</span>
                                <span v-if="alternate.alternateLabel" class="block text-xs text-violet-600 dark:text-violet-400">{{ alternate.alternateLabel }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
                <p class="text-xs text-muted">{{ t("suite.ged.documents.family.original_locked") }}</p>
            </div>
        </template>

        <template v-else>
            <div class="space-y-1.5">
                <span class="text-xs text-muted uppercase tracking-wide">{{ t("suite.ged.documents.family.original") }}</span>
                <div v-if="originalId" class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-primary truncate max-w-full">{{ originalTitle }}</span>
                    <a
                        v-if="showPath"
                        :href="buildPath(showPath, { id: originalId })"
                        class="text-xs text-accent-400 inline-flex items-center gap-1"
                    ><ExternalLink class="w-3 h-3" :stroke-width="2" /> {{ t("suite.ged.documents.family.open_original") }}</a>
                    <AppButton variant="ghost" size="sm" type="button" v-on:click="chooseOriginal">{{ t("suite.ged.documents.family.original_change") }}</AppButton>
                    <AppButton variant="ghost" size="sm" type="button" v-on:click="removeOriginal"><X class="w-3 h-3" :stroke-width="2" /> {{ t("suite.ged.documents.family.original_remove") }}</AppButton>
                </div>
                <div v-else class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-muted">{{ t("suite.ged.documents.family.original_none") }}</span>
                    <AppButton variant="ghost" size="sm" type="button" v-on:click="chooseOriginal"><Layers class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.ged.documents.family.original_choose") }}</AppButton>
                </div>
                <p v-if="error" class="text-xs text-rose-500">{{ error }}</p>
            </div>
            <AppInput
                v-if="originalId"
                v-model="label"
                :label="t('suite.ged.documents.family.label')"
                :placeholder="t('suite.ged.documents.family.label_placeholder')"
                maxlength="40"
            />
            <div v-if="originalId && labelSuggestions.length" class="flex flex-wrap items-center gap-1">
                <span class="text-xs text-muted">{{ t("suite.ged.documents.family.label_suggestions") }}</span>
                <button
                    v-for="suggestion in labelSuggestions"
                    :key="suggestion"
                    type="button"
                    class="text-xs px-1.5 py-0.5 rounded border transition-colors"
                    :class="label === suggestion ? 'border-violet-500 text-violet-600 dark:text-violet-400' : 'border-line text-secondary hover:text-primary'"
                    v-on:click="label = suggestion"
                >
                    {{ suggestion }}
                </button>
            </div>
        </template>
    </div>
</template>
