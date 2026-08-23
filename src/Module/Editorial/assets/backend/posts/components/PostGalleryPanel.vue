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
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { ArrowLeft, ArrowRight, Pencil, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppDropZone from "@/shared/components/form/file/AppDropZone.vue";
import { uploadImageFile } from "@/shared/utils/http/uploadImageFile.js";
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
const {
    items,
    enabled,
    mode,
    columns,
    ratio,
    isFull,
    addItem,
    removeItem,
    moveItem,
    reorder,
    wordsFor,
    importFiles,
    importing,
    imported,
    importTotal,
} = usePostGallery(props.layout, props.words);

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

/**
 * The words open in a modal rather than sitting under each tile.
 *
 * Square tiles side by side is how a gallery is edited everywhere, and it is the
 * right shape here - the order is the thing being edited, and a grid shows the
 * order. But two text fields under a 150px tile is a form nobody can read, so the
 * alt text and the caption move behind the tile's pencil.
 */
const editing = ref(null);

function editWords(id) {
    editing.value = id;
}

const importMessage = ref("");

/**
 * Several files in one gesture, which is the whole point of a gallery.
 *
 * The picker beside this one still has its place: it reaches into the library for
 * a picture already filed, which is a different question from "here are thirty
 * photographs off my card".
 *
 * Whatever did not go in is reported. Files past the cap and duplicates both
 * land here, because the author does not need the difference - they need the
 * number, or they count twelve on their disk and eight on the page and have no
 * idea why.
 */
async function onFiles(files) {
    importMessage.value = "";

    const { added, skipped } = await importFiles(files, uploadImageFile);

    if (skipped > 0) {
        importMessage.value = t("backend.posts.gallery.import_skipped", { added, skipped });
    }
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

            <!--
                Square tiles, dragged to reorder. The order is the thing being
                edited here, and a grid is the only shape that shows an order at
                a glance - a vertical list of rows shows a list.

                The whole tile is the handle, which is what a gallery editor does
                everywhere: with tiles this size a separate grip would be most of
                the tile. `VueDraggable` takes `:model-value` and emits the new
                order, so the write still goes through the composable.
            -->
            <VueDraggable
                v-else
                :model-value="items"
                :animation="150"
                class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3"
                v-on:update:model-value="reorder"
            >
                <div
                    v-for="(item, index) in items"
                    :key="item.id"
                    class="group relative aspect-square cursor-grab overflow-hidden rounded-lg border border-line/60 bg-surface-2 active:cursor-grabbing"
                >
                    <img
                        v-if="item.url"
                        :src="item.url"
                        :alt="wordsFor(item.id).alt"
                        class="h-full w-full object-cover"
                    >

                    <!-- The position, always on: the number is what tells the
                         author whether the drag landed where they meant. -->
                    <span class="absolute left-1.5 top-1.5 rounded-md bg-black/60 px-1.5 py-0.5 text-xs font-medium tabular-nums text-white">
                        {{ index + 1 }}
                    </span>

                    <!-- A caption already written is worth seeing without
                         opening anything: it is the one field whose absence is
                         easy to miss on a long gallery. -->
                    <span
                        v-if="wordsFor(item.id).caption"
                        class="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/80 to-transparent px-2 pb-1.5 pt-4 text-xs text-white"
                    >
                        {{ wordsFor(item.id).caption }}
                    </span>

                    <!--
                        On hover, and on focus-within so the keyboard reaches it:
                        dragging is a mouse gesture, and the arrows are how the
                        order is changed without one.
                    -->
                    <div
                        class="absolute inset-x-0 top-0 flex justify-end gap-0.5 bg-gradient-to-b from-black/70 to-transparent p-1.5 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                    >
                        <button
                            type="button"
                            class="rounded-md p-1 text-white/80 transition-colors hover:bg-white/20 hover:text-white disabled:opacity-30"
                            :disabled="0 === index"
                            :title="t('backend.posts.gallery.move_up')"
                            v-on:click="moveItem(item.id, -1)"
                        >
                            <ArrowLeft class="h-4 w-4" :stroke-width="2" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1 text-white/80 transition-colors hover:bg-white/20 hover:text-white disabled:opacity-30"
                            :disabled="index === items.length - 1"
                            :title="t('backend.posts.gallery.move_down')"
                            v-on:click="moveItem(item.id, 1)"
                        >
                            <ArrowRight class="h-4 w-4" :stroke-width="2" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1 text-white/80 transition-colors hover:bg-white/20 hover:text-white"
                            :title="t('backend.posts.gallery.words')"
                            v-on:click="editWords(item.id)"
                        >
                            <Pencil class="h-4 w-4" :stroke-width="2" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1 text-white/80 transition-colors hover:bg-rose-500/30 hover:text-white"
                            :title="t('shared.common.delete')"
                            v-on:click="removeItem(item.id)"
                        >
                            <Trash2 class="h-4 w-4" :stroke-width="2" />
                        </button>
                    </div>
                </div>
            </VueDraggable>

            <div v-if="isFull" class="text-xs text-amber-400">
                {{ t("backend.posts.gallery.full") }}
            </div>
            <template v-else>
                <!-- Several at once, which is how a gallery is actually filled.
                     `AppDropZone` already takes `multiple`, so this is the house
                     control rather than a file input written here. -->
                <AppDropZone
                    accept="image/*"
                    multiple
                    :uploading="importing"
                    :label="t('backend.posts.gallery.import')"
                    :drop-label="t('backend.posts.gallery.import_drop')"
                    :uploading-label="t('backend.posts.gallery.import_progress', { done: imported, total: importTotal })"
                    :hint="t('backend.posts.gallery.import_hint')"
                    v-on:change="onFiles"
                />

                <p v-if="importMessage" class="text-xs text-amber-400">{{ importMessage }}</p>

                <!-- And one from the library, for a picture already filed. A
                     different question from importing, so a separate control. -->
                <AppImagePickerField
                    :model-value="{ id: null, url: null }"
                    :choose-label="t('backend.posts.gallery.add')"
                    :size="72"
                    v-on:update:model-value="onPick"
                />
            </template>
        </div>

        <!-- `close-on-overlay` false, as the modal convention asks for a form:
             ESC and the X still close it, but a stray click in the backdrop no
             longer throws away a caption half typed. -->
        <AppModal
            :show="null !== editing"
            max-width="md"
            :close-on-overlay="false"
            :title="t('backend.posts.gallery.words')"
            v-on:close="editing = null"
        >
            <div v-if="editing" class="space-y-3">
                <AppInput
                    v-model="wordsFor(editing).alt"
                    :label="t('backend.posts.gallery.alt', { locale })"
                    :hint="t('backend.posts.gallery.alt_hint')"
                />
                <AppInput
                    v-model="wordsFor(editing).caption"
                    :label="t('backend.posts.gallery.caption', { locale })"
                />
            </div>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="primary" size="md" v-on:click="editing = null">
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
