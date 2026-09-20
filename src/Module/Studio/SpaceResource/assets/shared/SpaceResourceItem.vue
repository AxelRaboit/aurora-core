<script setup>
/**
 * Une ressource épinglée, telle qu'on la lit.
 *
 * **Le même composant des deux côtés.** Le studio l'entoure de ses boutons, le
 * client la lit seule : ce qui est écrit dedans est donc littéralement ce que
 * le client a sous les yeux, et non une seconde version. La seule chose que le
 * studio ajoute est autour, jamais dedans.
 *
 * Chaque genre se dessine pour ce qu'il est : un lien s'ouvre, un texte se
 * lit, un contact se compose. Un rendu unique aurait donné une liste de titres
 * dont il aurait fallu deviner le contenu.
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

            <!-- `noopener` avec `_blank` : sans lui la page ouverte garde une
                 poignée sur celle-ci par `window.opener`. -->
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

        <!-- Composables sur téléphone : c'est ce qui fait la différence entre
             une fiche de contact et une liste de coordonnées à recopier. -->
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
