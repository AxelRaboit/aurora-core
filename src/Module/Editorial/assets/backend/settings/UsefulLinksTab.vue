<script setup>
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import UsefulLinksField from "../posts/components/UsefulLinksField.vue";

/**
 * The site's useful links: set once here, shown at the foot of every
 * publication that does not bring its own. The server answers with the list
 * as kept - a link without words or with an address that is not https or
 * mailto is dropped - and the tab shows that answer, not what was typed.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/editorial/useful-links/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const links = ref([]);

onMounted(async () => {
    try {
        const state = await request(SETTINGS_PATH, null, { method: HttpMethod.Get, noGuard: true });
        links.value = state?.links ?? [];
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true;
    try {
        const state = await request(SETTINGS_PATH, { links: links.value }, { noGuard: true });

        if (state) {
            links.value = state.links ?? [];
            toast.success(t("backend.settings.saved"));
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="relative space-y-5">
        <AppLoader :active="loading" />

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.editorial.useful_links.settings.title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.editorial.useful_links.settings.intro") }}</p>
        </section>

        <UsefulLinksField v-model="links" />

        <div class="flex justify-end">
            <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>
    </div>
</template>
