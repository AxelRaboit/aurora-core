<script setup>
/**
 * La fiche d'un client, telle qu'on la lit.
 *
 * **Le même composant des deux côtés, et c'est le point.** Le studio voit un
 * formulaire, puis ceci en dessous ; le client ne voit que ceci. Ce que le
 * studio relit est donc exactement ce que le client a sous les yeux, et non
 * une seconde version qui pourrait en différer sans que personne s'en
 * aperçoive - la raison pour laquelle il n'existe qu'une sérialisation de la
 * fiche côté serveur.
 *
 * **Les champs vides n'apparaissent pas.** Une fiche où tout est facultatif et
 * où chaque ligne est dessinée quand même est une fiche faite de tirets : on
 * lit surtout ce qui manque. Ce qui est rempli se lit, le reste n'existe pas.
 *
 * Les numéros et les adresses sont cliquables : sur téléphone, c'est la
 * différence entre une fiche et un carnet d'adresses.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { AtSign, Building2, ExternalLink, FileText, Hash, MapPin, Phone, Smartphone } from "lucide-vue-next";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";

const props = defineProps({
    /** La forme que `CustomerInformationSerializer` rend, ou `null`. */
    information: { type: Object, default: null },
});

const { t } = useI18n();

const info = computed(() => props.information ?? {});

/**
 * Les lignes à dessiner, dans l'ordre où on les lit.
 *
 * Une table plutôt qu'une suite de `v-if` : l'ordre se lit d'un coup, ajouter
 * un champ est une ligne, et rien ne peut être dessiné deux fois.
 */
const ROWS = [
    { key: "siret", labelKey: "shared.space_information.siret", icon: Hash },
    { key: "siren", labelKey: "shared.space_information.siren", icon: Hash },
    { key: "phone", labelKey: "shared.space_information.phone", icon: Smartphone, href: (v) => `tel:${v.replace(/\s/g, "")}` },
    { key: "landline", labelKey: "shared.space_information.landline", icon: Phone, href: (v) => `tel:${v.replace(/\s/g, "")}` },
    { key: "email", labelKey: "shared.space_information.email", icon: AtSign, href: (v) => `mailto:${v}` },
    { key: "postalAddress", labelKey: "shared.space_information.postal_address", icon: MapPin, multiline: true },
];

const rows = computed(() => ROWS.filter((row) => {
    const value = info.value[row.key];

    return "string" === typeof value && "" !== value;
}));

const links = computed(() => (Array.isArray(info.value.links) ? info.value.links : []));

const notes = computed(() => ("string" === typeof info.value.notes && "" !== info.value.notes ? info.value.notes : null));

const empty = computed(() => 0 === rows.value.length && 0 === links.value.length && null === notes.value);
</script>

<template>
    <div class="space-y-5">
        <div class="flex items-center gap-2">
            <Building2 class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
            <h3 class="text-sm font-medium text-primary">{{ info.legalName }}</h3>
        </div>

        <AppNoData
            v-if="empty"
            :message="t('shared.space_information.empty')"
            :hint="t('shared.space_information.empty_hint')"
        />

        <!-- Une colonne sur téléphone, deux à partir de `sm` : une fiche
             d'identité se lit en paires libellé / valeur, et deux colonnes sur
             375 px coupent les adresses au milieu d'un mot. -->
        <dl v-if="rows.length" class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <div v-for="row in rows" :key="row.key" class="min-w-0">
                <dt class="flex items-center gap-1.5 text-xs uppercase tracking-wide text-muted">
                    <component :is="row.icon" class="h-3 w-3 shrink-0" :stroke-width="2" />
                    {{ t(row.labelKey) }}
                </dt>
                <dd class="mt-0.5 text-sm text-primary">
                    <a
                        v-if="row.href"
                        :href="row.href(info[row.key])"
                        class="break-words underline decoration-line underline-offset-2 transition-colors hover:decoration-primary"
                    >{{ info[row.key] }}</a>
                    <span v-else class="break-words" :class="row.multiline ? 'whitespace-pre-line' : ''">
                        {{ info[row.key] }}
                    </span>
                </dd>
            </div>
        </dl>

        <div v-if="links.length" class="space-y-2">
            <p class="text-xs uppercase tracking-wide text-muted">{{ t("shared.space_information.links") }}</p>
            <ul class="space-y-1.5">
                <li v-for="(link, index) in links" :key="index">
                    <!-- `noopener` avec `_blank` : sans lui la page ouverte
                         garde une poignée sur celle-ci par `window.opener`. -->
                    <a
                        :href="link.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex max-w-full items-center gap-1.5 text-sm text-primary transition-colors hover:text-accent"
                    >
                        <ExternalLink class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                        <span class="truncate underline decoration-line underline-offset-2">{{ link.label }}</span>
                    </a>
                </li>
            </ul>
        </div>

        <div v-if="notes" class="space-y-2">
            <p class="flex items-center gap-1.5 text-xs uppercase tracking-wide text-muted">
                <FileText class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t("shared.space_information.notes") }}
            </p>
            <p class="whitespace-pre-line break-words text-sm text-primary">{{ notes }}</p>
        </div>
    </div>
</template>
