<script setup>
/**
 * The whole record of a customer, as one component.
 *
 * Create and edit ask for exactly the same fields, so they share these rather
 * than each carrying their own copy - two copies of a form drift the first time
 * one field is added to only one of them. That is not a hypothetical: the
 * Information tab of a space had its own form, with the SIREN, the landline,
 * the links and the notes that this one lacked. It is read-only now, and every
 * field lives here.
 *
 * The grouping is the one the contracts use: who the company is, who signs for
 * it, how to reach it. Then what the client reads on their own page: links and
 * notes.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Plus, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";

const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    currencies: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

/**
 * Prospect or customer.
 *
 * Written here and not received as props: the enum has two cases, it will not
 * move, and passing it through the controller, the view and the parent
 * component to list two values costs more than it brings.
 */
const statusOptions = computed(() => [
    { value: "prospect", label: t("suite.studio.customers.statuses.prospect") },
    { value: "client", label: t("suite.studio.customers.statuses.client") },
]);

const form = computed(() => props.modelValue);

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}

const links = computed(() => (Array.isArray(form.value.links) ? form.value.links : []));

function setLink(index, field, value) {
    set(
        "links",
        links.value.map((link, at) => (at === index ? { ...link, [field]: value } : link)),
    );
}

function addLink() {
    set("links", [...links.value, { label: "", url: "" }]);
}

function removeLink(index) {
    set(
        "links",
        links.value.filter((_link, at) => at !== index),
    );
}

/**
 * The error of a link row. The server returns them under `links[2].url`,
 * which lets it be placed under the right field rather than announcing that
 * "one of the links" is invalid.
 */
function linkError(index, field) {
    return props.errors[`links[${index}].${field}`] ?? "";
}

const currencyOptions = computed(() =>
    props.currencies.map((currency) => ({
        value: currency.value,
        label: `${currency.value} (${currency.symbol})`,
    })),
);
</script>

