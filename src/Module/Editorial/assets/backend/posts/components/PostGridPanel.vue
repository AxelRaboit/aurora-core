<script setup>
/**
 * Content-grid panel of the post editor.
 *
 * A builder, like the banner's: add a zone, set how wide it is, reorder,
 * remove. Zones flow and wrap, so moving one is reordering it — there is no
 * empty cell to drag into.
 *
 * Two props because the grid is stored in two halves: the arrangement on the
 * post, what fills each zone on the open translation. The panel does not
 * arrange them differently for it — usePostGrid knows which half a field
 * belongs to.
 *
 * Presentation only: every field arrives as a writable computed, so nothing
 * here writes to a prop.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { ChevronDown, ChevronUp, FileText, Film, Image, Newspaper, Plus, Trash2 } from "lucide-vue-next";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useServerPreview } from "@/shared/composables/http/backend/useServerPreview.js";
import { usePostGrid } from "../composables/usePostGrid.js";

const props = defineProps({
    /** The arrangement, shared by every language. */
    layout: { type: Object, required: true },
    /** What fills each zone, for the language currently open. */
    content: { type: Object, required: true },
    /** Which language that is — shown on the fields that are per-language. */
    locale: { type: String, required: true },
    /** Publications this grid may link to, for the `post` zone type. */
    postOptions: { type: Array, default: () => [] },
    previewPath: { type: String, required: true },
});

const { t } = useI18n();

const {
    COLUMNS,
    zones,
    canAddZone,
    enabled,
    snap,
    snapOptions,
    typeOptions,
    addZone,
    removeZone,
    moveZone,
    zoneFields,
    widthLabel,
} = usePostGrid(
    computed(() => props.layout),
    computed(() => props.content),
);

// The locale travels too: a card links with `path('editorial_post', {locale})`
// and shows the linked publication in that language, so previewing the German
// tab from a French backend has to say German.
const { html: previewHtml, loading: previewLoading } = useServerPreview(
    () => ({ layout: props.layout, content: props.content, locale: props.locale }),
    [() => props.layout, () => props.content, () => props.locale],
    props.previewPath,
);

const ZONE_ICONS = { text: FileText, media: Image, post: Newspaper, video: Film };

const publicationOptions = computed(() =>
    props.postOptions.map((post) => ({ value: post.id, label: post.title ?? `#${post.id}` })),
);
</script>

<template>
    <div class="space-y-4">
        <AppToggle v-model="enabled" :label="t('backend.posts.grid.enabled')" />

        <!-- Without this the card is a lone toggle, which reads as collapsed
             rather than as off. It also says the thing that matters: turning
             this on is what replaces the plain column, not something that
             renders beside it. -->
        <p v-if="!enabled" class="text-sm text-muted">
            {{ t("backend.posts.grid.disabled_hint") }}
        </p>

        <template v-if="enabled">
            <div class="relative space-y-2">
                <p class="text-sm font-medium text-primary">{{ t("backend.posts.grid.preview") }}</p>
                <!-- Rendered by the server from the same Twig the public page
                     uses, so what shows here is what gets published. Widths are
                     set with a slider, and a slider without a picture of the
                     result is a number to guess at. -->
                <div class="rounded-lg border border-line overflow-hidden bg-surface-2/30 p-3">
                    <div v-html="previewHtml" />
                </div>
                <AppLoader :active="previewLoading" />
            </div>

            <AppSelect
                v-model="snap"
                :label="t('backend.posts.grid.snap')"
                :hint="t('backend.posts.grid.snap_hint')"
                :options="snapOptions"
            />

            <div v-for="(zone, index) in zones" :key="zone.id" class="bg-surface-2/40 border border-line rounded-lg p-4 space-y-4">
                <div class="flex items-center gap-2">
                    <component :is="ZONE_ICONS[zone.type]" class="w-4 h-4 text-secondary" :stroke-width="2" />
                    <p class="text-sm font-medium text-primary flex-1">
                        {{ t(`backend.posts.grid.zone_types.${zone.type}`) }}
                    </p>
                    <AppIconButton
                        color="default"
                        :title="t('backend.posts.grid.move_up')"
                        :disabled="index === 0"
                        v-on:click="moveZone(index, -1)"
                    >
                        <ChevronUp class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        color="default"
                        :title="t('backend.posts.grid.move_down')"
                        :disabled="index === zones.length - 1"
                        v-on:click="moveZone(index, 1)"
                    >
                        <ChevronDown class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        color="rose"
                        :title="t('backend.posts.grid.remove_zone')"
                        v-on:click="removeZone(index)"
                    >
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <!-- A slider stepping by the chosen snap, rather than a select:
                     at one column the select would have 48 entries, and the
                     point of the finer steps is to place precisely. -->
                <div class="space-y-1.5">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="text-xs font-medium text-secondary uppercase tracking-wide">
                            {{ t("backend.posts.grid.width") }}
                        </p>
                        <p class="text-xs text-muted tabular-nums">{{ widthLabel(index) }}</p>
                    </div>
                    <AppRange
                        v-model="zoneFields(index).width.value"
                        :min="snap"
                        :max="COLUMNS"
                        :step="snap"
                    />
                </div>

                <template v-if="zone.type === 'text'">
                    <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <AppBlockEditor
                            v-model="zoneFields(index).blocks.value"
                            :placeholder="t('backend.posts.content_placeholder')"
                        />
                    </div>
                </template>

                <template v-else-if="zone.type === 'media'">
                    <AppImagePickerField
                        v-model="zoneFields(index).media.value"
                        :label="t('backend.posts.grid.zone_image')"
                    />
                    <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <AppInput
                            v-model="zoneFields(index).alt.value"
                            :label="t('backend.posts.grid.zone_alt')"
                        />
                        <AppInput
                            v-model="zoneFields(index).caption.value"
                            :label="t('backend.posts.grid.zone_caption')"
                        />
                    </div>
                </template>

                <template v-else-if="zone.type === 'post'">
                    <!-- Shared, not translated: the linked publication carries
                         its own translations and the page picks the right one. -->
                    <AppSelect
                        v-model="zoneFields(index).postId.value"
                        :label="t('backend.posts.grid.zone_post')"
                        :hint="t('backend.posts.grid.zone_post_hint')"
                        :options="publicationOptions"
                    />
                </template>

                <template v-else>
                    <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <!-- The address is per language: a localised video has
                             a localised URL. -->
                        <AppInput
                            v-model="zoneFields(index).url.value"
                            :label="t('backend.posts.grid.zone_video')"
                            :hint="t('backend.posts.grid.zone_video_hint')"
                            placeholder="https://youtu.be/…"
                        />
                        <AppInput
                            v-model="zoneFields(index).caption.value"
                            :label="t('backend.posts.grid.zone_caption')"
                        />
                    </div>
                </template>
            </div>

            <div class="flex flex-wrap gap-2">
                <AppButton
                    v-for="option in typeOptions"
                    :key="option.value"
                    variant="secondary"
                    size="sm"
                    type="button"
                    :disabled="!canAddZone"
                    v-on:click="addZone(option.value)"
                >
                    <Plus class="w-4 h-4" :stroke-width="2" />
                    {{ option.label }}
                </AppButton>
            </div>
        </template>
    </div>
</template>
