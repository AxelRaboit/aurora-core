<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { CheckCircle2, ExternalLink, PlugZap, Save, Unplug, X, XCircle } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppHelp from "@/shared/components/overlay/AppHelp.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateTimeNumeric } = useDateFormat();

/**
 * The storage tab of the settings screen.
 *
 * It draws itself rather than declaring fields, because two of the values
 * behind it are credentials that have no business being echoed into a page,
 * and one is a record of when a backend last answered rather than a value
 * anybody retypes.
 *
 * Most of what follows is instructions. Aurora is delivered to clients and the
 * bucket is theirs to open, so someone who has never heard of R2 has to be
 * able to finish this on their own.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/configuration/storage";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const testing = ref(false);

const activeDisk = ref("local");

/**
 * Ce que pèse chaque emplacement.
 *
 * Les deux sont toujours montrés, même à zéro : ce qu'on vient vérifier après
 * une bascule, c'est justement qu'il ne reste plus rien de l'autre côté, et un
 * écran qui masque l'emplacement vide ne sait pas le dire.
 */
const usage = ref({ local: { count: 0, bytes: 0 }, r2: { count: 0, bytes: 0 } });

/** Des unités qu'on lit, pas des octets qu'on compte. */
function weigh(bytes) {
    if (!bytes) return "0 ko";

    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} Go`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} Mo`;

    return `${Math.max(1, Math.round(bytes / 1024))} ko`;
}
const deliveryMode = ref("proxy");
const endpoint = ref("");
const bucket = ref("");
const publicBaseUrl = ref("");
const hasAccessKeyId = ref(false);
const hasSecretAccessKey = ref(false);
const isComplete = ref(false);
const fromEnvironment = ref(false);
const verifiedAt = ref(null);
const disconnecting = ref(false);
const confirmingDisconnect = ref(false);

// Never pre-filled from the server: write-only from here on. Left empty, the
// save keeps whatever is stored.
const accessKeyId = ref("");
const secretAccessKey = ref("");

const probe = ref(null);

/**
 * Switching needs a backend that has actually answered. The server refuses
 * without it; disabling the option here means the reason is visible before
 * the click rather than reported after it.
 */
const canSwitchToR2 = computed(() => null !== verifiedAt.value);


// R2 is offered only once a probe has passed. Withheld rather than shown
// disabled: AppSelect has no disabled state, and an option that cannot be
// chosen is worse than an option that is not there when the hint underneath
// says why.
const diskOptions = computed(() => {
    const options = [{ value: "local", label: t("backend.settings.storage.disk_local") }];
    if (canSwitchToR2.value) {
        options.push({ value: "r2", label: t("backend.settings.storage.disk_r2") });
    }

    return options;
});

const deliveryOptions = computed(() => [
    { value: "proxy", label: t("backend.settings.storage.delivery_proxy") },
    { value: "presigned", label: t("backend.settings.storage.delivery_presigned") },
    { value: "public_url", label: t("backend.settings.storage.delivery_public_url") },
]);

const deliveryHint = computed(
    () => t(`backend.settings.storage.delivery_${deliveryMode.value}_hint`),
);

const verifiedOn = computed(() => {
    if (!verifiedAt.value) return "";
    const date = new Date(verifiedAt.value);

    return Number.isNaN(date.getTime()) ? verifiedAt.value : formatDateTimeNumeric(date.toISOString());
});
/**
 * The one line that answers "is this thing on?" without reading the form.
 *
 * Four fields filled and a date somewhere down the page do not answer it: the
 * fields show dots for stored keys, and the date sits next to a button. The
 * banner states the connection, which bucket, and where new files go - the
 * three things one comes to this screen to check.
 */
const connection = computed(() => {
    if (!isComplete.value) {
        return { variant: "info", key: "banner_none" };
    }

    if (null === verifiedAt.value) {
        return { variant: "warning", key: "banner_untested" };
    }

    return { variant: "success", key: "banner_connected" };
});

