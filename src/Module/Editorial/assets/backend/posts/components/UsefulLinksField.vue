<script setup>
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { GripVertical, Plus, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import BannerColorField from "./BannerColorField.vue";
import { isShareAddress } from "../../../frontend/shareLinks.js";

/**
 * The useful links at the foot of a publication: where else to find the
 * author, the product, the code.
 *
 * The share field's twin, minus what only sharing needs: no network to pick
 * and no default row, because every useful link is an address the author
 * types. Words and an address are both required - the server drops a link
 * missing either - and the colour tints it like a share link.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

/** Mirrors UsefulLinksNormalizer::MAX_LINKS. */
const MAX_USEFUL_LINKS = 12;

function commit(next) {
    emit(
        "update:modelValue",
        next.map((link) => ({ ...link })),
    );
}

function update(index, patch) {
    commit(props.modelValue.map((link, i) => (i === index ? { ...link, ...patch } : link)));
}

function remove(index) {
    commit(props.modelValue.filter((_, i) => i !== index));
}

function add() {
    commit([...props.modelValue, { label: "", url: "", color: null }]);
}

/** What the server will keep, said before the save rather than after it. */
function isKept(link) {
    return "" !== (link.label ?? "").trim() && isShareAddress(link.url);
}
</script>

<template>
    <div class="space-y-3">
        <p class="text-xs text-muted">{{ t("backend.posts.useful_links.hint") }}</p>

        <p v-if="!modelValue.length" class="text-sm text-secondary">{{ t("backend.posts.useful_links.empty") }}</p>

        <!-- The grip is the handle: the rest of the row is full of inputs,
             and a drag started in a text field is a selection gone wrong. -->
        <VueDraggable
            :model-value="modelValue"
            :animation="150"
            handle=".useful-link-handle"
            class="space-y-2"
            v-on:update:model-value="commit"
        >
            <div
                v-for="(link, index) in modelValue"
                :key="index"
                class="rounded-lg border border-line bg-surface-2 p-3 space-y-3"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="useful-link-handle cursor-grab text-muted active:cursor-grabbing"
                        :title="t('backend.posts.useful_links.move')"
                    >
                        <GripVertical class="w-4 h-4" :stroke-width="2" />
                    </span>
                    <AppInput
                        class="flex-1"
                        :model-value="link.label ?? ''"
                        :aria-label="t('backend.posts.useful_links.label')"
                        :placeholder="t('backend.posts.useful_links.label')"
                        v-on:update:model-value="update(index, { label: $event })"
                    />
                    <AppIconButton
                        color="rose"
                        :title="t('backend.posts.useful_links.remove')"
                        v-on:click="remove(index)"
                    >
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <AppInput
                        :model-value="link.url ?? ''"
                        :label="t('backend.posts.useful_links.url')"
                        :hint="t('backend.posts.useful_links.url_hint')"
                        :error="isKept(link) ? '' : t('backend.posts.useful_links.url_invalid')"
                        placeholder="https://"
                        v-on:update:model-value="update(index, { url: $event })"
                    />
                    <BannerColorField
                        :model-value="link.color"
                        :label="t('backend.posts.useful_links.color')"
                        v-on:update:model-value="update(index, { color: $event })"
                    />
                </div>
            </div>
        </VueDraggable>

        <AppButton
            variant="ghost"
            size="sm"
            :disabled="modelValue.length >= MAX_USEFUL_LINKS"
            v-on:click="add"
        >
            <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.useful_links.add") }}
        </AppButton>
    </div>
</template>
