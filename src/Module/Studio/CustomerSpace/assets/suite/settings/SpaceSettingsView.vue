<script setup>
/**
 * A space's Settings tab: everything about the space, in one place.
 *
 * **Sub-tabs, one per subject.** « Espace » (name, description, customer,
 * colour, timezone, status) for whoever may edit the space; « Équipe » (who is
 * on it, and their roles) and « Google Drive » for its lead and the
 * administrators only, the rule `canConfigure` carries and the server
 * enforces on save. The bar is drawn from the second subject on: a selector
 * with one choice helps nobody choose.
 *
 * The space form used to be a modal of the spaces list; the list now links
 * here. One form model for both form sections, saved through the route the
 * modal used, see `useSpaceSettingsForm`.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { FolderOpen, PanelsTopLeft, Save, Users } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import CustomerSpaceFormFields from "../spaces/components/CustomerSpaceFormFields.vue";
import SpaceDriveSettings from "./SpaceDriveSettings.vue";
import { useSpaceSettingsForm } from "./useSpaceSettingsForm.js";

const props = defineProps({
    /**
     * The space form and its options (`space`, `customers`, `users`,
     * `statuses`, `roles`, `timezones`, `canCreateCustomer`, `canConfigure`,
     * `updatePath`); null without the right to edit the space.
     */
    spaceSettings: { type: Object, default: null },
    /** True for the lead, the administrator and the developer: the team and the Drive. */
    canConfigure: { type: Boolean, default: false },
    settingsPath: { type: String, default: "" },
    agencyFolderId: { type: String, default: null },
    agencyFolderPath: { type: String, default: null },
    serviceAccountEmail: { type: String, default: null },
});

const emit = defineEmits(["locked-changed", "folder-changed", "agency-folder-changed"]);

const { t } = useI18n();

const SECTIONS = [
    { key: "general", labelKey: "suite.studio.spaces.settings.section_general", icon: PanelsTopLeft },
    { key: "team", labelKey: "suite.studio.spaces.settings.section_team", icon: Users },
    { key: "drive", labelKey: "suite.studio.spaces.settings.section_drive", icon: FolderOpen },
];

const sections = computed(() =>
    SECTIONS.filter((entry) => {
        if ("general" === entry.key) return !!props.spaceSettings;
        if ("team" === entry.key) return !!props.spaceSettings && props.canConfigure;

        return props.canConfigure;
    }),
);

const section = ref(sections.value[0]?.key ?? "general");

const editable = !!props.spaceSettings;
const { form, customerOptions, errors, loading, submit } = editable
    ? useSpaceSettingsForm(props.spaceSettings)
    : { form: ref(null), customerOptions: ref([]), errors: ref({}), loading: ref(false), submit: () => {} };
</script>

<template>
    <section class="relative aurora-stack">
        <div
            v-if="sections.length > 1"
            class="inline-flex max-w-full gap-1 overflow-x-auto rounded-lg border border-line bg-surface-2 p-1"
            role="group"
        >
            <AppTab
                v-for="entry in sections"
                :key="entry.key"
                size="sm"
                :active="section === entry.key"
                :aria-pressed="section === entry.key ? 'true' : 'false'"
                active-class="bg-surface text-primary shadow-sm"
                inactive-class="text-secondary hover:text-primary"
                class="whitespace-nowrap"
                v-on:click="section = entry.key"
            >
                <component :is="entry.icon" class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t(entry.labelKey) }}
            </AppTab>
        </div>

        <!-- The two form sections share one model and one save: each says
             what it holds, and its button saves the whole space. -->
        <form
            v-if="editable && ('general' === section || 'team' === section)"
            class="aurora-card flex max-w-3xl flex-col gap-4 p-3 sm:p-5"
            v-on:submit.prevent="submit"
        >
            <p class="m-0 text-xs text-muted">
                {{ t(`suite.studio.spaces.settings.${section}_intro`) }}
            </p>
            <CustomerSpaceFormFields
                v-model="form"
                :errors="errors"
                :with-identity="'general' === section"
                :with-team="'team' === section"
                :can-edit-team="canConfigure"
                :can-create-customer="spaceSettings.canCreateCustomer"
                :customer-options="customerOptions"
                :users="spaceSettings.users ?? []"
                :statuses="spaceSettings.statuses ?? []"
                :roles="spaceSettings.roles ?? []"
                :timezones="spaceSettings.timezones ?? []"
            />
            <div class="flex justify-end">
                <AppButton
                    type="submit"
                    variant="primary"
                    size="md"
                    class="w-full sm:w-auto"
                    :loading="loading"
                >
                    <Save class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.save") }}
                </AppButton>
            </div>
        </form>

        <!-- The lock goes up from here to the Drive view: the screen that sets
             it and the one that suffers it must agree without a reload. -->
        <SpaceDriveSettings
            v-if="'drive' === section && canConfigure"
            :settings-path="settingsPath"
            :agency-folder-id="agencyFolderId"
            :agency-folder-path="agencyFolderPath"
            :service-account-email="serviceAccountEmail"
            v-on:agency-folder-changed="emit('agency-folder-changed', $event)"
            v-on:locked-changed="emit('locked-changed', $event)"
            v-on:folder-changed="emit('folder-changed', $event)"
        />
    </section>
</template>
