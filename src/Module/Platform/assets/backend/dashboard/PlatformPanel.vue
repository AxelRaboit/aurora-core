<script setup>
/**
 * Platform's dashboard panel: who has a backend account, and at what level.
 *
 * One figure and one composition - the module has less to count than Editorial
 * or the library, and padding the row with three more tiles would say less, not
 * more.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Users } from "lucide-vue-next";
import AppShareBar from "@/shared/components/chart/AppShareBar.vue";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

/**
 * Mirrors `UserRoleEnum::getLabelKey()`, which is where a role's label lives.
 * Written out rather than derived from the role string: the enum maps
 * `ROLE_USER` to `…role.user`, and a rule that happens to hold for three values
 * is not a rule - the day a role's key does not match its constant, a silent
 * fallback to the raw `ROLE_X` is what reaches the screen.
 */
const ROLE_LABEL_KEYS = {
    ROLE_USER: "backend.users.role.user",
    ROLE_ADMIN: "backend.users.role.admin",
    ROLE_DEV: "backend.users.role.dev",
};

/**
 * In the order the enum declares them, so a role keeps its colour between
 * visits and between this panel and any other chart that reads the same list.
 */
const byRole = computed(() =>
    Object.entries(props.stats.byRole ?? {})
        .filter(([role]) => ROLE_LABEL_KEYS[role])
        .map(([role, count]) => ({
            key: role,
            label: t(ROLE_LABEL_KEYS[role]),
            value: count,
            // Named, not inferred from position: a role keeps its colour when a
            // role above it in the list has nobody in it.
            slot: props.stats.roleSlots?.[role],
        })),
);
</script>

<template>
    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-surface border border-line rounded-xl p-4">
                <div class="flex items-center gap-2 text-secondary text-xs uppercase tracking-wide">
                    <Users class="w-4 h-4 shrink-0" :stroke-width="2" />
                    {{ t("backend.stats.platform.users") }}
                </div>
                <p class="text-2xl font-semibold text-primary mt-2">{{ stats.users ?? 0 }}</p>
            </div>
        </div>

        <div v-if="byRole.length" class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-primary">{{ t("backend.stats.platform.by_role") }}</h3>

            <AppShareBar :segments="byRole" />
        </div>
    </div>
</template>
