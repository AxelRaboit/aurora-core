<script setup>
/**
 * Everything a space is, as one component.
 *
 * Create and edit ask for exactly the same fields, so they share these rather
 * than each carrying their own copy - two copies of a form drift the first time
 * a field is added to only one of them.
 *
 * Two groups and not more: what the space is, and who is on it. The legal
 * identity of the company is deliberately absent - it lives on the customer
 * record, and asking for it twice is how two screens end up disagreeing about
 * the same company.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { X } from "lucide-vue-next";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import { COLOUR_SLOTS } from "../composables/useCustomerSpacesForm.js";

const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    customerOptions: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    /** When the space mails its client; empty where the choice is not offered. */
    clientDigestModes: { type: Array, default: () => [] },
    /** The right to share: the choice is read by the others, not changed. */
    canChooseClientDigest: { type: Boolean, default: false },
    /**
     * Whether the reader can change the team and the roles. Reserved to the
     * space's lead: the others see the team without being able to touch it,
     * the server refusing anyway.
     */
    canEditTeam: { type: Boolean, default: true },
    /**
     * Whether "nouveau prospect" is offered. It creates a customer sheet, so
     * it requires the right to create one; the server refuses otherwise.
     */
    canCreateCustomer: { type: Boolean, default: false },
    /**
     * Which of the two groups to draw. The Settings tab of a space draws them
     * as two sections, and the team only for its lead; the creation modal
     * draws both.
     */
    withIdentity: { type: Boolean, default: true },
    withTeam: { type: Boolean, default: true },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const form = computed(() => props.modelValue);

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}

/**
 * The selector value that names nobody but opens someone.
 *
 * A sentinel rather than a checkbox next to it: the field answers a single
 * question - who this space is for - and two controls for a single slot make
 * people hesitate over which one counts.
 */
const NEW_PROSPECT = "__prospect__";

/**
 * The chosen mode, held here and not derived from the form.
 *
 * Deriving it from a non-empty `prospectName` would force writing something
 * there for the fields to stay open - and that something would go to the
 * server. The panel being open is a state of the screen, it stays in the
 * screen.
 */
const prospectMode = ref(false);

const pickingProspect = computed(
    () => prospectMode.value || "" !== (form.value.prospectName ?? ""),
);

// The dialog stays mounted between two openings: without this, opening a
// prospect then editing an existing space would reopen the two fields on a
// record that already has its company.
watch(
    () => form.value.customerId,
    (customerId) => {
        if (customerId) prospectMode.value = false;
    },
);

const customerChoices = computed(() => [
    ...(props.canCreateCustomer
        ? [{ value: NEW_PROSPECT, label: t("suite.studio.spaces.customer_new_prospect") }]
        : []),
    ...props.customerOptions,
]);

/**
 * Choosing one clears the other.
 *
 * Without this, a form where a prospect name was typed and then an existing
 * company chosen would go out with both, and the server would have to guess
 * which one wins.
 */
function chooseCustomer(value) {
    if (NEW_PROSPECT === value) {
        prospectMode.value = true;
        emit("update:modelValue", { ...props.modelValue, customerId: "" });

        return;
    }

    prospectMode.value = false;
    emit("update:modelValue", {
        ...props.modelValue,
        customerId: value,
        prospectName: "",
        prospectEmail: "",
    });
}

const clientDigestOptions = computed(() =>
    props.clientDigestModes.map((mode) => ({ value: mode.value, label: t(mode.labelKey) })),
);

const statusOptions = computed(() =>
    props.statuses.map((status) => ({
        value: status.value,
        label: t(status.labelKey),
    })),
);

const roleOptions = computed(() =>
    props.roles.map((role) => ({
        value: role.value,
        label: t(role.labelKey),
    })),
);

const timezoneOptions = computed(() =>
    props.timezones.map((zone) => ({
        value: zone,
        label: zone.replace(/_/g, " "),
    })),
);

const userById = computed(
    () => new Map(props.users.map((user) => [user.id, user])),
);

function roleLabel(value) {
    return roleOptions.value.find((option) => option.value === value)?.label ?? value;
}

function userLabel(userId) {
    const user = userById.value.get(userId);

    if (!user) return String(userId);

    return user.email ? `${user.name} (${user.email})` : user.name;
}

/** Accounts not already on the space, so the picker cannot add a duplicate. */
const availableUsers = computed(() => {
    const taken = new Set(form.value.members.map((member) => member.userId));

    return props.users.filter((user) => !taken.has(user.id));
});

const memberToAdd = computed({
    get: () => "",
    set: (value) => {
        const userId = Number(value);

        if (!Number.isInteger(userId) || userId <= 0) return;

        set("members", [
            ...form.value.members,
            { userId, role: props.roles[0]?.value ?? "member" },
        ]);
    },
});

const addOptions = computed(() =>
    availableUsers.value.map((user) => ({
        value: String(user.id),
        label: user.email ? `${user.name} (${user.email})` : user.name,
    })),
);

function setRole(userId, role) {
    set(
        "members",
        form.value.members.map((member) =>
            member.userId === userId ? { ...member, role } : member,
        ),
    );
}

function removeMember(userId) {
    set(
        "members",
        form.value.members.filter((member) => member.userId !== userId),
    );
}
</script>

