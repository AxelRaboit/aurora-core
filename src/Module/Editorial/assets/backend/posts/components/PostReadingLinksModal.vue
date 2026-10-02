<script setup>
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, Link2, Plus, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";

/**
 * The addresses that open this publication outside the site, without an
 * account.
 *
 * The deck's share panel, for a publication: one link per recipient, each
 * with a label, an optional expiry and an optional password, and how often it
 * was opened. A link is never deleted, only revoked, and the revoked ones stay
 * in the list, faded - who could read it once is worth knowing afterwards.
 *
 * Fetched when it opens rather than handed to the editor up front: the links
 * change from elsewhere (a reader opening one bumps its count), and the editor
 * already carries enough.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    postId: { type: [Number, String], required: true },
    pathTemplate: { type: String, required: true },
});

defineEmits(["close"]);

const { t } = useI18n();
const { request } = useRequest();
const { formatDateShort } = useDateFormat();

const links = ref([]);
const readable = ref(true);
const withheld = ref([]);
const loading = ref(false);
const creating = ref(false);
const newLabel = ref("");
const expiresInDays = ref("");
const newPassword = ref("");
const copiedId = ref(null);

const base = () => props.pathTemplate.replace("__id__", String(props.postId));

const expiryOptions = [
    { value: "", label: t("backend.posts.reading.no_expiry") },
    { value: "7", label: t("backend.posts.reading.days", { count: 7 }) },
    { value: "30", label: t("backend.posts.reading.days", { count: 30 }) },
    { value: "90", label: t("backend.posts.reading.days", { count: 90 }) },
];

const isLive = (link) =>
    !link.revokedAt &&
    (!link.expiresAt || new Date(link.expiresAt) > new Date());

function apply(data) {
    if (!data?.success) return;
    links.value = data.links ?? [];
    readable.value = data.readable ?? true;
    withheld.value = data.withheldPictures ?? [];
}

async function load() {
    loading.value = true;
    try {
        apply(await request(base(), null, { method: HttpMethod.Get }));
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.show,
    (open) => {
        if (open) load();
    },
    { immediate: true },
);

async function createLink() {
    if (creating.value) return;

    creating.value = true;
    try {
        const data = await request(`${base()}/create`, {
            label: newLabel.value,
            expiresInDays: expiresInDays.value ? Number(expiresInDays.value) : null,
            password: newPassword.value,
        });

        if (!data?.success) return;

        apply(data);
        newLabel.value = "";
        // Cleared rather than kept: the field holds a secret, and a second
        // link made from this panel would otherwise inherit the first one's.
        newPassword.value = "";
        toast.success(t("backend.posts.reading.created"));
    } finally {
        creating.value = false;
    }
}

async function revoke(link) {
    const data = await request(`${base()}/${link.id}/revoke`);

    if (!data?.success) return;

    apply(data);
    toast.success(t("backend.posts.reading.revoked_toast"));
}

/**
 * Copy, with a fallback that is not a failure: the clipboard needs a secure
 * context, routinely absent on a local instance over plain http, and the
 * address shown in the row can then be selected by hand.
 */
async function copy(link) {
    try {
        await navigator.clipboard.writeText(link.url);
        toast.success(t("backend.posts.reading.copied"));
    } catch {
        toast.message(t("backend.posts.reading.copy_manually"));
    }

    copiedId.value = link.id;
    setTimeout(() => {
        if (copiedId.value === link.id) copiedId.value = null;
    }, 2000);
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :title="t('backend.posts.reading.title')"
        :icon="Link2"
        v-on:close="$emit('close')"
    >
        <div class="space-y-4">
            <p class="m-0 text-sm text-secondary">{{ t("backend.posts.reading.intro") }}</p>

            <!-- Before the form, like the deck's warning: they change what is
                 about to be sent, not what was sent. -->
            <div
                v-if="!readable"
                class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-300"
                role="status"
            >
                {{ t("backend.posts.reading.not_readable") }}
            </div>
            <div
                v-if="withheld.length"
                class="flex flex-col gap-1 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3"
                role="status"
            >
                <p class="m-0 text-sm font-medium text-amber-300">
                    {{ t("backend.posts.reading.withheld_title", withheld.length) }}
                </p>
                <p class="m-0 text-xs text-secondary">{{ t("backend.posts.reading.withheld_hint") }}</p>
                <p class="m-0 truncate text-xs text-muted">{{ withheld.map((picture) => picture.name).join(", ") }}</p>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <AppInput
                    v-model="newLabel"
                    class="min-w-48 flex-1"
                    :label="t('backend.posts.reading.label')"
                    :placeholder="t('backend.posts.reading.label_placeholder')"
                />
                <AppSelect
                    v-model="expiresInDays"
                    class="w-44"
                    :label="t('backend.posts.reading.expiry')"
                    :options="expiryOptions"
                />
                <AppButton variant="primary" :loading="creating" v-on:click="createLink">
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.posts.reading.create") }}
                </AppButton>
            </div>

            <AppInput
                v-model="newPassword"
                type="password"
                autocomplete="new-password"
                :label="t('backend.posts.reading.password')"
                :placeholder="t('backend.posts.reading.password_placeholder')"
                :hint="t('backend.posts.reading.password_hint')"
            />

            <p v-if="!loading && !links.length" class="m-0 text-sm text-muted">{{ t("backend.posts.reading.none") }}</p>

            <ul v-else class="m-0 flex list-none flex-col gap-2 p-0">
                <li
                    v-for="link in links"
                    :key="link.id"
                    class="rounded-lg border border-line p-3"
                    :class="isLive(link) ? '' : 'opacity-60'"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="min-w-0 text-sm font-medium text-primary">
                            {{ link.label || t("backend.posts.reading.untitled") }}
                        </span>
                        <span class="flex shrink-0 gap-1">
                            <AppIconButton :title="t('backend.posts.reading.copy')" v-on:click="copy(link)">
                                <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                v-if="isLive(link)"
                                :title="t('backend.posts.reading.revoke')"
                                v-on:click="revoke(link)"
                            >
                                <X class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>
                        </span>
                    </div>

                    <p class="m-0 mt-1 truncate font-mono text-xs text-muted">
                        {{ copiedId === link.id ? t("backend.posts.reading.copied") : link.url }}
                    </p>

                    <p class="m-0 mt-1 text-xs text-muted">
                        <span v-if="link.revokedAt">{{ t("backend.posts.reading.revoked") }}</span>
                        <span v-else-if="link.expiresAt">{{ t("backend.posts.reading.expires_on", { date: formatDateShort(link.expiresAt) }) }}</span>
                        <span v-else>{{ t("backend.posts.reading.no_expiry") }}</span>
                        <span v-if="link.locked"> · {{ t("backend.posts.reading.locked") }}</span>
                        <span v-if="link.lastUsedAt">
                            ·
                            {{ link.openCount > 1
                                ? t("backend.posts.reading.opened", { count: link.openCount })
                                : t("backend.posts.reading.opened_once") }}
                            · {{ t("backend.posts.reading.last_used", { date: formatDateShort(link.lastUsedAt) }) }}
                        </span>
                        <span v-else> · {{ t("backend.posts.reading.never_opened") }}</span>
                    </p>
                </li>
            </ul>
        </div>
    </AppModal>
</template>