// Built outside the `t()` call: a ternary between its parentheses reads as a
// translation key to the guard that checks every key resolves, and the string
// "local" is not one.
const activeDiskLabel = computed(() =>
    t(`backend.settings.storage.disk_${activeDisk.value}`),
);

const connectionMessage = computed(() =>
    t(`backend.settings.storage.${connection.value.key}`, {
        bucket: bucket.value,
        date: verifiedOn.value,
        target: activeDiskLabel.value,
    }),
);

/**
 * Nothing to disconnect from until something is stored, and nothing this
 * screen can do about a configuration the server's environment supplies:
 * those variables are unset where they are set, not here. Offering the button
 * anyway would be offering a button that cannot work.
 */
const canDisconnect = computed(
    () => !fromEnvironment.value && (isComplete.value || null !== verifiedAt.value),
);


function apply(state) {
    if (!state) return;
    activeDisk.value = state.activeDisk ?? "local";
    deliveryMode.value = state.deliveryMode ?? "proxy";
    endpoint.value = state.endpoint ?? "";
    bucket.value = state.bucket ?? "";
    publicBaseUrl.value = state.publicBaseUrl ?? "";
    hasAccessKeyId.value = true === state.hasAccessKeyId;
    hasSecretAccessKey.value = true === state.hasSecretAccessKey;
    isComplete.value = true === state.isComplete;
    fromEnvironment.value = true === state.fromEnvironment;
    verifiedAt.value = state.verifiedAt ?? null;
    usage.value = state.usage ?? usage.value;
    accessKeyId.value = "";
    secretAccessKey.value = "";
}

function payload() {
    const body = {
        activeDisk: activeDisk.value,
        deliveryMode: deliveryMode.value,
        endpoint: endpoint.value.trim(),
        bucket: bucket.value.trim(),
        publicBaseUrl: publicBaseUrl.value.trim(),
    };
    // Sent only when something was typed, so saving without touching a field
    // keeps the credential already stored.
    if ("" !== accessKeyId.value.trim()) body.accessKeyId = accessKeyId.value.trim();
    if ("" !== secretAccessKey.value.trim()) body.secretAccessKey = secretAccessKey.value.trim();

    return body;
}

onMounted(async () => {
    try {
        apply(await request(SETTINGS_PATH, null, { method: HttpMethod.Get, noGuard: true }));
    } finally {
        loading.value = false;
    }
});

/**
 * Saves, and returns the state on success or null when the server refused.
 *
 * Reading `success` rather than the mere presence of a response matters,
 * because a refusal is an object too - treating it as state used to announce
 * a save that had not happened.
 *
 * A refusal only carries a state when something really was written, and the
 * screen refreshes only then. That distinction is what lets someone who
 * mistyped one key fix it without pasting both again: the key fields are
 * write-only, so refreshing the form empties them.
 */
async function persist() {
    const response = await request(SETTINGS_PATH, payload(), { noGuard: true });
    if (!response) return null;

    if (false === response.success) {
        toast.error(t(response.error ?? "shared.common.error"));
        if (response.state) apply(response.state);

        return null;
    }

    apply(response);

    return response;
}

async function save() {
    saving.value = true;
    try {
        // Nothing on this screen changes visibly when a save works: the keys
        // come back as "a value is stored", not as themselves. Without a word
        // said, pressing the button looks like pressing nothing.
        if (await persist()) toast.success(t("backend.settings.saved"));
    } finally {
        saving.value = false;
    }
}

/**
 * Saves first, then probes. Testing what is on screen rather than what was
 * stored last time is the only behaviour that makes sense to someone who has
 * just pasted a key.
 */
async function test() {
    testing.value = true;
    probe.value = null;
    try {
        // A configuration the server would not even store is not worth
        // sending to Cloudflare: the refusal already says what is wrong, and
        // probing it would answer with something less clear.
        if (!(await persist())) return;

        const result = await request(`${SETTINGS_PATH}/test`, { disk: "r2" }, { noGuard: true });
        if (!result) return;

        probe.value = result;
        if (result.state) apply(result.state);
    } finally {
        testing.value = false;
    }
}

