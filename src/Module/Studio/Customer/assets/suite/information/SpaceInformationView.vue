<script setup>
/**
 * La fiche du client, remplie depuis son espace.
 *
 * **La fiche d'abord, le formulaire dans une fenêtre.** On vient ici le plus
 * souvent pour relire la fiche, rarement pour la changer : la garder ouverte
 * en formulaire faisait de chaque visite une saisie. Le récapitulatif est le
 * composant que la page du client utilise, donc ce qu'on relit ici est
 * littéralement ce qu'il a sous les yeux - il ne peut pas y avoir deux
 * versions qui divergent.
 *
 * **La fiche est au client, pas au projet.** Deux espaces ouverts pour la même
 * société montrent la même fiche et se modifient au même endroit : un SIRET
 * appartient à une entreprise, et une copie par espace se serait contredite
 * dès le deuxième projet. L'écran le dit, parce que modifier depuis un projet
 * quelque chose qui vaut pour tous doit être annoncé.
 *
 * Écrire demande le droit sur les clients et non sur les espaces : tenir le
 * tableau d'un espace n'autorise pas à changer l'identité de la société.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Pencil, Plus, Save, Trash2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import CustomerInformationCard from "../../shared/CustomerInformationCard.vue";

const props = defineProps({
    information: { type: Object, required: true },
    savePath: { type: String, required: true },
    /** Ses contrats, ses livrables de Studio, ses autres espaces : null pour ce que le lecteur ne peut pas ouvrir. */
    related: { type: Object, default: () => ({}) },
});

/** Les trois listes qui ont quelque chose à montrer, dans l'ordre où on les consulte. */
const relatedGroups = computed(() =>
    [
        { key: "contracts", titleKey: "suite.studio.space_information.related_contracts", rows: props.related?.contracts },
        { key: "deliverables", titleKey: "suite.studio.space_information.related_deliverables", rows: props.related?.deliverables },
        { key: "spaces", titleKey: "suite.studio.space_information.related_spaces", rows: props.related?.spaces },
    ].filter((group) => Array.isArray(group.rows) && group.rows.length),
);

const emit = defineEmits(["saved"]);

const { t } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();

const editable = computed(() => can("studio.customers.edit"));

const saving = ref(false);
const errors = ref({});
const editing = ref(false);

/**
 * L'état du formulaire, détaché de ce que le serveur a rendu.
 *
 * Une copie et non la propriété elle-même : la fiche revient entière à chaque
 * enregistrement, et modifier l'objet reçu ferait dépendre l'écran de la
 * réactivité d'une propriété qu'il ne possède pas.
 */
const form = ref(blank());

function blank() {
    return { legalName: "", siret: "", siren: "", phone: "", landline: "", email: "", postalAddress: "", links: [], notes: "" };
}

function fill(information) {
    form.value = {
        legalName: information?.legalName ?? "",
        siret: information?.siret ?? "",
        siren: information?.siren ?? "",
        phone: information?.phone ?? "",
        landline: information?.landline ?? "",
        email: information?.email ?? "",
        postalAddress: information?.postalAddress ?? "",
        // Copiés ligne par ligne : partager le tableau du serveur ferait
        // bouger le récapitulatif pendant la frappe, avant tout enregistrement.
        links: (information?.links ?? []).map((link) => ({ label: link.label ?? "", url: link.url ?? "" })),
        notes: information?.notes ?? "",
    };
    errors.value = {};
}

fill(props.information);

watch(() => props.information, fill);

/**
 * Ce que le récapitulatif montre : la fiche enregistrée, pas la saisie.
 *
 * **Délibérément pas un aperçu en direct.** « Voici ce que votre client voit »
 * doit décrire l'état du serveur ; le faire suivre la frappe dirait au studio
 * que le client lit déjà ce qui n'est pas encore enregistré.
 */
const saved = computed(() => props.information);

const dirty = computed(() => JSON.stringify(form.value) !== JSON.stringify(fromSaved()));

