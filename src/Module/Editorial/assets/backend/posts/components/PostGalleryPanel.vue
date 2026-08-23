<script setup>
/**
 * The gallery tab: pick pictures, order them, say what they are.
 *
 * Presentation only. Which pictures may go in, what happens when one is already
 * there, how the words follow an item - all of that is `usePostGallery`, and all
 * of it is enforced again by `GalleryNormalizer` on the way into the column.
 *
 * Two halves on screen, like the banner and the grid tabs: the settings and the
 * order are one per post, the alt text and captions are one set per language.
 * Switching the locale swaps the second and leaves the first standing.
 */
import { useI18n } from "vue-i18n";
import { ArrowDown, ArrowUp, Trash2 } from "lucide-vue-next";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import {
    GALLERY_COLUMNS,
    GALLERY_LAYOUTS,
    GALLERY_RATIOS,
    usePostGallery,
} from "../composables/usePostGallery.js";

const props = defineProps({
    /** `form.galleryLayout` - the arrangement, one per post. */
    layout: { type: Object, required: true },
    /** The open locale's half: alt text and captions, keyed by item id. */
    words: { type: Object, required: true },
    locale: { type: String, required: true },
});

const { t } = useI18n();

// The settings arrive as writable computeds: binding `v-model` straight to a
// prop's field is mutating something this component does not own, and
// `vue/no-mutating-props` is right to refuse it. The composable owns the write.
const { items, enabled, mode, columns, ratio, isFull, addItem, removeItem, moveItem, wordsFor } =
    usePostGallery(props.layout, props.words);

const layoutOptions = GALLERY_LAYOUTS.map((value) => ({
    value,
    label: t(`backend.posts.gallery.layouts.${value}`),
}));

const ratioOptions = GALLERY_RATIOS.map((value) => ({
    value,
    label: t(`backend.posts.grid.ratios.${value}`),
}));

const columnOptions = GALLERY_COLUMNS.map((value) => ({ value, label: String(value) }));

/**
 * The picker is the add button: it holds nothing of its own, and every pick is
 * appended and then cleared. `AppImagePickerField` chooses one image, which is
 * what "add the next one" needs - a second, multi-select picker would be a
 * component to keep for one caller.
 */
function onPick(media) {
    addItem(media);
}
</script>

<template>
    <div class="space-y-4">
        <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.gallery.title") }}</h3>
                    <p class="mt-1 text-xs text-muted">{{ t("backend.posts.gallery.hint") }}</p>
                </div>
                <AppToggle v-model="enabled" class="shrink-0" />
            </div>

            <!-- The settings stay reachable with the gallery off: an author sets
                 it up and then turns it on, and hiding the form behind the switch
                 would make that two visits. -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <AppSelect
                    v-model="mode"
                    :label="t('backend.posts.gallery.layout')"
                    :options="layoutOptions"
                />
                <AppSelect
                    v-model.number="columns"
                    :label="t('backend.posts.gallery.columns')"
                    :options="columnOptions"
                />
                <AppSelect
                    v-model="ratio"
                    :label="t('backend.posts.gallery.ratio')"
                    :options="ratioOptions"
                    :hint="'masonry' === mode ? t('backend.posts.gallery.ratio_ignored') : ''"
                    :disabled="'masonry' === mode"
                />
            </div>
        </div>

        <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-primary">
                    {{ t("backend.posts.gallery.pictures") }}
                    <span class="ml-1 text-xs font-normal text-muted tabular-nums">{{ items.length }}</span>
                </h3>
            </div>

            <AppNoData v-if="!items.length" :message="t('backend.posts.gallery.empty')" />

            <ul v-else class="space-y-2">
                <li
                    v-for="(item, index) in items"
                    :key="item.id"
                    class="flex items-start gap-3 rounded-lg border border-line/60 p-3"
                >
                    <img
                        v-if="item.url"
                        :src="item.url"
                        :alt="wordsFor(item.id).alt"
                        class="w-16 h-16 shrink-0 rounded-md object-cover"
                    >
                    <div v-else class="w-16 h-16 shrink-0 rounded-md bg-surface-2" />

                    <!-- The words, one set per language. The picture above them
                         is the same in every locale, which is the whole point of
                         the split. -->
                    <div class="flex-1 min-w-0 space-y-2">
                        <AppInput
                            v-model="wordsFor(item.id).alt"
                            :label="t('backend.posts.gallery.alt', { locale })"
                            :hint="t('backend.posts.gallery.alt_hint')"
                        />
                        <AppInput
                            v-model="wordsFor(item.id).caption"
                            :label="t('backend.posts.gallery.caption', { locale })"
                        />
                    </div>

                    <div class="flex flex-col gap-0.5 shrink-0">
                        <AppIconButton
                            :title="t('backend.posts.gallery.move_up')"
                            :disabled="0 === index"
                            v-on:click="moveItem(item.id, -1)"
                        >
                            <ArrowUp class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            :title="t('backend.posts.gallery.move_down')"
                            :disabled="index === items.length - 1"
                            v-on:click="moveItem(item.id, 1)"
                        >
                            <ArrowDown class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            color="rose"
                            :title="t('shared.common.delete')"
                            v-on:click="removeItem(item.id)"
                        >
                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </li>
            </ul>

            <div v-if="isFull" class="text-xs text-amber-400">
                {{ t("backend.posts.gallery.full") }}
            </div>
            <AppImagePickerField
                v-else
                :model-value="{ id: null, url: null }"
                :choose-label="t('backend.posts.gallery.add')"
                :size="72"
                v-on:update:model-value="onPick"
            />
        </div>
    </div>
</template>
