<script setup>
/**
 * A customer's sheet, as it is read.
 *
 * **The same component on both sides, and that is the point.** The studio
 * sees a form, then this below it; the customer only sees this. What the
 * studio reads back is therefore exactly what the customer has in front of
 * them, and not a second version that could differ from it without anyone
 * noticing - the reason there is only one serialization of the sheet on the
 * server side.
 *
 * **Empty fields do not appear.** A sheet where everything is optional and
 * every row is drawn anyway is a sheet made of dashes: what you mostly read
 * is what is missing. What is filled in is read, the rest does not exist.
 *
 * Numbers and addresses are clickable: on a phone, that is the difference
 * between a sheet and an address book.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { AtSign, Building2, ExternalLink, FileText, Hash, MapPin, Phone, Smartphone } from "lucide-vue-next";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";

const props = defineProps({
    /** The shape `CustomerInformationSerializer` returns, or `null`. */
    information: { type: Object, default: null },
});

const { t } = useI18n();

const info = computed(() => props.information ?? {});

/**
 * The rows to draw, in the order they are read.
 *
 * A table rather than a series of `v-if`: the order reads at a glance, adding
 * a field is one line, and nothing can be drawn twice.
 */
const ROWS = [
    { key: "siret", labelKey: "shared.space_information.siret", icon: Hash },
    { key: "siren", labelKey: "shared.space_information.siren", icon: Hash },
    { key: "phone", labelKey: "shared.space_information.phone", icon: Smartphone, href: (v) => `tel:${v.replace(/\s/g, "")}` },
    { key: "landline", labelKey: "shared.space_information.landline", icon: Phone, href: (v) => `tel:${v.replace(/\s/g, "")}` },
    { key: "email", labelKey: "shared.space_information.email", icon: AtSign, href: (v) => `mailto:${v}` },
    { key: "postalAddress", labelKey: "shared.space_information.registered_office", icon: MapPin, multiline: true },
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

        <!-- One column on phones, two from `sm` up: an identity sheet reads
             as label / value pairs, and two columns on 375 px cut addresses
             in the middle of a word. -->
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
                    <!-- `noopener` with `_blank`: without it the opened page
                         keeps a handle on this one through `window.opener`. -->
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
