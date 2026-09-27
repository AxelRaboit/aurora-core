<script setup>
import { computed } from "vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";

/**
 * Le choix unique du back-office, jamais un `<select>` natif.
 *
 * Le menu du navigateur ne suit ni le thème ni la police, et il n'a pas la
 * même allure d'un système à l'autre : c'était le seul contrôle de la maison
 * à trancher sur le reste de l'écran. Celui-ci repose sur le sélecteur de
 * `AppMultiselect`, sans recherche tant que la liste reste courte.
 *
 * Le contrat de l'ancien `<select>` est gardé tel quel, pour que les appelants
 * n'aient rien à changer : la valeur émise est toujours une chaîne (ce que
 * rendait `$event.target.value`, et ce que `v-model.number` sait convertir),
 * et le placeholder reste une entrée qu'on peut choisir, celle qui ramène à
 * « Tous les … » dans une barre de filtres.
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: "" },
    label: { type: String, default: "" },
    error: { type: String, default: "" },
    /** Help text under the control - explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    required: { type: Boolean, default: false },
    /** Topic id from `helpTopics.js`, surfaced next to the label. */
    help: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
    /** Array of { value, label } OR object { value: label }. */
    options: { type: [Array, Object], default: () => [] },
    /** Au-delà de ce nombre d'entrées, taper filtre la liste. */
    searchAbove: { type: Number, default: 10 },
});

const emit = defineEmits(["update:modelValue"]);

const choices = computed(() => {
    const list = Array.isArray(props.options)
        ? props.options.map((option) => ({ value: String(option.value), label: option.label }))
        : Object.entries(props.options ?? {}).map(([value, label]) => ({ value, label }));

    return props.placeholder ? [{ value: "", label: props.placeholder }, ...list] : list;
});

const current = computed(() => String(props.modelValue ?? ""));
</script>

<template>
    <AppMultiselect
        :model-value="current"
        :options="choices"
        :label="label"
        :error="error"
        :hint="hint"
        :help="help"
        :required="required"
        :disabled="disabled"
        :placeholder="placeholder"
        :searchable="choices.length > searchAbove"
        v-on:update:model-value="emit('update:modelValue', $event ?? '')"
    />
</template>
