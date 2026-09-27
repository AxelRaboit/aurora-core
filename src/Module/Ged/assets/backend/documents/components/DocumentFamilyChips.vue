<script setup>
import { useI18n } from "vue-i18n";
import { labelSwatch } from "../utils/familyLabels.js";

/**
 * One chip per member of a family, on the card that stands for it.
 *
 * A chip is a colour dot when the label names a colour, the label itself
 * otherwise (`fr`, `téléphone`...). Clicking one shows that member on the
 * card without leaving the listing; the badge beside them opens the family.
 * A member used somewhere carries a small mark, so the one on line can be
 * told from the ones kept aside.
 */
defineProps({
    members: { type: Array, required: true },
});

const previewed = defineModel({ type: Number, default: null });

const { t } = useI18n();

function titleOf(member) {
    const name = member.original ? t("backend.ged.documents.family.chip_original") : member.label || member.title;

    return member.usageCount > 0 ? `${name} · ${t("backend.ged.documents.family.chip_used")}` : name;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1" v-on:click.stop>
        <button
            v-for="member in members"
            :key="member.id"
            type="button"
            class="relative inline-flex items-center justify-center rounded-full border transition-colors focus-visible:outline-2 focus-visible:outline-accent-500"
            :class="[
                labelSwatch(member.label) ? 'h-4 w-4' : 'h-5 px-1.5 text-2xs font-mono',
                (previewed ?? members[0].id) === member.id ? 'border-primary ring-1 ring-primary' : 'border-line text-secondary hover:text-primary',
            ]"
            :style="labelSwatch(member.label) ? { backgroundColor: labelSwatch(member.label) } : null"
            :title="titleOf(member)"
            :aria-label="titleOf(member)"
            :aria-pressed="(previewed ?? members[0].id) === member.id"
            v-on:click="previewed = member.id"
        >
            <template v-if="!labelSwatch(member.label)">{{ member.original ? t("backend.ged.documents.family.chip_original_short") : member.label || "?" }}</template>
            <span v-if="member.usageCount > 0" class="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-success" aria-hidden="true" />
        </button>
    </div>
</template>
