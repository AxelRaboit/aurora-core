<script setup>
/**
 * Add or edit a resource.
 *
 * **The kind is chosen first, and it dictates the rest.** A link wants an
 * address, a text wants a body, a contact wants an address and a number: a
 * form that showed all five fields every time would ask people to guess
 * which ones count. The choice is at the top, as three tiles rather than a
 * dropdown, because there are three of them and they get compared.
 *
 * **Visibility is in the modal, not after the fact.** It is a decision that
 * belongs to the moment of filing: closing the modal and then looking for a
 * switch in the list is what makes people forget. Closed by default, and
 * offered only to whoever can share the space: the others prepare the
 * resource, someone who can shows it.
 *
 * The kind can no longer change when editing: what was saved as a contact
 * and became a text would leave behind an address and a number that nothing
 * displays any more.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, Link2, Save, User } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** The resource being edited, or `null` to add a new one. */
    resource: { type: Object, default: null },
    saving: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    /** Showing or hiding from the client: the right to share the space. */
    canShowToClient: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "submit"]);

const { t } = useI18n();

const KINDS = [
    { key: "link", icon: Link2 },
    { key: "text", icon: FileText },
    { key: "contact", icon: User },
];

const form = ref(blank());

function blank() {
    return { kind: "link", label: "", url: "", body: "", email: "", phone: "", visibleToClient: false };
}

watch(
    () => [props.show, props.resource],
    () => {
        if (!props.show) return;

        form.value = props.resource
            ? {
                kind: props.resource.kind,
                label: props.resource.label ?? "",
                url: props.resource.url ?? "",
                body: props.resource.body ?? "",
                email: props.resource.email ?? "",
                phone: props.resource.phone ?? "",
                visibleToClient: true === props.resource.visibleToClient,
            }
            : blank();
    },
    { immediate: true },
);

const editing = computed(() => null !== props.resource);

const labelPlaceholder = computed(() => t(`suite.studio.space_resources.label_placeholder_${form.value.kind}`));

function error(field) {
    const message = props.errors?.[field];

    return message ? t(message) : "";
}
</script>

<template>
    <AppModal
        :show="show"
        :title="t(editing ? 'suite.studio.space_resources.edit' : 'suite.studio.space_resources.add')"
        max-width="lg"
        mobile-fullscreen
        v-on:close="emit('close')"
    >
        <form class="space-y-5" v-on:submit.prevent="emit('submit', { ...form })">
            <fieldset v-if="!editing" class="space-y-2">
                <legend class="text-sm font-medium text-primary">{{ t("suite.studio.space_resources.kind") }}</legend>

                <!-- One column on a phone: three tiles on 375 px give cut-off
                     labels, and the choice is what dictates the whole rest
                     of the form. -->
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <button
                        v-for="entry in KINDS"
                        :key="entry.key"
                        type="button"
                        class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition-colors"
                        :class="
                            form.kind === entry.key
                                ? 'border-accent bg-accent/5 text-primary'
                                : 'border-line text-muted hover:border-line hover:text-primary'
                        "
                        :aria-pressed="form.kind === entry.key"
                        v-on:click="form.kind = entry.key"
                    >
                        <component :is="entry.icon" class="h-4 w-4 shrink-0" :stroke-width="2" />
                        <span>{{ t(`shared.space_resources.kinds.${entry.key}`) }}</span>
                    </button>
                </div>
            </fieldset>

            <AppInput
                v-model="form.label"
                :label="t('suite.studio.space_resources.label')"
                :placeholder="labelPlaceholder"
                :error="error('label')"
                required
            />

            <AppInput
                v-if="'link' === form.kind"
                v-model="form.url"
                :label="t('suite.studio.space_resources.url')"
                placeholder="https://"
                :error="error('url')"
                required
            />

            <template v-if="'contact' === form.kind">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="form.email"
                        type="email"
                        :label="t('suite.studio.space_resources.email')"
                        :placeholder="t('suite.studio.space_resources.email_placeholder')"
                        :error="error('email')"
                    />
                    <AppInput
                        v-model="form.phone"
                        :label="t('suite.studio.space_resources.phone')"
                        :placeholder="t('suite.studio.space_resources.phone_placeholder')"
                        :error="error('phone')"
                    />
                </div>
            </template>

            <AppTextarea
                v-model="form.body"
                :label="t(`suite.studio.space_resources.body_${form.kind}`)"
                :placeholder="t(`suite.studio.space_resources.body_placeholder_${form.kind}`)"
                :hint="t(`suite.studio.space_resources.body_hint_${form.kind}`)"
                :error="error('body')"
                :rows="'text' === form.kind ? 8 : 3"
                :required="'text' === form.kind"
            />

            <AppToggle
                v-if="canShowToClient"
                v-model="form.visibleToClient"
                :label="t('suite.studio.space_resources.visible_to_client')"
                :hint="t(form.visibleToClient ? 'suite.studio.space_resources.visible_hint_on' : 'suite.studio.space_resources.visible_hint_off')"
            />
        </form>

        <template #footer>
            <!-- `AppModalFooter` already stacks full width on a phone. -->
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('submit', { ...form })">
                    <Save class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t(editing ? "shared.common.save" : "suite.studio.space_resources.add_confirm") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
