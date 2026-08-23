<script setup>
/**
 * Which occurrences an edit to a series applies to.
 *
 * Asked rather than defaulted, because there is no safe default: guessing "this
 * one" loses a change meant for the series, and guessing "all" rewrites
 * occurrences the reader never looked at. Google and Apple both ask, and both ask
 * it as a small modal in front of the change rather than a setting somewhere.
 *
 * Three options and no more, in the order the risk grows: one occurrence, then
 * this one onward, then everything including the past.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    /** True while the question is being asked. */
    show: { type: Boolean, default: false },
    /** `edit` or `delete`, which changes only the wording. */
    intent: { type: String, default: "edit" },
});

const emit = defineEmits(["close", "confirm"]);

const { t } = useI18n();

/**
 * Starts on one occurrence, every time.
 *
 * The least destructive of the three, and the default nobody regrets: a reader who
 * confirms without reading has changed one meeting rather than a year of them.
 */
const scope = ref("this");

watch(
    () => props.show,
    (show) => {
        if (show) {
            scope.value = "this";
        }
    },
);

const OPTIONS = ["this", "following", "all"];
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        mobile-fullscreen
        :title="t(`backend.plannings.scope.title_${intent}`)"
        v-on:close="emit('close')"
    >
        <div class="flex flex-col gap-1">
            <label
                v-for="option in OPTIONS"
                :key="option"
                class="flex cursor-pointer items-start gap-2.5 rounded-lg px-2 py-2 transition-colors hover:bg-surface-2"
            >
                <input
                    v-model="scope"
                    type="radio"
                    :value="option"
                    class="mt-0.5 shrink-0 accent-current text-accent-600"
                >
                <span class="min-w-0">
                    <span class="block text-sm text-primary">{{ t(`backend.plannings.scope.${option}`) }}</span>
                    <span class="block text-xs text-muted">
                        {{ t(`backend.plannings.scope.${option}_hint_${intent}`) }}
                    </span>
                </span>
            </label>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    :variant="'delete' === intent ? 'danger' : 'primary'"
                    size="md"
                    v-on:click="emit('confirm', scope)"
                >
                    {{ t("shared.common.confirm") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