function fromSaved() {
    const information = props.information ?? {};

    return {
        legalName: information.legalName ?? "",
        siret: information.siret ?? "",
        siren: information.siren ?? "",
        phone: information.phone ?? "",
        landline: information.landline ?? "",
        email: information.email ?? "",
        postalAddress: information.postalAddress ?? "",
        links: (information.links ?? []).map((link) => ({ label: link.label ?? "", url: link.url ?? "" })),
        notes: information.notes ?? "",
    };
}

/** Ouverte sur la fiche enregistrée : une saisie abandonnée ne revient pas. */
function openEdit() {
    fill(props.information);
    editing.value = true;
}

function closeEdit() {
    editing.value = false;
    fill(props.information);
}

function addLink() {
    form.value.links.push({ label: "", url: "" });
}

function removeLink(index) {
    form.value.links.splice(index, 1);
}

/**
 * L'erreur d'une ligne de liens.
 *
 * Le serveur les rend sous `links[2].url`, ce qui est ce qui permet de la
 * poser sous le bon champ plutôt que d'annoncer qu'« un des liens » est
 * invalide, ce que l'écran ne saurait pas placer.
 */
function linkError(index, field) {
    return errors.value[`links[${index}].${field}`] ?? "";
}

async function save() {
    saving.value = true;
    errors.value = {};

    try {
        const data = await request(props.savePath, form.value);

        if (!data?.success) {
            // **Le refus est dit.** `request` rend l'enveloppe d'un 400 sans
            // rien annoncer : sans ceci, un SIRET invalide ne produirait rien
            // à l'écran, ce qui se lit comme un bouton mort.
            errors.value = data?.errors ?? {};

            if (0 === Object.keys(errors.value).length) {
                toast.error(t(data?.error ?? "shared.common.error"));
            }

            return;
        }

        emit("saved", data.information);
        editing.value = false;
        toast.success(t("suite.studio.space_information.saved"));
    } finally {
        saving.value = false;
    }
}
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
                        <AppButton
                            v-if="editable"
                            variant="secondary"
                            size="sm"
                            class="w-full sm:w-auto"
                            v-on:click="openEdit"
                        >
                            <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("suite.studio.space_information.edit") }}
                        </AppButton>
                    </header>
                    <p class="m-0 text-xs text-muted">{{ t("suite.studio.space_information.scope") }}</p>
                </div>
                <CustomerInformationCard :information="saved" />
            </article>
        </section>

        <!-- Autour de ce client : ce qui le relie au reste du Studio. -->
        <section class="flex flex-col gap-3">
            <h2 class="m-0 text-xs font-semibold uppercase tracking-wider text-muted">
                {{ t("suite.studio.space_information.group_related") }}
            </h2>
            <article class="aurora-card flex flex-col gap-4 p-3 sm:p-4">
                <template v-if="relatedGroups.length">
                    <section v-for="group in relatedGroups" :key="group.key" class="flex flex-col gap-1.5">
                        <h3 class="m-0 text-xs uppercase tracking-wide text-muted">{{ t(group.titleKey) }}</h3>
                        <ul class="m-0 list-none divide-y divide-line/60 p-0">
                            <li v-for="row in group.rows" :key="row.url">
                                <a :href="row.url" class="flex items-center justify-between gap-3 py-1.5 text-sm text-primary no-underline hover:text-accent-500 hover:underline">
                                    <span class="min-w-0 truncate">{{ row.label }}</span>
                                    <span v-if="row.detail" class="shrink-0 text-xs text-muted">{{ row.detail }}</span>
                                </a>
                            </li>
                        </ul>
                    </section>
                </template>
                <p v-else class="m-0 text-xs text-muted">{{ t("suite.studio.space_information.related_empty") }}</p>
            </article>
        </section>

        <AppModal
            :show="editing"
            max-width="2xl"
            mobile-fullscreen
            :title="t('suite.studio.space_information.edit_title')"
            v-on:close="closeEdit"
        >
            <form id="space-information-form" class="flex flex-col gap-5" v-on:submit.prevent="save">
                <AppMessage variant="neutral">{{ t("suite.studio.space_information.scope") }}</AppMessage>

                <!-- Une colonne sur téléphone, deux à partir de `sm` : deux
                     colonnes de champs sur 375 px donnent des libellés tronqués. -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="form.legalName"
                        class="sm:col-span-2"
                        :label="t('suite.studio.space_information.legal_name')"
                        :placeholder="t('suite.studio.space_information.legal_name_placeholder')"
                        :error="errors.legalName ? t(errors.legalName) : ''"
                        required
                    />
                    <AppInput
                        v-model="form.siret"
                        :label="t('shared.space_information.siret')"
                        :placeholder="t('suite.studio.space_information.siret_placeholder')"
                        :hint="t('suite.studio.space_information.siret_hint')"
                        :error="errors.siret ? t(errors.siret) : ''"
                    />
                    <AppInput
                        v-model="form.siren"
                        :label="t('shared.space_information.siren')"
                        :placeholder="t('suite.studio.space_information.siren_placeholder')"
                        :hint="t('suite.studio.space_information.siren_hint')"
                        :error="errors.siren ? t(errors.siren) : ''"
                    />
                    <AppInput
                        v-model="form.phone"
                        :label="t('shared.space_information.phone')"
                        :placeholder="t('suite.studio.space_information.phone_placeholder')"
                        :error="errors.phone ? t(errors.phone) : ''"
                    />
                    <AppInput
                        v-model="form.landline"
                        :label="t('shared.space_information.landline')"
                        :placeholder="t('suite.studio.space_information.landline_placeholder')"
                        :error="errors.landline ? t(errors.landline) : ''"
                    />
                    <AppInput
                        v-model="form.email"
                        class="sm:col-span-2"
                        type="email"
                        :label="t('shared.space_information.email')"
                        :placeholder="t('suite.studio.space_information.email_placeholder')"
                        :hint="t('suite.studio.space_information.email_hint')"
                        :error="errors.email ? t(errors.email) : ''"
                    />
                    <AppTextarea
                        v-model="form.postalAddress"
                        class="sm:col-span-2"
                        :label="t('shared.space_information.postal_address')"
                        :placeholder="t('suite.studio.space_information.postal_address_placeholder')"
                        :error="errors.postalAddress ? t(errors.postalAddress) : ''"
                        :rows="3"
                    />
                </div>

                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-0.5">
                        <p class="m-0 text-sm font-medium text-primary">{{ t("shared.space_information.links") }}</p>
                        <p class="m-0 text-xs text-muted">{{ t("suite.studio.space_information.links_hint") }}</p>
                    </div>

                    <div v-for="(link, index) in form.links" :key="index" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                        <AppInput
                            v-model="link.label"
                            class="sm:w-1/3"
                            :placeholder="t('suite.studio.space_information.link_label_placeholder')"
                            :error="linkError(index, 'label') ? t(linkError(index, 'label')) : ''"
                        />
                        <AppInput
                            v-model="link.url"
                            class="sm:flex-1"
                            :placeholder="t('suite.studio.space_information.link_url_placeholder')"
                            :error="linkError(index, 'url') ? t(linkError(index, 'url')) : ''"
                        />
                        <!-- Le geste est écrit en toutes lettres sur téléphone :
                             une icône seule dans une ligne de champs ne dit pas
                             laquelle des deux lignes elle retire. -->
                        <AppButton
                            variant="ghost"
                            size="sm"
                            class="w-full justify-center sm:w-auto"
                            v-on:click="removeLink(index)"
                        >
                            <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                            <span>{{ t("suite.studio.space_information.link_remove") }}</span>
                        </AppButton>
                    </div>

                    <AppButton variant="ghost" size="sm" class="w-full justify-center sm:w-auto sm:self-start" v-on:click="addLink">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("suite.studio.space_information.link_add") }}
                    </AppButton>
                </div>

                <AppTextarea
                    v-model="form.notes"
                    :label="t('shared.space_information.notes')"
                    :placeholder="t('suite.studio.space_information.notes_placeholder')"
                    :hint="t('suite.studio.space_information.notes_hint')"
                    :error="errors.notes ? t(errors.notes) : ''"
                    :rows="5"
                />
            </form>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="closeEdit">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        type="submit"
                        form="space-information-form"
                        size="md"
                        :loading="saving"
                        :disabled="!dirty || saving"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
