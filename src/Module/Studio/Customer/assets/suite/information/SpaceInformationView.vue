<script setup>
/**
 * La fiche du client, vue depuis son espace.
 *
 * **En lecture, et c'est voulu.** L'onglet avait son propre formulaire, qui ne
 * portait pas les mêmes champs que celui de l'écran des clients : le SIREN, le
 * fixe, les liens et les notes ne se saisissaient que d'ici, le capital et le
 * RCS que de là. La fiche se modifie maintenant en un seul endroit, la page du
 * client, et l'onglet y mène par « Modifier la fiche » pour qui en a le droit.
 *
 * Le récapitulatif est le composant que la page du client utilise, donc ce
 * qu'on relit ici est littéralement ce qu'il a sous les yeux.
 *
 * **La fiche est au client, pas au projet.** Deux espaces ouverts pour la même
 * société montrent la même fiche : un SIRET appartient à une entreprise, et
 * une copie par espace se serait contredite dès le deuxième projet.
 */
import { useI18n } from "vue-i18n";
import { Pencil } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import CustomerInformationCard from "../../shared/CustomerInformationCard.vue";
import CustomerRelatedLists from "../components/CustomerRelatedLists.vue";

defineProps({
    information: { type: Object, required: true },
    /** Ses contrats, ses livrables de Studio, ses autres espaces : null pour ce que le lecteur ne peut pas ouvrir. */
    related: { type: Object, default: () => ({}) },
    /** La page du client, ou null pour qui ne peut pas y modifier la fiche. */
    customerPath: { type: String, default: null },
});

const { t } = useI18n();
</script>

<template>
    <!-- La fiche à gauche, ce qui relie le client au reste à droite sur un
         grand écran ; l'une sous l'autre ailleurs. -->
    <div class="grid grid-cols-1 items-start aurora-gap lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <section class="flex flex-col gap-3">
            <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                {{ t("suite.studio.space_information.group_card") }}
            </h2>
            <article class="aurora-card flex flex-col gap-4 p-3 sm:p-4">
                <div class="flex flex-col gap-1">
                    <header class="flex flex-wrap items-start justify-between gap-2">
                        <h3 class="m-0 text-sm font-medium text-primary">{{ t("suite.studio.space_information.what_the_client_sees") }}</h3>
                        <!-- Un lien et non un geste : on change de page, et
                             elle doit pouvoir s'ouvrir dans un autre onglet. -->
                        <AppButton
                            v-if="customerPath"
                            :href="customerPath"
                            variant="secondary"
                            size="sm"
                            class="w-full sm:w-auto"
                        >
                            <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("suite.studio.space_information.edit") }}
                        </AppButton>
                    </header>
                    <p class="m-0 text-xs text-muted">{{ t("suite.studio.space_information.scope") }}</p>
                </div>
                <CustomerInformationCard :information="information" />
            </article>
        </section>

        <!-- Autour de ce client : ce qui le relie au reste du Studio. -->
        <section class="flex flex-col gap-3">
            <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                {{ t("suite.studio.space_information.group_related") }}
            </h2>
            <article class="aurora-card p-3 sm:p-4">
                <CustomerRelatedLists :related="related" from-space />
            </article>
        </section>
    </div>
</template>
