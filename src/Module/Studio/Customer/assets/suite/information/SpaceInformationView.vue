<script setup>
/**
 * The customer's sheet, seen from their space.
 *
 * **Read-only, on purpose.** The tab had its own form, which did not carry
 * the same fields as the customers screen's: the SIREN, landline, links and
 * notes could only be entered from here, the capital and RCS only from
 * there. The sheet is now edited in a single place, the customer's page, and
 * the tab leads there through "Modifier la fiche" for whoever has the right.
 *
 * The summary is the component the customer's page uses, so what is read
 * back here is literally what they have in front of them.
 *
 * **The sheet belongs to the customer, not to the project.** Two spaces
 * opened for the same company show the same sheet: a SIRET belongs to a
 * company, and one copy per space would have contradicted itself from the
 * second project on.
 */
import { useI18n } from "vue-i18n";
import { Pencil } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import CustomerInformationCard from "../../shared/CustomerInformationCard.vue";
import CustomerRelatedLists from "../components/CustomerRelatedLists.vue";

defineProps({
    information: { type: Object, required: true },
    /** Their contracts, Studio deliverables, other spaces: null for what the reader cannot open. */
    related: { type: Object, default: () => ({}) },
    /** The customer's page, or null for whoever cannot edit the sheet there. */
    customerPath: { type: String, default: null },
});

const { t } = useI18n();
</script>

<template>
    <!-- The sheet on the left, what links the customer to the rest on the
         right on a large screen; one under the other elsewhere. -->
    <div class="grid grid-cols-1 items-start aurora-gap lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <section class="flex flex-col gap-3">
            <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                {{ t("suite.studio.space_information.group_card") }}
            </h2>
            <article class="aurora-card flex flex-col gap-4 p-3 sm:p-4">
                <div class="flex flex-col gap-1">
                    <header class="flex flex-wrap items-start justify-between gap-2">
                        <h3 class="m-0 text-sm font-medium text-primary">{{ t("suite.studio.space_information.what_the_client_sees") }}</h3>
                        <!-- A link and not a gesture: it changes page, and the
                             page must be able to open in another tab. -->
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

        <!-- Around this customer: what links them to the rest of Studio. -->
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