<template>
    <div class="space-y-5">
        <section v-if="withIdentity" class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("suite.studio.spaces.group_identity") }}
            </h3>

            <AppInput
                :model-value="form.name"
                :label="t('suite.studio.spaces.name')"
                :placeholder="t('suite.studio.spaces.name_placeholder')"
                :hint="t('suite.studio.spaces.name_hint')"
                :error="errors.name"
                required
                v-on:update:model-value="set('name', $event)"
            />

            <!-- A known company, or a prospect opened on the spot. The option
                 is in the same list rather than next to it, because it is a
                 single question - who this space is for - and two fields for
                 a single slot make people hesitate. -->
            <AppSelect
                :model-value="pickingProspect ? NEW_PROSPECT : String(form.customerId ?? '')"
                :label="t('suite.studio.spaces.customer')"
                :placeholder="t('suite.studio.spaces.customer_none')"
                :options="customerChoices"
                :hint="t('suite.studio.spaces.customer_hint')"
                :error="errors.customerId"
                required
                v-on:update:model-value="chooseCustomer"
            />

            <div v-if="pickingProspect" class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.prospectName"
                    :label="t('suite.studio.spaces.prospect_name')"
                    :placeholder="t('suite.studio.spaces.prospect_name_placeholder')"
                    :error="errors.prospectName"
                    required
                    v-on:update:model-value="set('prospectName', $event)"
                />
                <AppInput
                    :model-value="form.prospectEmail"
                    type="email"
                    :label="t('suite.studio.spaces.prospect_email')"
                    :placeholder="t('shared.placeholders.email')"
                    :hint="t('suite.studio.spaces.prospect_email_hint')"
                    :error="errors.prospectEmail"
                    v-on:update:model-value="set('prospectEmail', $event)"
                />
            </div>

            <AppTextarea
                :model-value="form.description"
                :label="t('suite.studio.spaces.description')"
                :placeholder="t('suite.studio.spaces.description_placeholder')"
                :error="errors.description"
                :rows="3"
                v-on:update:model-value="set('description', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppSelect
                    :model-value="form.status"
                    :label="t('suite.studio.spaces.status')"
                    :options="statusOptions"
                    :hint="t('suite.studio.spaces.status_hint')"
                    :error="errors.status"
                    v-on:update:model-value="set('status', $event)"
                />
                <AppSelect
                    :model-value="form.timezone"
                    :label="t('suite.studio.spaces.timezone')"
                    :options="timezoneOptions"
                    :hint="t('suite.studio.spaces.timezone_hint')"
                    :error="errors.timezone"
                    v-on:update:model-value="set('timezone', $event)"
                />
            </div>

            <AppSelect
                v-if="clientDigestOptions.length"
                :model-value="form.clientDigest ?? 'off'"
                :label="t('suite.studio.spaces.client_digest.label')"
                :options="clientDigestOptions"
                :hint="t('suite.studio.spaces.client_digest.hint')"
                :error="errors.clientDigest"
                :disabled="!canChooseClientDigest"
                v-on:update:model-value="set('clientDigest', $event)"
            />

            <!-- Swatches and not a select: the thing being chosen is the colour
                 itself, so showing it beats naming it. These are the same eight
                 tokens the charts and the calendar draw with, so what is picked
                 here is literally what will appear beside this space's dates. -->
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-medium text-primary">
                    {{ t("suite.studio.spaces.colour") }}
                </span>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="slot in COLOUR_SLOTS"
                        :key="slot"
                        type="button"
                        class="w-7 h-7 rounded-lg border-2 transition-transform cursor-pointer hover:scale-110"
                        :class="
                            Number(form.colourSlot) === slot
                                ? 'border-primary scale-110'
                                : 'border-transparent'
                        "
                        :style="{ backgroundColor: `var(--chart-cat-${slot})` }"
                        :aria-label="t('suite.studio.spaces.colour')"
                        :aria-pressed="Number(form.colourSlot) === slot"
                        v-on:click="set('colourSlot', slot)"
                    />
                </div>
                <p class="text-xs text-muted">
                    {{
                        form.colourSlot === ""
                            ? t("suite.studio.spaces.colour_auto")
                            : t("suite.studio.spaces.colour_hint")
                    }}
                </p>
            </div>
        </section>

        <section v-if="withTeam" class="space-y-4">
            <div>
                <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                    {{ t("suite.studio.spaces.group_team") }}
                </h3>
                <p class="mt-1 text-xs text-muted">
                    {{ t("suite.studio.spaces.members_hint") }}
                </p>
            </div>

            <ul v-if="form.members.length" class="space-y-2">
                <li
                    v-for="member in form.members"
                    :key="member.userId"
                    class="flex flex-wrap items-center gap-2 rounded-lg border border-line bg-surface-2/40 px-3 py-2"
                >
                    <span class="flex-1 min-w-0 truncate text-sm text-primary">
                        {{ userLabel(member.userId) }}
                    </span>
                    <AppSelect
                        v-if="canEditTeam"
                        :model-value="member.role"
                        :options="roleOptions"
                        class="w-40"
                        v-on:update:model-value="setRole(member.userId, $event)"
                    />
                    <span v-else class="text-xs text-muted">{{ roleLabel(member.role) }}</span>
                    <AppButton
                        v-if="canEditTeam"
                        variant="icon"
                        size="sm"
                        :aria-label="t('suite.studio.spaces.member_remove')"
                        class="p-1.5 text-muted hover:text-primary"
                        v-on:click="removeMember(member.userId)"
                    >
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                    </AppButton>
                </li>
            </ul>

            <p v-else class="text-sm text-muted">
                {{ t("suite.studio.spaces.no_members") }}
            </p>

            <p v-if="errors.members" class="text-xs text-red-500">{{ t(errors.members, errors.members) }}</p>

            <AppSelect
                v-if="canEditTeam && addOptions.length"
                v-model="memberToAdd"
                :label="t('suite.studio.spaces.member_add')"
                :placeholder="t('suite.studio.spaces.member_add')"
                :options="addOptions"
            />
            <p v-else-if="canEditTeam && form.members.length" class="text-xs text-muted">
                {{ t("suite.studio.spaces.member_none_left") }}
            </p>
        </section>
    </div>
</template>
