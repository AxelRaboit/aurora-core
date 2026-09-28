<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { familyUsage, labelSwatch } from "../utils/familyLabels.js";

/**
 * Where a family is used, in one line: which member, and what kind of source
 * draws it. "Original : 2 publications · rouge : 1 présentation".
 *
 * Read at the scale of the family because that is the question somebody
 * tidying the library asks: of the green, yellow and red copies, which one is
 * on the site, and can the others go. Per member, the same answer was three
 * "Inutilisé" badges and one missing, to be compared by eye.
 */
const props = defineProps({
    // Members as `familyMembers()` builds them, or as the alternates route
    // returns them: each with `usageCount` and `usageByType`.
    members: { type: Array, required: true },
    originalId: { type: Number, default: null },
});

const { t, te } = useI18n();

const used = computed(() => familyUsage(props.members.map((member) => ({
    ...member,
    original: member.original ?? member.id === props.originalId,
    label: member.label ?? member.alternateLabel ?? null,
}))));

function nameOf(member) {
    return member.original ? t("backend.ged.documents.family.chip_original") : member.label || member.title;
}

function describe({ type, count }) {
    const key = `backend.ged.documents.family.usage_types.${type.replaceAll(".", "_")}`;

    return t(te(key) ? key : "backend.ged.documents.family.usage_types.other", { count }, count);
}
</script>

<template>
    <p class="text-xs text-secondary" data-family-usage>
        <template v-if="used.length">
            <span
                v-for="({ member, parts }, index) in used"
                :key="member.id"
                class="inline-flex items-center gap-1"
            >
                <span v-if="index > 0" class="text-muted px-0.5">·</span>
                <span
                    v-if="labelSwatch(member.label)"
                    class="h-2 w-2 shrink-0 rounded-full"
                    :style="{ backgroundColor: labelSwatch(member.label) }"
                    aria-hidden="true"
                />
                <span class="font-medium text-primary">{{ nameOf(member) }}</span>
                <span>{{ parts.map(describe).join(", ") }}</span>
            </span>
        </template>
        <span v-else class="text-amber-600 dark:text-amber-400">{{ t("backend.ged.documents.family.usage_none") }}</span>
    </p>
</template>
