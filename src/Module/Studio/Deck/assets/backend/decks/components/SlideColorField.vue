<script setup>
/**
 * A colour that may be unset, which is what "inherit the theme" looks like.
 *
 * `<input type="color">` always holds a value: handed an empty one it shows
 * black, so an inherited colour looked like a chosen black and the reader had
 * no way to tell the deck was still following its theme. The swatch is
 * therefore dimmed and labelled with the theme's own value until somebody
 * chooses, and a clear button appears only once there is something to clear.
 *
 * Its own component rather than Editorial's `BannerColorField`: that one lives
 * in another module, and a module reaching into another module's assets is the
 * kind of link that breaks silently the day either is reorganised.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { X } from "lucide-vue-next";
import AppColorField from "@/shared/components/form/picker/AppColorField.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";

const props = defineProps({
    modelValue: { type: String, default: null },
    label: { type: String, default: "" },
    /** The theme's own value, shown while nothing overrides it. */
    inherited: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const value = computed({
    get: () => props.modelValue ?? props.inherited ?? "",
    set: (next) => emit("update:modelValue", next || null),
});
</script>

<template>
    <div class="flex items-end gap-2">
        <AppColorField
            v-model="value"
            :label="label"
            :show-hex="false"
            size="sm"
            class="flex-1"
            :class="modelValue ? '' : 'opacity-60'"
        />
        <span class="pb-1 font-mono text-xs text-muted">
            {{ modelValue || t("backend.studio.decks.colour_inherited") }}
        </span>
        <AppIconButton
            v-if="modelValue"
            size="sm"
            variant="ghost"
            :title="t('backend.studio.decks.colour_reset')"
            v-on:click="emit('update:modelValue', null)"
        >
            <X class="h-3.5 w-3.5" :stroke-width="2" />
        </AppIconButton>
    </div>
</template>
