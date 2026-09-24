<script setup>
/**
 * Le panneau du Studio : ce qui tourne, et ce qui attend quelqu'un.
 *
 * Même forme que les autres panneaux, délibérément : une rangée de chiffres
 * puis des compositions. Un tableau de bord dont chaque onglet invente sa mise
 * en page oblige à la réapprendre à chaque fois.
 *
 * **Deux chiffres sont mis en avant plutôt que tous.** « En attente du client »
 * et « signature attendue » sont les seuls sur lesquels on agit en arrivant :
 * les autres situent. Une nuance sur ces deux-là seulement, parce qu'un écran
 * où tout est coloré ne désigne plus rien.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarClock, FileSignature, FolderKanban, Presentation } from "lucide-vue-next";
import AppShareBar from "@/shared/components/chart/AppShareBar.vue";
import AppStatTile from "@/shared/components/display/AppStatTile.vue";
import { hasAnyShare } from "@/shared/utils/data/hasAnyShare.js";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const totals = computed(() => [
    {
        key: "spaces",
        icon: FolderKanban,
        value: props.stats.activeSpaces ?? 0,
    },
    {
        key: "awaiting_client",
        icon: CalendarClock,
        value: props.stats.awaitingClient ?? 0,
        tone: (props.stats.awaitingClient ?? 0) > 0 ? "attention" : "default",
    },
    {
        key: "upcoming",
        icon: CalendarClock,
        value: props.stats.upcoming ?? 0,
    },
    {
        key: "decks",
        icon: Presentation,
        value: props.stats.decks ?? 0,
    },
]);

/** Les verdicts, dans l'ordre où une carte les traverse. */
const APPROVALS = ["pending", "changes_requested", "approved"];

const byApproval = computed(() =>
    APPROVALS.filter((key) => key in (props.stats.itemsByApproval ?? {})).map((key) => ({
        key,
        label: t(`backend.studio.space_content.approvals.${key}`),
        value: props.stats.itemsByApproval[key],
    })),
);

/**
 * Les états d'un contrat, ceux qui existent seulement.
 *
 * Neuf colonnes dont sept à zéro diraient surtout que la barre a neuf
 * couleurs.
 */
const byContractStatus = computed(() =>
    Object.entries(props.stats.contractsByStatus ?? {})
        .filter(([, count]) => count > 0)
        .map(([key, value]) => ({
            key,
            label: t(`backend.studio.contracts.status.${key}`),
            value,
        })),
);
</script>

<template>
    <div class="space-y-5">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <AppStatTile
                v-for="total in totals"
                :key="total.key"
                :icon="total.icon"
                :label="t(`backend.stats.studio.${total.key}`)"
                :value="total.value"
                :tone="total.tone ?? 'default'"
            />
        </div>

        <div v-if="hasAnyShare(byApproval)" class="aurora-card space-y-4 p-3 sm:p-5">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.stats.studio.by_approval") }}</h3>
            <AppShareBar :segments="byApproval" />
        </div>

        <div v-if="hasAnyShare(byContractStatus)" class="aurora-card space-y-4 p-3 sm:p-5">
            <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                <FileSignature class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("backend.stats.studio.by_contract_status") }}
            </h3>
            <AppShareBar :segments="byContractStatus" />
        </div>
    </div>
</template>
