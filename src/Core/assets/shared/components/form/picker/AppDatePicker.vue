<script setup>
import { VueDatePicker } from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";
import { useTheme } from "@/shared/composables/useTheme.js";
import { useI18n } from "vue-i18n";
import { computed } from "vue";
import { fr, enUS, es, de } from "date-fns/locale";

const { theme } = useTheme();
const { locale } = useI18n();
const isDark = computed(() => theme.value === "dark");

const LOCALES = { fr, en: enUS, es, de };
const dateFnsLocale = computed(() => LOCALES[locale.value] ?? enUS);

const props = defineProps({
    modelValue: { type: String, default: "" },
    label: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    required: { type: Boolean, default: false },
    error: { type: String, default: "" },
    /** Help text under the control - explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    enableTime: { type: Boolean, default: false },
    /**
     * Month-only picker. `modelValue` is then expected/emitted as
     * `YYYY-MM` (e.g. `2026-05`) instead of a full ISO date - handy
     * for budget months, monthly reports, etc.
     */
    monthOnly: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

/**
 * Les formats acceptés au clavier, du plus courant au plus toléré.
 *
 * Le champ ressemble à un champ de texte, donc il se tape. Avant, une date
 * tapée s'affichait puis disparaissait à la fermeture du calendrier, sans un
 * mot : le composant n'écoutait que les clics. Le premier format est celui
 * que rend le composant, les suivants sont ce qu'une personne écrit d'elle
 * même.
 *
 * Le mois seul a les siens : `2026-05` et `05/2026`.
 */
const TEXT_FORMATS = ["dd/MM/yyyy", "yyyy-MM-dd", "ddMMyyyy", "dd-MM-yyyy", "dd.MM.yyyy"];
const TIME_FORMATS = ["dd/MM/yyyy HH:mm", "yyyy-MM-dd HH:mm"];
const MONTH_FORMATS = ["MM/yyyy", "yyyy-MM"];

/**
 * Les formats que ce champ accepte, dans l'ordre.
 *
 * Un seul endroit pour les deux usages : ce qui est affiché est le premier de
 * la liste, ce qui est accepté au clavier est la liste entière.
 */
const acceptedFormats = computed(
    () => props.monthOnly ? MONTH_FORMATS : (props.enableTime ? TIME_FORMATS : TEXT_FORMATS),
);

/**
 * Ce que le champ affiche, et non ce que le navigateur préfère.
 *
 * Sans cette ligne, `VueDatePicker` rend la date avec son format par défaut,
 * qui suit la machine plutôt que l'application : un back-office français
 * affichait `09/20/2026, 20:00` pour le 20 septembre. Le docblock au-dessus
 * annonçait déjà que « le premier format est celui que rend le composant » -
 * c'était une intention, pas une implémentation.
 *
 * C'est le même défaut que celui qui avait fait remplacer les `datetime-local`
 * natifs par ce composant : une date lue de travers n'est pas une date
 * illisible, c'est une date comprise à l'envers.
 */
const displayFormat = computed(() => acceptedFormats.value[0]);

const textInput = computed(() => ({
    format: acceptedFormats.value,
    // Entrée valide la saisie, et une saisie que le composant ne sait pas
    // lire laisse la valeur précédente plutôt que de vider le champ.
    enterSubmit: true,
    tabSubmit: true,
    openMenu: "open",
}));

function onUpdate(val) {
    if (!val) { emit("update:modelValue", ""); return; }
    const pad = (n) => String(n).padStart(2, "0");
    if (props.monthOnly) {
        // VueDatePicker emits `{ month, year }` in month-picker mode.
        const year = val.year ?? new Date(val).getFullYear();
        const monthIndex = (val.month ?? new Date(val).getMonth());
        emit("update:modelValue", `${year}-${pad(monthIndex + 1)}`);
        return;
    }
    const d = new Date(val);
    if (props.enableTime) {
        emit("update:modelValue", `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`);
    } else {
        emit("update:modelValue", `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`);
    }
}

const internalValue = computed(() => {
    if (!props.modelValue) return null;
    if (props.monthOnly) {
        // Accept either YYYY-MM or YYYY-MM-DD - pull year + month index back out
        // and feed VueDatePicker's `{ month, year }` shape.
        const match = /^(\d{4})-(\d{2})/.exec(props.modelValue);
        if (!match) return null;
        return { year: Number.parseInt(match[1], 10), month: Number.parseInt(match[2], 10) - 1 };
    }
    const d = new Date(props.modelValue);
    return isNaN(d.getTime()) ? null : d;
});
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <VueDatePicker
            :model-value="internalValue"
            :dark="isDark"
            :locale="dateFnsLocale"
            :format="displayFormat"
            :enable-time-picker="enableTime"
            :month-picker="monthOnly"
            :placeholder="placeholder"
            :text-input="textInput"
            auto-apply
            :teleport="true"
            input-class-name="dp-custom-input"
            v-on:update:model-value="onUpdate"
        />
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
    </div>
</template>

<style>
/* Le champ du calendrier, peint comme AppInput.
 *
 * Il n'est pas rendu par nous - la bibliotheque pose son propre `input` - donc
 * il ne peut pas porter les classes utilitaires des autres champs, et ces
 * regles sont la traduction litterale de celles d'AppInput. Le liseré de
 * focus etait un indigo ecrit en dur : sur un site dont l'accent n'est pas
 * l'indigo, un seul champ du formulaire s'allumait de la mauvaise couleur.
 *
 * Toute retouche d'AppInput doit passer ici, faute de quoi les deux champs se
 * remettent a diverger. */
.dp-custom-input {
    width: 100%;
    border-radius: 0.375rem;
    border: 1px solid var(--color-line);
    background: var(--color-surface);
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: var(--color-primary);
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
}
.dp-custom-input::placeholder {
    color: var(--color-muted);
}
.dp-custom-input:focus {
    border-color: var(--color-accent-500);
    box-shadow: 0 0 0 1px var(--color-accent-500);
}
</style>
