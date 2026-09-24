<script setup>
/**
 * La fiche du client, remplie depuis son espace.
 *
 * **Le formulaire au-dessus, ce que le client lit en dessous.** Ce sont deux
 * choses différentes et non deux styles du même écran : on remplit des champs,
 * puis on relit une fiche. Le récapitulatif est le composant que la page du
 * client utilise, donc ce qu'on relit ici est littéralement ce qu'il a sous
 * les yeux - il ne peut pas y avoir deux versions qui divergent.
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
import { Plus, RotateCcw, Save, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import CustomerInformationCard from "../../shared/CustomerInformationCard.vue";

const props = defineProps({
    information: { type: Object, required: true },
    savePath: { type: String, required: true },
});

const emit = defineEmits(["saved"]);

const { t } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();

const editable = computed(() => can("studio.customers.edit"));

const saving = ref(false);
const errors = ref({});

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
        toast.success(t("backend.studio.space_information.saved"));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <AppMessage variant="neutral">{{ t("backend.studio.space_information.scope") }}</AppMessage>

        <form v-if="editable" class="space-y-5" v-on:submit.prevent="save">
            <!-- Une colonne sur téléphone, deux à partir de `sm` : deux
                 colonnes de champs sur 375 px donnent des libellés tronqués. -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <AppInput
                    v-model="form.legalName"
                    class="sm:col-span-2"
                    :label="t('backend.studio.space_information.legal_name')"
                    :placeholder="t('backend.studio.space_information.legal_name_placeholder')"
                    :error="errors.legalName ? t(errors.legalName) : ''"
                    required
                />
                <AppInput
                    v-model="form.siret"
                    :label="t('shared.space_information.siret')"
                    :placeholder="t('backend.studio.space_information.siret_placeholder')"
                    :hint="t('backend.studio.space_information.siret_hint')"
                    :error="errors.siret ? t(errors.siret) : ''"
                />
                <AppInput
                    v-model="form.siren"
                    :label="t('shared.space_information.siren')"
                    :placeholder="t('backend.studio.space_information.siren_placeholder')"
                    :hint="t('backend.studio.space_information.siren_hint')"
                    :error="errors.siren ? t(errors.siren) : ''"
                />
                <AppInput
                    v-model="form.phone"
                    :label="t('shared.space_information.phone')"
                    :placeholder="t('backend.studio.space_information.phone_placeholder')"
                    :error="errors.phone ? t(errors.phone) : ''"
                />
                <AppInput
                    v-model="form.landline"
                    :label="t('shared.space_information.landline')"
                    :placeholder="t('backend.studio.space_information.landline_placeholder')"
                    :error="errors.landline ? t(errors.landline) : ''"
                />
                <AppInput
                    v-model="form.email"
                    class="sm:col-span-2"
                    type="email"
                    :label="t('shared.space_information.email')"
                    :placeholder="t('backend.studio.space_information.email_placeholder')"
                    :hint="t('backend.studio.space_information.email_hint')"
                    :error="errors.email ? t(errors.email) : ''"
                />
                <AppTextarea
                    v-model="form.postalAddress"
                    class="sm:col-span-2"
                    :label="t('shared.space_information.postal_address')"
                    :placeholder="t('backend.studio.space_information.postal_address_placeholder')"
                    :error="errors.postalAddress ? t(errors.postalAddress) : ''"
                    :rows="3"
                />
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-primary">{{ t("shared.space_information.links") }}</p>
                        <p class="text-xs text-muted">{{ t("backend.studio.space_information.links_hint") }}</p>
                    </div>
                </div>

                <div v-for="(link, index) in form.links" :key="index" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <AppInput
                        v-model="link.label"
                        class="sm:w-1/3"
                        :placeholder="t('backend.studio.space_information.link_label_placeholder')"
                        :error="linkError(index, 'label') ? t(linkError(index, 'label')) : ''"
                    />
                    <AppInput
                        v-model="link.url"
                        class="sm:flex-1"
                        :placeholder="t('backend.studio.space_information.link_url_placeholder')"
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
                        <span>{{ t("backend.studio.space_information.link_remove") }}</span>
                    </AppButton>
                </div>

                <AppButton variant="ghost" size="sm" class="w-full justify-center sm:w-auto" v-on:click="addLink">
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.space_information.link_add") }}
                </AppButton>
            </div>

            <AppTextarea
                v-model="form.notes"
                :label="t('shared.space_information.notes')"
                :placeholder="t('backend.studio.space_information.notes_placeholder')"
                :hint="t('backend.studio.space_information.notes_hint')"
                :error="errors.notes ? t(errors.notes) : ''"
                :rows="5"
            />

            <!-- Deux boutons : pleine largeur empilés sur téléphone, côte à
                 côte ensuite - la convention de l'application. -->
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end sm:gap-3">
                <AppButton
                    variant="ghost"
                    class="w-full justify-center sm:w-auto"
                    :disabled="!dirty || saving"
                    v-on:click="fill(props.information)"
                >
                    <RotateCcw class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    type="submit"
                    class="w-full justify-center sm:w-auto"
                    :loading="saving"
                    :disabled="!dirty || saving"
                >
                    <Save class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.save") }}
                </AppButton>
            </div>
        </form>

        <div class="aurora-card space-y-3 p-4">
            <p class="text-xs uppercase tracking-wide text-muted">
                {{ t("backend.studio.space_information.what_the_client_sees") }}
            </p>
            <CustomerInformationCard :information="saved" />
        </div>
    </div>
</template>
