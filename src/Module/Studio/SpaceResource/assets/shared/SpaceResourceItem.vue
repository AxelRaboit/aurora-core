<script setup>
/**
 * A pinned resource, as it is read.
 *
 * **The same component on both sides.** The studio surrounds it with its
 * buttons, the client reads it alone: what is written inside is therefore
 * literally what the client has in front of them, and not a second version.
 * The only thing the studio adds is around it, never inside.
 *
 * Each kind is drawn for what it is: a link opens, a text is read, a contact
 * is dialled. A single rendering would have given a list of titles whose
 * content would have had to be guessed.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { AtSign, ExternalLink, FileText, Link2, Phone, User } from "lucide-vue-next";

const props = defineProps({
    resource: { type: Object, required: true },
});

const { t } = useI18n();

const ICONS = { link: Link2, text: FileText, contact: User };

const icon = computed(() => ICONS[props.resource.kind] ?? Link2);

const body = computed(() => ("string" === typeof props.resource.body && "" !== props.resource.body ? props.resource.body : null));
</script>

<template>
    <div class="min-w-0 space-y-1.5">
        <div class="flex items-start gap-2">
            <component :is="icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted" :stroke-width="2" />

            <!-- `noopener` with `_blank`: without it the opened page keeps a
                 handle on this one through `window.opener`. -->
            <a
                v-if="'link' === resource.kind && resource.url"
                :href="resource.url"
                target="_blank"
                rel="noopener noreferrer"
                class="group min-w-0 flex-1 text-sm font-medium text-primary transition-colors hover:text-accent"
            >
                <span class="break-words underline decoration-line underline-offset-2 group-hover:decoration-accent">
                    {{ resource.label }}
                </span>
                <ExternalLink class="ml-1 inline h-3 w-3 shrink-0 align-baseline" :stroke-width="2" />
            </a>
            <p v-else class="min-w-0 flex-1 break-words text-sm font-medium text-primary">{{ resource.label }}</p>
        </div>

        <p v-if="body" class="whitespace-pre-line break-words pl-6 text-sm text-muted">{{ body }}</p>

        <!-- Dialable on a phone: that is what makes the difference between
             a contact card and a list of details to copy out. -->
        <div v-if="'contact' === resource.kind && (resource.email || resource.phone)" class="flex flex-col gap-1 pl-6 sm:flex-row sm:gap-4">
            <a
                v-if="resource.email"
                :href="`mailto:${resource.email}`"
                class="inline-flex min-w-0 items-center gap-1.5 text-xs text-muted transition-colors hover:text-primary"
            >
                <AtSign class="h-3 w-3 shrink-0" :stroke-width="2" />
                <span class="truncate">{{ resource.email }}</span>
            </a>
            <a
                v-if="resource.phone"
                :href="`tel:${resource.phone.replace(/\s/g, '')}`"
                class="inline-flex items-center gap-1.5 text-xs text-muted transition-colors hover:text-primary"
            >
                <Phone class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ resource.phone }}
            </a>
        </div>

        <p v-if="'link' === resource.kind && resource.url" class="truncate pl-6 text-xs text-muted">
            {{ resource.url }}
        </p>

        <span class="sr-only">{{ t(`shared.space_resources.kinds.${resource.kind}`) }}</span>
    </div>
</template>
