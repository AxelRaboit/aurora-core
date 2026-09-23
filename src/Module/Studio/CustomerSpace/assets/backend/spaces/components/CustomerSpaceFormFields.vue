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
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const form = computed(() => props.modelValue);

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}

/**
 * La valeur du selecteur qui ne designe personne mais ouvre quelqu'un.
 *
 * Une sentinelle plutot qu'une case a cocher a cote : le champ repond a une
 * seule question - pour qui est cet espace - et deux commandes pour un seul
 * creneau font hesiter sur celle qui compte.
 */
const NEW_PROSPECT = "__prospect__";

/**
 * Le mode choisi, tenu ici et pas deduit du formulaire.
 *
 * Le deduire d'un `prospectName` non vide obligerait a y ecrire quelque chose
 * pour que les champs restent ouverts - et ce quelque chose partirait au
 * serveur. L'ouverture du panneau est un etat de l'ecran, il reste dans
 * l'ecran.
 */
const prospectMode = ref(false);

const pickingProspect = computed(
    () => prospectMode.value || "" !== (form.value.prospectName ?? ""),
);

// La fenetre reste montee entre deux ouvertures : sans ca, ouvrir un prospect
// puis modifier un espace existant rouvrirait les deux champs sur une fiche
// qui a deja sa societe.
watch(
    () => form.value.customerId,
    (customerId) => {
        if (customerId) prospectMode.value = false;
    },
);

const customerChoices = computed(() => [
    { value: NEW_PROSPECT, label: t("backend.studio.spaces.customer_new_prospect") },
    ...props.customerOptions,
]);

/**
 * Choisir l'un efface l'autre.
 *
 * Sans ca, un formulaire ou l'on a tape un nom de prospect puis choisi une
 * societe existante partirait avec les deux, et le serveur devrait deviner
 * lequel l'emporte.
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
    <div class="space-y-6">
        <section class="space-y-4">
            <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.spaces.group_identity") }}
            </h3>

            <AppInput
                :model-value="form.name"
                :label="t('backend.studio.spaces.name')"
                :placeholder="t('backend.studio.spaces.name_placeholder')"
                :hint="t('backend.studio.spaces.name_hint')"
                :error="errors.name"
                required
                v-on:update:model-value="set('name', $event)"
            />

            <!-- Une societe connue, ou un prospect ouvert dans la foulee.
                 L'option est dans la meme liste plutot qu'a cote, parce que
                 c'est une seule question - pour qui est cet espace - et que
                 deux champs pour un seul creneau font hesiter. -->
            <AppSelect
                :model-value="pickingProspect ? NEW_PROSPECT : String(form.customerId ?? '')"
                :label="t('backend.studio.spaces.customer')"
                :placeholder="t('backend.studio.spaces.customer_none')"
                :options="customerChoices"
                :hint="t('backend.studio.spaces.customer_hint')"
                :error="errors.customerId"
                required
                v-on:update:model-value="chooseCustomer"
            />

            <div v-if="pickingProspect" class="grid gap-4 sm:grid-cols-2">
                <AppInput
                    :model-value="form.prospectName"
                    :label="t('backend.studio.spaces.prospect_name')"
                    :placeholder="t('backend.studio.spaces.prospect_name_placeholder')"
                    :error="errors.prospectName"
                    required
                    v-on:update:model-value="set('prospectName', $event)"
                />
                <AppInput
                    :model-value="form.prospectEmail"
                    type="email"
                    :label="t('backend.studio.spaces.prospect_email')"
                    :placeholder="t('shared.placeholders.email')"
                    :hint="t('backend.studio.spaces.prospect_email_hint')"
                    :error="errors.prospectEmail"
                    v-on:update:model-value="set('prospectEmail', $event)"
                />
            </div>

            <AppTextarea
                :model-value="form.description"
                :label="t('backend.studio.spaces.description')"
                :placeholder="t('backend.studio.spaces.description_placeholder')"
                :error="errors.description"
                :rows="3"
                v-on:update:model-value="set('description', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppSelect
                    :model-value="form.status"
                    :label="t('backend.studio.spaces.status')"
                    :options="statusOptions"
                    :hint="t('backend.studio.spaces.status_hint')"
                    :error="errors.status"
                    v-on:update:model-value="set('status', $event)"
                />
                <AppSelect
                    :model-value="form.timezone"
                    :label="t('backend.studio.spaces.timezone')"
                    :options="timezoneOptions"
                    :hint="t('backend.studio.spaces.timezone_hint')"
                    :error="errors.timezone"
                    v-on:update:model-value="set('timezone', $event)"
                />
            </div>

            <!-- Swatches and not a select: the thing being chosen is the colour
                 itself, so showing it beats naming it. These are the same eight
                 tokens the charts and the calendar draw with, so what is picked
                 here is literally what will appear beside this space's dates. -->
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-medium text-primary">
                    {{ t("backend.studio.spaces.colour") }}
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
                        :aria-label="t('backend.studio.spaces.colour')"
                        :aria-pressed="Number(form.colourSlot) === slot"
                        v-on:click="set('colourSlot', slot)"
                    />
                </div>
                <p class="text-xs text-muted">
                    {{
                        form.colourSlot === ""
                            ? t("backend.studio.spaces.colour_auto")
                            : t("backend.studio.spaces.colour_hint")
                    }}
                </p>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h3 class="text-xs font-medium uppercase tracking-wider text-muted">
                    {{ t("backend.studio.spaces.group_team") }}
                </h3>
                <p class="mt-1 text-xs text-muted">
                    {{ t("backend.studio.spaces.members_hint") }}
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
                        :model-value="member.role"
                        :options="roleOptions"
                        class="w-40"
                        v-on:update:model-value="setRole(member.userId, $event)"
                    />
                    <AppButton
                        variant="icon"
                        size="sm"
                        :aria-label="t('backend.studio.spaces.member_remove')"
                        class="p-1.5 text-muted hover:text-primary"
                        v-on:click="removeMember(member.userId)"
                    >
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                    </AppButton>
                </li>
            </ul>

            <p v-else class="text-sm text-muted">
                {{ t("backend.studio.spaces.no_members") }}
            </p>

            <AppSelect
                v-if="addOptions.length"
                v-model="memberToAdd"
                :label="t('backend.studio.spaces.member_add')"
                :placeholder="t('backend.studio.spaces.member_add')"
                :options="addOptions"
            />
            <p v-else-if="form.members.length" class="text-xs text-muted">
                {{ t("backend.studio.spaces.member_none_left") }}
            </p>
        </section>
    </div>
</template>