<template>
    <div class="space-y-5">
        <section class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("suite.studio.customers.group_identity") }}
            </h3>

            <!-- At the top of the sheet, because it is what tells the reader
                 why half the fields below are empty: a prospect does not yet
                 have a SIRET, a legal form or a head office. -->
            <AppSelect
                :model-value="form.status"
                :label="t('suite.studio.customers.status')"
                :options="statusOptions"
                :hint="t('suite.studio.customers.status_hint')"
                :error="errors.status"
                v-on:update:model-value="set('status', $event)"
            />

            <AppInput
                :model-value="form.legalName"
                :label="t('suite.studio.customers.legal_name')"
                :placeholder="t('suite.studio.customers.legal_name_placeholder')"
                :error="errors.legalName"
                required
                v-on:update:model-value="set('legalName', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.legalForm"
                    :label="t('suite.studio.customers.legal_form')"
                    :placeholder="t('suite.studio.customers.legal_form_placeholder')"
                    :error="errors.legalForm"
                    v-on:update:model-value="set('legalForm', $event)"
                />
                <AppInput
                    :model-value="form.activitySector"
                    :label="t('suite.studio.customers.activity_sector')"
                    :placeholder="t('suite.studio.customers.activity_sector_placeholder')"
                    :error="errors.activitySector"
                    :hint="t('suite.studio.customers.activity_sector_hint')"
                    v-on:update:model-value="set('activitySector', $event)"
                />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <AppInput
                        :model-value="form.shareCapital"
                        :label="t('suite.studio.customers.share_capital')"
                        :placeholder="t('suite.studio.customers.share_capital_placeholder')"
                        :error="errors.shareCapitalCents"
                        :hint="t('suite.studio.customers.share_capital_hint')"
                        v-on:update:model-value="set('shareCapital', $event)"
                    />
                </div>
                <AppSelect
                    :model-value="form.shareCapitalCurrency"
                    :label="t('suite.studio.customers.currency')"
                    :options="currencyOptions"
                    v-on:update:model-value="set('shareCapitalCurrency', $event)"
                />
            </div>

            <AppTextarea
                :model-value="form.registeredOffice"
                :label="t('suite.studio.customers.registered_office')"
                :placeholder="t('suite.studio.customers.registered_office_placeholder')"
                :error="errors.registeredOffice"
                :rows="2"
                v-on:update:model-value="set('registeredOffice', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.siret"
                    :label="t('suite.studio.customers.siret')"
                    :placeholder="t('suite.studio.customers.siret_placeholder')"
                    :error="errors.siret"
                    :hint="t('suite.studio.customers.siret_hint')"
                    v-on:update:model-value="set('siret', $event)"
                />
                <AppInput
                    :model-value="form.siren"
                    :label="t('suite.studio.customers.siren')"
                    :placeholder="t('suite.studio.customers.siren_placeholder')"
                    :error="errors.siren"
                    :hint="t('suite.studio.customers.siren_hint')"
                    v-on:update:model-value="set('siren', $event)"
                />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.tradeRegister"
                    :label="t('suite.studio.customers.trade_register')"
                    :placeholder="t('suite.studio.customers.trade_register_placeholder')"
                    :error="errors.tradeRegister"
                    v-on:update:model-value="set('tradeRegister', $event)"
                />
                <AppInput
                    :model-value="form.vatNumber"
                    :label="t('suite.studio.customers.vat_number')"
                    :placeholder="t('suite.studio.customers.vat_number_placeholder')"
                    :error="errors.vatNumber"
                    v-on:update:model-value="set('vatNumber', $event)"
                />
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("suite.studio.customers.group_representative") }}
            </h3>

            <div class="grid gap-4 sm:grid-cols-3">
                <AppInput
                    :model-value="form.representativeFirstName"
                    :label="t('suite.studio.customers.representative_first_name')"
                    :placeholder="t('suite.studio.customers.representative_first_name_placeholder')"
                    :error="errors.representativeFirstName"
                    v-on:update:model-value="set('representativeFirstName', $event)"
                />
                <AppInput
                    :model-value="form.representativeLastName"
                    :label="t('suite.studio.customers.representative_last_name')"
                    :placeholder="t('suite.studio.customers.representative_last_name_placeholder')"
                    :error="errors.representativeLastName"
                    v-on:update:model-value="set('representativeLastName', $event)"
                />
                <AppInput
                    :model-value="form.representativeRole"
                    :label="t('suite.studio.customers.representative_role')"
                    :placeholder="t('suite.studio.customers.representative_role_placeholder')"
                    :error="errors.representativeRole"
                    v-on:update:model-value="set('representativeRole', $event)"
                />
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("suite.studio.customers.group_contact") }}
            </h3>

            <AppInput
                :model-value="form.contractualEmail"
                :label="t('suite.studio.customers.contractual_email')"
                :placeholder="t('suite.studio.customers.contractual_email_placeholder')"
                :error="errors.contractualEmail"
                :hint="t('suite.studio.customers.contractual_email_hint')"
                type="email"
                :required="'prospect' !== form.status"
                v-on:update:model-value="set('contractualEmail', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.phone"
                    :label="t('suite.studio.customers.phone')"
                    :placeholder="t('suite.studio.customers.phone_placeholder')"
                    :error="errors.phone"
                    v-on:update:model-value="set('phone', $event)"
                />
                <AppInput
                    :model-value="form.landline"
                    :label="t('suite.studio.customers.landline')"
                    :placeholder="t('suite.studio.customers.landline_placeholder')"
                    :error="errors.landline"
                    v-on:update:model-value="set('landline', $event)"
                />
            </div>
        </section>

        <!-- What the customer reads in the Informations tab of their spaces:
             say so here, since this is where it is written. -->
        <section class="space-y-4">
            <div class="space-y-0.5">
                <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                    {{ t("suite.studio.customers.group_links") }}
                </h3>
                <p class="m-0 text-xs text-muted">{{ t("suite.studio.customers.group_links_hint") }}</p>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-0.5">
                    <p class="m-0 text-sm font-medium text-primary">{{ t("suite.studio.customers.links") }}</p>
                    <p class="m-0 text-xs text-muted">{{ t("suite.studio.customers.links_hint") }}</p>
                </div>

                <div
                    v-for="(link, index) in links"
                    :key="index"
                    class="flex flex-col gap-2 sm:flex-row sm:items-start"
                    data-customer-link
                >
                    <AppInput
                        :model-value="link.label"
                        class="sm:w-1/3"
                        :placeholder="t('suite.studio.customers.link_label_placeholder')"
                        :error="linkError(index, 'label')"
                        v-on:update:model-value="setLink(index, 'label', $event)"
                    />
                    <AppInput
                        :model-value="link.url"
                        class="sm:flex-1"
                        :placeholder="t('suite.studio.customers.link_url_placeholder')"
                        :error="linkError(index, 'url')"
                        v-on:update:model-value="setLink(index, 'url', $event)"
                    />
                    <!-- The gesture is spelled out on phones: an icon alone
                         in a row of fields does not say which of the two rows
                         it removes. -->
                    <AppButton
                        variant="ghost"
                        size="sm"
                        class="w-full justify-center sm:w-auto"
                        v-on:click="removeLink(index)"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        <span>{{ t("suite.studio.customers.link_remove") }}</span>
                    </AppButton>
                </div>

                <AppButton
                    variant="ghost"
                    size="sm"
                    class="w-full justify-center sm:w-auto sm:self-start"
                    v-on:click="addLink"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("suite.studio.customers.link_add") }}
                </AppButton>
            </div>

            <AppTextarea
                :model-value="form.informationNotes"
                :label="t('suite.studio.customers.notes')"
                :placeholder="t('suite.studio.customers.notes_placeholder')"
                :hint="t('suite.studio.customers.notes_hint')"
                :error="errors.informationNotes"
                :rows="5"
                v-on:update:model-value="set('informationNotes', $event)"
            />
        </section>
    </div>
</template>
