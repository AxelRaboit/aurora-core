<script setup>
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { computed } from "vue";
import { GripVertical, Pencil, Plus, RotateCcw, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import BannerColorField from "./BannerColorField.vue";
import { isShareAddress } from "../../../frontend/shareLinks.js";

/**
 * The useful links at the foot of a publication, or of the whole site: where
 * else to find the author, the product, the code.
 *
 * On a publication, null means the page follows the site's list - set once in
 * Configuration - and the field shows that list read-only with a way to make
 * the page's own; going back is a reset to null rather than a copy, so a page
 * left alone follows the site if the list changes. Without `siteLinks` (the
 * Configuration tab itself) the list is simply edited.
 *
 * Words and an address are both required - the server drops a link missing
 * either - and the colour tints it like a share link.
 */
const props = defineProps({
    modelValue: { type: Array, default: null },
    siteLinks: { type: Array, default: null },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

/** Mirrors UsefulLinksNormalizer::MAX_LINKS. */
const MAX_USEFUL_LINKS = 12;

const followsSite = computed(() => null !== props.siteLinks && null === props.modelValue);
const entries = computed(() => props.modelValue ?? []);

function customise() {
    commit(props.siteLinks ?? []);
}

function commit(next) {
    emit(
        "update:modelValue",
        next.map((link) => ({ ...link })),
    );
}

function update(index, patch) {
    commit(entries.value.map((link, entryIndex) => (entryIndex === index ? { ...link, ...patch } : link)));
}

function remove(index) {
    commit(entries.value.filter((_, entryIndex) => entryIndex !== index));
}

function add() {
    commit([...entries.value, { label: "", url: "", color: null }]);
}

/** What the server will keep, said before the save rather than after it. */
function isKept(link) {
    return "" !== (link.label ?? "").trim() && isShareAddress(link.url);
}
</script>

<template>
    <div v-if="followsSite" class="space-y-3">
        <p class="text-xs text-muted">{{ t("suite.posts.useful_links.site_hint") }}</p>
        <div v-if="siteLinks.length" class="flex flex-wrap gap-2">
            <span
                v-for="(link, index) in siteLinks"
                :key="index"
                class="inline-flex items-center rounded-md border border-line px-2.5 py-1 text-sm text-secondary"
                :style="link.color ? { color: link.color, borderColor: link.color } : null"
            >{{ link.label }}</span>
        </div>
        <p v-else class="text-sm text-secondary">{{ t("suite.posts.useful_links.site_empty") }}</p>
        <AppButton variant="ghost" size="sm" v-on:click="customise">
            <Pencil class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.useful_links.customise") }}
        </AppButton>
    </div>

    <div v-else class="space-y-3">
        <div class="flex items-start justify-between gap-3">
            <p class="text-xs text-muted">{{ t(null === siteLinks ? "suite.posts.useful_links.site_list_hint" : "suite.posts.useful_links.hint") }}</p>
            <AppButton v-if="null !== siteLinks" variant="ghost" size="sm" v-on:click="emit('update:modelValue', null)">
                <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.useful_links.reset") }}
            </AppButton>
        </div>

        <p v-if="!entries.length" class="text-sm text-secondary">{{ t("suite.posts.useful_links.empty") }}</p>

        <!-- The grip is the handle: the rest of the row is full of inputs,
             and a drag started in a text field is a selection gone wrong. -->
        <VueDraggable
            :model-value="entries"
            :animation="150"
            handle=".useful-link-handle"
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
                        class="useful-link-handle cursor-grab text-muted active:cursor-grabbing"
                        :title="t('suite.posts.useful_links.move')"
                    >
                        <GripVertical class="w-4 h-4" :stroke-width="2" />
                    </span>
                    <AppInput
                        class="flex-1"
                        :model-value="link.label ?? ''"
                        :aria-label="t('suite.posts.useful_links.label')"
                        :placeholder="t('suite.posts.useful_links.label')"
                        v-on:update:model-value="update(index, { label: $event })"
                    />
                    <AppIconButton
                        color="rose"
                        :title="t('suite.posts.useful_links.remove')"
                        v-on:click="remove(index)"
                    >
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <AppInput
                        :model-value="link.url ?? ''"
                        :label="t('suite.posts.useful_links.url')"
                        :hint="t('suite.posts.useful_links.url_hint')"
                        :error="isKept(link) ? '' : t('suite.posts.useful_links.url_invalid')"
                        placeholder="https://"
                        v-on:update:model-value="update(index, { url: $event })"
                    />
                    <BannerColorField
                        :model-value="link.color"
                        :label="t('suite.posts.useful_links.color')"
                        v-on:update:model-value="update(index, { color: $event })"
                    />
                </div>
            </div>
        </VueDraggable>

        <AppButton
            variant="ghost"
            size="sm"
            :disabled="entries.length >= MAX_USEFUL_LINKS"
            v-on:click="add"
        >
            <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.posts.useful_links.add") }}
        </AppButton>
    </div>
</template>
