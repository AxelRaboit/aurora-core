<script setup>
/**
 * The identity block of a customer, as one component.
 *
 * Create and edit ask for exactly the same fields, so they share these rather
 * than each carrying their own copy - two copies of a fifteen-field form drift
 * the first time one field is added to only one of them.
 *
 * The grouping is the one the contracts use: who the company is, who signs for
 * it, how to reach it. Reading the form and reading the contract's opening
 * page should feel like the same document.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";

const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    userOptions: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

/**
 * Prospect ou client.
 *
 * Ecrit ici et pas recu en props : l'enum a deux cas, il ne bougera pas, et le
 * faire traverser le controleur, la vue et le composant parent pour lister deux
 * valeurs coute plus que ce que ca rapporte.
 */
const statusOptions = computed(() => [
    { value: "prospect", label: t("backend.studio.customers.statuses.prospect") },
    { value: "client", label: t("backend.studio.customers.statuses.client") },
]);

const form = computed(() => props.modelValue);

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}

const currencyOptions = computed(() =>
    props.currencies.map((currency) => ({
        value: currency.value,
        label: `${currency.value} (${currency.symbol})`,
    })),
);
</script>

<template>
    <div class="space-y-6">
        <section class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.customers.group_identity") }}
            </h3>

            <!-- En tete de la fiche, parce que c'est ce qui dit au lecteur
                 pourquoi la moitie des champs plus bas sont vides : un
                 prospect n'a pas encore de SIRET, ni de forme juridique, ni
                 de siege. -->
            <AppSelect
                :model-value="form.status"
                :label="t('backend.studio.customers.status')"
                :options="statusOptions"
                :hint="t('backend.studio.customers.status_hint')"
                :error="errors.status"
                v-on:update:model-value="set('status', $event)"
            />

            <AppInput
                :model-value="form.legalName"
                :label="t('backend.studio.customers.legal_name')"
                :placeholder="t('backend.studio.customers.legal_name_placeholder')"
                :error="errors.legalName"
                required
                v-on:update:model-value="set('legalName', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.legalForm"
                    :label="t('backend.studio.customers.legal_form')"
                    :placeholder="t('backend.studio.customers.legal_form_placeholder')"
                    :error="errors.legalForm"
                    v-on:update:model-value="set('legalForm', $event)"
                />
                <AppInput
                    :model-value="form.activitySector"
                    :label="t('backend.studio.customers.activity_sector')"
                    :placeholder="t('backend.studio.customers.activity_sector_placeholder')"
                    :error="errors.activitySector"
                    :hint="t('backend.studio.customers.activity_sector_hint')"
                    v-on:update:model-value="set('activitySector', $event)"
                />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <AppInput
                        :model-value="form.shareCapital"
                        :label="t('backend.studio.customers.share_capital')"
                        :placeholder="t('backend.studio.customers.share_capital_placeholder')"
                        :error="errors.shareCapitalCents"
                        :hint="t('backend.studio.customers.share_capital_hint')"
                        v-on:update:model-value="set('shareCapital', $event)"
                    />
                </div>
                <AppSelect
                    :model-value="form.shareCapitalCurrency"
                    :label="t('backend.studio.customers.currency')"
                    :options="currencyOptions"
                    v-on:update:model-value="set('shareCapitalCurrency', $event)"
                />
            </div>

            <AppTextarea
                :model-value="form.registeredOffice"
                :label="t('backend.studio.customers.registered_office')"
                :placeholder="t('backend.studio.customers.registered_office_placeholder')"
                :error="errors.registeredOffice"
                :rows="2"
                v-on:update:model-value="set('registeredOffice', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-3">
                <AppInput
                    :model-value="form.siret"
                    :label="t('backend.studio.customers.siret')"
                    :placeholder="t('backend.studio.customers.siret_placeholder')"
                    :error="errors.siret"
                    :hint="t('backend.studio.customers.siret_hint')"
                    v-on:update:model-value="set('siret', $event)"
                />
                <AppInput
                    :model-value="form.tradeRegister"
                    :label="t('backend.studio.customers.trade_register')"
                    :placeholder="t('backend.studio.customers.trade_register_placeholder')"
                    :error="errors.tradeRegister"
                    v-on:update:model-value="set('tradeRegister', $event)"
                />
                <AppInput
                    :model-value="form.vatNumber"
                    :label="t('backend.studio.customers.vat_number')"
                    :placeholder="t('backend.studio.customers.vat_number_placeholder')"
                    :error="errors.vatNumber"
                    v-on:update:model-value="set('vatNumber', $event)"
                />
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.customers.group_representative") }}
            </h3>

            <div class="grid gap-4 sm:grid-cols-3">
                <AppInput
                    :model-value="form.representativeFirstName"
                    :label="t('backend.studio.customers.representative_first_name')"
                    :placeholder="t('backend.studio.customers.representative_first_name_placeholder')"
                    :error="errors.representativeFirstName"
                    v-on:update:model-value="set('representativeFirstName', $event)"
                />
                <AppInput
                    :model-value="form.representativeLastName"
                    :label="t('backend.studio.customers.representative_last_name')"
                    :placeholder="t('backend.studio.customers.representative_last_name_placeholder')"
                    :error="errors.representativeLastName"
                    v-on:update:model-value="set('representativeLastName', $event)"
                />
                <AppInput
                    :model-value="form.representativeRole"
                    :label="t('backend.studio.customers.representative_role')"
                    :placeholder="t('backend.studio.customers.representative_role_placeholder')"
                    :error="errors.representativeRole"
                    v-on:update:model-value="set('representativeRole', $event)"
                />
            </div>
        </section>

        <section class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.customers.group_contact") }}
            </h3>

            <div class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.contractualEmail"
                    :label="t('backend.studio.customers.contractual_email')"
                    :placeholder="t('backend.studio.customers.contractual_email_placeholder')"
                    :error="errors.contractualEmail"
                    :hint="t('backend.studio.customers.contractual_email_hint')"
                    type="email"
                    :required="'prospect' !== form.status"
                    v-on:update:model-value="set('contractualEmail', $event)"
                />
                <AppInput
                    :model-value="form.phone"
                    :label="t('backend.studio.customers.phone')"
                    :placeholder="t('backend.studio.customers.phone_placeholder')"
                    :error="errors.phone"
                    v-on:update:model-value="set('phone', $event)"
                />
            </div>

            <AppSelect
                :model-value="String(form.userId ?? '')"
                :label="t('backend.studio.customers.account')"
                :placeholder="t('backend.studio.customers.account_none')"
                :options="userOptions"
                :hint="t('backend.studio.customers.account_hint')"
                :error="errors.userId"
                v-on:update:model-value="set('userId', $event)"
            />
        </section>
    </div>
</template>
