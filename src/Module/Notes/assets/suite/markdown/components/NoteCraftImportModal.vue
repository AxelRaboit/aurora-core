<script setup>
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { FileText, Download } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Pick a Craft document, and drop it into a notes space.
 *
 * **The list is short, and the setting wants it so.** The connection only
 * carries the documents designated in Craft: what is not there does not
 * appear here, and the way to add it is to go back to Craft. The screen says
 * so rather than suggesting an outage.
 *
 * **Loaded on open, not on mount.** A call to Craft costs a second, and most
 * visits to the notes screen never open it.
 *
 * The note lands at the root of the given space, or in the given folder; the
 * server checks that one can write there.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    documentsPath: { type: String, required: true },
    importPath: { type: String, required: true },
    spaceId: { type: Number, default: null },
    folderId: { type: Number, default: null },
});

const emit = defineEmits(["close", "imported"]);

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(false);
const importing = ref(false);
const configured = ref(true);
const reachable = ref(true);
const documents = ref([]);
const picked = ref(null);

const chosen = computed(
    () => documents.value.find((document) => document.id === picked.value) ?? null,
);

watch(
    () => props.show,
    async (open) => {
        if (!open) return;

        picked.value = null;
        loading.value = true;

        try {
            const data = await request(props.documentsPath, null, {
                method: HttpMethod.Get,
                noGuard: true,
            });

            configured.value = true === data?.configured;
            reachable.value = false !== data?.reachable;
            documents.value = Array.isArray(data?.documents) ? data.documents : [];
        } finally {
            loading.value = false;
        }
    },
);

async function submit() {
    if (importing.value || null === chosen.value) return;

    importing.value = true;

    try {
        const data = await request(props.importPath, {
            documentId: chosen.value.id,
            title: chosen.value.title,
            spaceId: props.spaceId,
            folderId: props.folderId,
        });

        if (data) {
            toast.success(t("notes.craft.import.imported"));
            emit("imported", data);
            emit("close");
        }
    } finally {
        importing.value = false;
    }
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :title="t('notes.craft.import.title')"
        :icon="FileText"
        v-on:close="emit('close')"
    >
        <div class="relative min-h-32 space-y-3">
            <AppLoader :active="loading" />

            <p class="text-sm text-secondary">{{ t("notes.craft.import.intro") }}</p>

            <template v-if="!loading">
                <div v-if="!configured" class="rounded-lg border border-line bg-surface-2 p-4">
                    <p class="text-sm text-primary">{{ t("notes.craft.import.disabled") }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t("notes.craft.import.disabled_hint") }}</p>
                </div>

                <!-- Unreachable and empty are not fixed in the same place:
                     one in the settings, the other in Craft. -->
                <div v-else-if="!reachable" class="rounded-lg border border-line bg-surface-2 p-4">
                    <p class="text-sm text-primary">{{ t("notes.craft.import.unreachable") }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t("notes.craft.import.unreachable_hint") }}</p>
                </div>

                <div v-else-if="!documents.length" class="rounded-lg border border-line bg-surface-2 p-4">
                    <p class="text-sm text-primary">{{ t("notes.craft.import.empty") }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t("notes.craft.import.empty_hint") }}</p>
                </div>

                <!-- Radio buttons, and not a dropdown: there are only a
                     handful, and seeing the titles side by side is what one
                     comes to do. -->
                <ul v-else class="divide-y divide-line/40 overflow-hidden rounded-lg border border-line">
                    <li v-for="document in documents" :key="document.id">
                        <label
                            class="flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors"
                            :class="picked === document.id ? 'bg-surface-2' : 'hover:bg-surface-2/60'"
                        >
                            <input
                                v-model="picked"
                                type="radio"
                                name="craft-document"
                                :value="document.id"
                                class="shrink-0 text-accent focus:ring-accent"
                            >
                            <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ document.title }}</span>
                        </label>
                    </li>
                </ul>
            </template>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton class="w-full sm:w-auto" variant="ghost" size="sm" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    class="w-full sm:w-auto"
                    variant="primary"
                    size="sm"
                    :disabled="null === chosen"
                    :loading="importing"
                    v-on:click="submit"
                >
                    <Download class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("notes.craft.import.submit") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
