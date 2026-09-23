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
import AppStatTile from "@/shared/components/display/AppStatTile.vue";
import AppShareBar from "@/shared/components/chart/AppShareBar.vue";
import { hasAnyShare } from "@/shared/utils/data/hasAnyShare.js";

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
            <AppStatTile
                :icon="Users"
                :label="t('backend.stats.platform.users')"
                :value="stats.users ?? 0"
            />
        </div>

        <div v-if="hasAnyShare(byRole)" class="aurora-card p-3 sm:p-5 space-y-4">
            <h3 class="text-sm font-semibold text-primary">{{ t("backend.stats.platform.by_role") }}</h3>

            <AppShareBar :segments="byRole" />
        </div>
    </div>
</template>
