<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { GripVertical, Plus, RotateCcw, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import BannerColorField from "./BannerColorField.vue";
import { DEFAULT_SHARE_LINKS, MAX_SHARE_LINKS, SHARE_TYPES, isShareAddress } from "../../../frontend/shareLinks.js";

/**
 * The links of a publication's share block.
 *
 * Null is "never configured": the page shows the default row, and so does
 * this list, until the first change turns it into the page's own. Resetting
 * goes back to null rather than to a copy of the defaults, so a page left
 * alone follows the defaults if they ever change.
 */
const props = defineProps({
    modelValue: { type: Array, default: null },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const isDefault = computed(() => null === props.modelValue);
const entries = computed(() => props.modelValue ?? DEFAULT_SHARE_LINKS);

const typeOptions = computed(() =>
    SHARE_TYPES.map((type) => ({ value: type, label: typeLabel(type) })),
);

function typeLabel(type) {
    return t(`frontend.editorial.share.${"copy" === type ? "copy_link" : type}`);
}

function commit(next) {
    emit(
        "update:modelValue",
        next.map((link) => ({ ...link })),
    );
}

function update(index, patch) {
    commit(entries.value.map((link, i) => (i === index ? { ...link, ...patch } : link)));
}

function remove(index) {
    commit(entries.value.filter((_, i) => i !== index));
}

function add() {
    // The first network not already in the list, so a click adds something
    // useful rather than a second copy of the same button.
    const used = new Set(entries.value.map((link) => link.type));
    const type = SHARE_TYPES.find((candidate) => "custom" !== candidate && !used.has(candidate)) ?? "custom";

    commit([...entries.value, { type, label: null, url: null, color: null }]);
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-start justify-between gap-3">
            <p class="text-xs text-muted">
                {{ isDefault ? t("suite.posts.share_links.default_hint") : t("suite.posts.share_links.hint") }}
            </p>
            <AppButton v-if="!isDefault" variant="ghost" size="sm" v-on:click="emit('update:modelValue', null)">
                <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.share_links.reset") }}
            </AppButton>
        </div>

        <p v-if="!entries.length" class="text-sm text-secondary">{{ t("suite.posts.share_links.empty") }}</p>

        <!-- The grip is the handle: the rest of the row is full of inputs,
             and a drag started in a text field is a selection gone wrong. -->
        <VueDraggable
            :model-value="entries"
            :animation="150"
            handle=".share-link-handle"
            class="space-y-2"
            v-on:update:model-value="commit"
        >
            <div
                v-for="(link, index) in entries"
                :key="index"
                class="rounded-lg border border-line bg-surface-2 p-3 space-y-3"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="share-link-handle cursor-grab text-muted active:cursor-grabbing"
                        :title="t('suite.posts.share_links.move')"
                    >
                        <GripVertical class="w-4 h-4" :stroke-width="2" />
                    </span>
                    <AppSelect
                        class="flex-1"
                        :model-value="link.type"
                        :options="typeOptions"
                        :aria-label="t('suite.posts.share_links.type')"
                        v-on:update:model-value="update(index, { type: $event, url: 'custom' === $event ? link.url : null })"
                    />
                    <AppIconButton
                        color="rose"
                        :title="t('suite.posts.share_links.remove')"
                        v-on:click="remove(index)"
                    >
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <AppInput
                        :model-value="link.label ?? ''"
                        :label="t('suite.posts.share_links.label')"
                        :placeholder="typeLabel(link.type)"
                        v-on:update:model-value="update(index, { label: $event || null })"
                    />
                    <BannerColorField
                        :model-value="link.color"
                        :label="t('suite.posts.share_links.color')"
                        v-on:update:model-value="update(index, { color: $event })"
                    />
                </div>

                <AppInput
                    v-if="'custom' === link.type"
                    :model-value="link.url ?? ''"
                    :label="t('suite.posts.share_links.url')"
                    :hint="t('suite.posts.share_links.url_hint', { url: '{url}', title: '{title}' })"
                    :error="isShareAddress(link.url) ? '' : t('suite.posts.share_links.url_invalid')"
                    placeholder="https://"
                    v-on:update:model-value="update(index, { url: $event || null })"
                />
            </div>
        </VueDraggable>

        <AppButton
            variant="ghost"
            size="sm"
            :disabled="entries.length >= MAX_SHARE_LINKS"
            v-on:click="add"
        >
            <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.share_links.add") }}
        </AppButton>
    </div>
</template>
