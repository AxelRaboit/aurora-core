<script setup>
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Check, FileText, Folder, Search, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";

/**
 * Reusable picker for an existing GED Document. Mirrors the role of
 * `MediaPickerModal` but for the document-management module - semantically
 * the right place when callers need a business document (welding PDF
 * templates, contract attachments, …) rather than a content asset.
 *
 * Out of scope (intentional vs MediaPickerModal):
 *   - No upload from inside the picker. Users go to /backend/ged/documents
 *     to add or version docs. Keeps the component lean and discourages
 *     uploading docs without their metadata (category, tags, folder).
 *   - No inline edit of title/description for the same reason.
 *
 * Optional filters:
 *   - `mimeFilter` - restrict the visible documents to a single MIME
 *     (e.g. "application/pdf"). Applied client-side from the list payload.
 *   - `status` query - defaults to "published".
 *
 * Emits `select` with the full serialized document so the consumer can
 * extract whichever field it needs (`fileId`, `fileName`, etc.).
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    listPath: { type: String, required: true },
    // Strict MIME equality, e.g. "application/pdf".
    mimeFilter: { type: String, default: null },
    // Prefix-match, e.g. "image/" → every raster image MIME passes. Set by
    // the document picker wrapper when the consumer only wants images.
    mimePrefix: { type: String, default: null },
    // Bulk-pick mode: confirm returns an array of docs, not a single one.
    // Mirrors MediaPickerModal's `multiple` so callers like Gallery's
    // addPhotos flow can keep their existing array-shaped contract.
    multiple: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "select"]);

const { t } = useI18n();
const { formatSize } = useFileSize();

const items = ref([]);
const loading = ref(false);
const search = ref("");
const page = ref(1);
const totalPages = ref(1);
const selected = ref(null);

const visibleItems = computed(() => {
    let list = items.value;
    if (props.mimeFilter) {
        list = list.filter((doc) => doc.fileMime === props.mimeFilter);
    }
    if (props.mimePrefix) {
        list = list.filter((doc) => (doc.fileMime ?? "").startsWith(props.mimePrefix));
    }
    return list;
});

async function load() {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            page: String(page.value),
            status: "published",
        });
        if (search.value) params.set("search", search.value);
        const res = await fetch(`${props.listPath}?${params}`, {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        });
        const data = await res.json();
        items.value = data.items ?? [];
        page.value = data.page ?? 1;
        totalPages.value = data.totalPages ?? 1;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.show,
    (next) => {
        if (next) {
            selected.value = props.multiple ? [] : null;
            search.value = "";
            page.value = 1;
            load();
        }
    },
    { immediate: true },
);

function onSearch(value) {
    search.value = value;
    page.value = 1;
    load();
}

function goToPage(p) {
    page.value = p;
    load();
}

function pick(doc) {
    if (!props.multiple) {
        selected.value = doc;
        return;
    }
    const list = Array.isArray(selected.value) ? [...selected.value] : [];
    const idx = list.findIndex((d) => d.id === doc.id);
    if (idx === -1) list.push(doc);
    else list.splice(idx, 1);
    selected.value = list;
}

function confirm() {
    if (!selected.value) return;
    if (props.multiple) {
        if (Array.isArray(selected.value) && selected.value.length > 0) emit("select", selected.value);
        return;
    }
    emit("select", selected.value);
}

function isSelected(doc) {
    if (props.multiple) {
        return Array.isArray(selected.value) && selected.value.some((d) => d.id === doc.id);
    }
    return selected.value?.id === doc.id;
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="4xl"
        :title="t('backend.ged.documents.picker_title')"
        :icon="FileText"
        :closeable="true"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <AppSearchInput
                v-model="search"
                :placeholder="t('backend.ged.documents.search_placeholder')"
                v-on:search="onSearch"
            />

            <div class="relative min-h-64">
                <ul v-if="visibleItems.length > 0" class="space-y-1.5">
                    <li
                        v-for="doc in visibleItems"
                        :key="doc.id"
                        :class="[
                            'flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors',
                            isSelected(doc)
                                ? 'border-accent bg-accent-500/10'
                                : 'border-line bg-surface-2 hover:border-accent-500/40 hover:bg-surface-2/60',
                        ]"
                        v-on:click="pick(doc)"
                    >
                        <FileText class="w-5 h-5 text-secondary shrink-0" :stroke-width="1.5" />
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-primary truncate">{{ doc.title }}</span>
                                <AppBadge v-if="doc.categoryName" color="sky">{{ doc.categoryName }}</AppBadge>
                                <AppBadge v-if="doc.folderName" color="slate">
                                    <Folder class="w-3 h-3" :stroke-width="2" /> {{ doc.folderName }}
                                </AppBadge>
                            </div>
                            <p v-if="doc.description" class="text-xs text-secondary line-clamp-1 mt-0.5">{{ doc.description }}</p>
                            <p class="text-xs text-muted mt-0.5">
                                {{ doc.fileName }}
                                <span v-if="doc.fileSize"> · {{ formatSize(doc.fileSize) }}</span>
                                <span v-if="doc.fileMime"> · {{ doc.fileMime }}</span>
                            </p>
                        </div>
                        <Check v-if="isSelected(doc)" class="w-5 h-5 text-accent shrink-0" :stroke-width="2.5" />
                    </li>
                </ul>
                <AppNoData v-else-if="!loading" :message="t('backend.ged.documents.picker_empty')" />
                <AppLoader :active="loading" />
            </div>

            <AppPagination v-if="totalPages > 1" :page="page" :total-pages="totalPages" v-on:go-to-page="goToPage" />
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    :disabled="multiple ? !(Array.isArray(selected) && selected.length > 0) : !selected"
                    v-on:click="confirm"
                >
                    <Check class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.ged.documents.picker_confirm") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