/**
 * Forgets the remote backend, after asking - it erases two credentials that
 * Cloudflare only ever showed once, so getting them back means creating a new
 * token, not remembering.
 *
 * The server refuses while documents still live there and says how many. That
 * check belongs there rather than here: the screen would be asking a question
 * whose answer can change between the asking and the click.
 */
async function disconnect() {
    disconnecting.value = true;
    try {
        const response = await request(`${SETTINGS_PATH}/disconnect`, {}, { noGuard: true });
        if (!response) return;

        if (false === response.success) {
            toast.error(
                t(response.error ?? "shared.common.error", { count: response.remaining ?? 0 }),
            );

            return;
        }

        probe.value = null;
        apply(response);
        confirmingDisconnect.value = false;
        toast.success(t("backend.settings.storage.disconnected"));
    } finally {
        disconnecting.value = false;
    }
}

defineExpose({ save, apply, canSwitchToR2 });
</script>

<template>
    <div class="relative space-y-6">
        <AppLoader :active="loading" />

        <!-- First thing on the screen, because it answers the question that
             brought most people here. -->
        <AppMessage v-if="!loading" :variant="connection.variant">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>
                    {{ connectionMessage }}
                    <template v-if="fromEnvironment">
                        {{ t("backend.settings.storage.banner_from_environment") }}
                    </template>
                </span>
                <AppHelp topic="storage.connection" />
            </div>
        </AppMessage>

        <section class="space-y-2">
            <p class="text-sm text-secondary">{{ t("backend.settings.storage.intro") }}</p>
            <p class="text-sm text-muted">{{ t("backend.settings.storage.when_useful") }}</p>
        </section>

        <!-- Ce que ça pèse, et de quel côté. L'écran disait où les fichiers
             vont ; il ne disait pas où ils sont, ce qui est la seule chose
             qu'on vient vérifier après une bascule. -->
        <section v-if="!loading" class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.settings.storage.usage_title") }}</h3>
            <div class="grid gap-2 sm:grid-cols-2">
                <div
                    v-for="disk in ['local', 'r2']"
                    :key="disk"
                    class="rounded-lg border border-line bg-surface-2/40 px-3 py-2"
                    :class="activeDisk === disk ? 'border-accent/60' : ''"
                >
                    <p class="flex items-center gap-2 text-xs uppercase tracking-wide text-muted">
                        {{ t(`backend.settings.storage.disk_${disk}`) }}
                        <span v-if="activeDisk === disk" class="rounded-full bg-accent/15 px-1.5 py-0.5 text-[0.65rem] normal-case text-accent">
                            {{ t("backend.settings.storage.usage_active") }}
                        </span>
                    </p>
                    <p class="mt-0.5 text-sm text-primary tabular-nums">
                        {{ weigh(usage[disk]?.bytes ?? 0) }}
                    </p>
                    <p class="text-xs text-muted">
                        {{ t("backend.settings.storage.usage_files", { count: usage[disk]?.count ?? 0 }) }}
                    </p>
                </div>
            </div>
            <p class="text-xs text-muted">{{ t("backend.settings.storage.usage_hint") }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.settings.storage.how_title") }}</h3>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-secondary">
                <li>{{ t("backend.settings.storage.how_step_bucket") }}</li>
                <li>{{ t("backend.settings.storage.how_step_token") }}</li>
                <li>{{ t("backend.settings.storage.how_step_copy") }}</li>
            </ol>
            <a
                href="https://developers.cloudflare.com/r2/api/tokens/"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-sm text-accent hover:underline"
            >
                {{ t("backend.settings.storage.how_link") }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
        </section>

        <section class="space-y-3">
            <AppInput
                v-model="endpoint"
                :label="t('backend.settings.storage.endpoint_label')"
                :hint="t('backend.settings.storage.endpoint_hint')"
                placeholder="https://<compte>.r2.cloudflarestorage.com"
            />
            <AppInput
                v-model="bucket"
                :label="t('backend.settings.storage.bucket_label')"
                :hint="t('backend.settings.storage.bucket_hint')"
                placeholder="mon-projet-fichiers"
            />
            <AppInput
                v-model="accessKeyId"
                type="password"
                :toggleable="true"
                :label="t('backend.settings.storage.access_key_label')"
                :hint="hasAccessKeyId ? t('backend.settings.storage.key_stored') : t('backend.settings.storage.key_hint')"
                :placeholder="hasAccessKeyId ? '••••••••••••••••' : ''"
            />
            <AppInput
                v-model="secretAccessKey"
                type="password"
                :toggleable="true"
                :label="t('backend.settings.storage.secret_key_label')"
                :hint="hasSecretAccessKey ? t('backend.settings.storage.key_stored') : t('backend.settings.storage.key_hint')"
                :placeholder="hasSecretAccessKey ? '••••••••••••••••' : ''"
            />
            <AppInput
                v-model="publicBaseUrl"
                :label="t('backend.settings.storage.public_url_label')"
                :hint="t('backend.settings.storage.public_url_hint')"
                placeholder="https://files.exemple.fr"
            />
        </section>

        <section class="space-y-3 rounded-lg border border-line bg-surface-2 p-4">
            <p class="text-sm text-secondary">{{ t("backend.settings.storage.test_intro") }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <AppButton variant="secondary" size="md" :loading="testing" v-on:click="test">
                    <PlugZap class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.settings.storage.test_button") }}
                </AppButton>
                <span v-if="verifiedAt" class="text-xs text-muted">
                    {{ t("backend.settings.storage.test_passed", { date: verifiedOn }) }}
                </span>
                <span v-else class="text-xs text-muted">{{ t("backend.settings.storage.test_never") }}</span>
            </div>

            <ul v-if="probe" class="space-y-1 text-sm">
                <li v-for="step in probe.steps" :key="step.key" class="flex items-start gap-2">
                    <CheckCircle2 v-if="step.ok" class="mt-0.5 w-3.5 h-3.5 shrink-0 text-success" :stroke-width="2" />
                    <XCircle v-else class="mt-0.5 w-3.5 h-3.5 shrink-0 text-danger" :stroke-width="2" />
                    <span :class="step.ok ? 'text-secondary' : 'text-danger'">
                        {{ t(`backend.settings.storage.probe.${step.key}`) }}
                        <span v-if="step.error" class="text-muted">- {{ step.error }}</span>
                    </span>
                </li>
            </ul>

            <p v-if="probe && probe.hint" class="text-sm text-warning">{{ t(probe.hint) }}</p>
        </section>

        <section class="space-y-3">
            <AppSelect
                v-model="deliveryMode"
                :options="deliveryOptions"
                :label="t('backend.settings.storage.delivery_title')"
                :hint="deliveryHint"
                help="storage.delivery_mode"
            />
            <p class="text-xs text-muted">{{ t("backend.settings.storage.delivery_hint") }}</p>

            <AppSelect
                v-model="activeDisk"
                :options="diskOptions"
                :label="t('backend.settings.storage.active_disk_label')"
                :hint="canSwitchToR2 ? t('backend.settings.storage.switch_hint') : t('backend.settings.storage.switch_blocked')"
            />
        </section>

        <div class="flex flex-col items-stretch justify-end gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <!-- Far from the save button, and only when there is something to
                 disconnect from. It erases both keys, which nothing else on
                 this screen can do. -->
            <AppButton
                v-if="canDisconnect"
                variant="danger"
                size="md"
                class="mr-auto"
                v-on:click="confirmingDisconnect = true"
            >
                <Unplug class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t("backend.settings.storage.disconnect_button") }}
            </AppButton>
            <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>

        <AppModal
            :show="confirmingDisconnect"
            max-width="sm"
            :closeable="false"
            :title="t('backend.settings.storage.disconnect_button')"
            :icon="Unplug"
            v-on:close="confirmingDisconnect = false"
        >
            <p class="text-sm text-primary">{{ t("backend.settings.storage.disconnect_confirm") }}</p>
            <p class="text-sm text-secondary">{{ t("backend.settings.storage.disconnect_warning") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="confirmingDisconnect = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="disconnecting" v-on:click="disconnect">
                        <Unplug class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.settings.storage.disconnect_button") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
