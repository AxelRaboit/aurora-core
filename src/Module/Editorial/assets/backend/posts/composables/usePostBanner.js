import { computed } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Drives the banner panel of the post editor.
 *
 * Holds no state of its own: the banner lives inside the current translation,
 * so switching locale switches banner with it and saving carries it along
 * without this file knowing anything about the save.
 *
 * Every field is exposed as a writable computed rather than being bound
 * straight in the template. Two reasons, and the second is the real one:
 * the panel stays a pure template, and `v-model` on a prop's property is a
 * mutation ESLint rightly refuses — the write belongs here, next to the
 * mapping it goes through.
 *
 * The picker components speak `{ id, url }` while the wire format is a bare
 * `mediaId` plus the `media` the server resolved; translating between the two
 * is most of what the media fields do.
 */
function writable(get, set) {
    return computed({ get, set });
}

function pickerModel(target, mediaKey, idKey) {
    return {
        id: target[idKey],
        url: target[mediaKey]?.url ?? null,
    };
}

function applyPicked(target, picked, mediaKey, idKey) {
    target[idKey] = picked?.id ?? null;
    // Keep the url the picker handed back so the preview survives until the
    // next save; the server re-resolves it from the id on the way out.
    target[mediaKey] = picked?.id ? { url: picked.url ?? null } : null;
}

export function usePostBanner(banner) {
    const { t } = useI18n();

    const options = (values, prefix) =>
        computed(() =>
            values.map((value) => ({
                value,
                label: t(
                    `backend.posts.banner.${prefix}.${value.replace("-", "_")}`,
                ),
            })),
        );

    const heightOptions = options(["sm", "md", "lg", "full"], "heights");
    const ratioOptions = options(["50-50", "33-67", "67-33"], "ratios");
    const slotTypeOptions = options(["none", "text", "image"], "slot_types");
    const alignOptions = options(["start", "center", "end"], "aligns");
    const fillOptions = options(["none", "solid", "gradient"], "fills");

    /**
     * The ratio only decides anything when both slots hold something — a lone
     * slot spans the row whatever it says. Hiding the control then is what
     * stops it reading as broken.
     */
    const bothSlotsFilled = computed(
        () =>
            banner.value.slots.filter((slot) => slot.type !== "none").length >
            1,
    );

    /**
     * Authors think in arrangements — "text then image", "two images" — while
     * the model is two independent slots. These presets bridge the two: one
     * click sets both slot types, and the per-slot control below stays free
     * for anything the list does not cover.
     *
     * Deliberately not stored: the arrangement is derived from the slot types,
     * so there is no second source of truth to keep in step.
     */
    const presets = [
        { key: "text", types: ["text", "none"] },
        { key: "image", types: ["image", "none"] },
        { key: "text_image", types: ["text", "image"] },
        { key: "image_text", types: ["image", "text"] },
        { key: "two_texts", types: ["text", "text"] },
        { key: "two_images", types: ["image", "image"] },
    ];

    const presetOptions = computed(() =>
        presets.map(({ key, types }) => ({
            key,
            types,
            label: t(`backend.posts.banner.presets.${key}`),
            active: banner.value.slots.every(
                (slot, i) => slot.type === types[i],
            ),
        })),
    );

    function applyPreset(types) {
        types.forEach((type, index) => {
            banner.value.slots[index].type = type;
        });
    }

    const background = () => banner.value.background;

    const fields = {
        enabled: writable(
            () => banner.value.enabled,
            (value) => {
                banner.value.enabled = value;
            },
        ),
        height: writable(
            () => banner.value.height,
            (value) => {
                banner.value.height = value;
            },
        ),
        ratio: writable(
            () => banner.value.ratio,
            (value) => {
                banner.value.ratio = value;
            },
        ),
        fillType: writable(
            () => background().type,
            (value) => {
                background().type = value;
            },
        ),
        backgroundColor: writable(
            () => background().color,
            (value) => {
                background().color = value;
            },
        ),
        gradientFrom: writable(
            () => background().gradientFrom,
            (value) => {
                background().gradientFrom = value;
            },
        ),
        gradientTo: writable(
            () => background().gradientTo,
            (value) => {
                background().gradientTo = value;
            },
        ),
        gradientAngle: writable(
            () => background().gradientAngle,
            (value) => {
                background().gradientAngle = value;
            },
        ),
        overlay: writable(
            () => background().overlay,
            (value) => {
                background().overlay = value;
            },
        ),
        backgroundMedia: writable(
            () => pickerModel(background(), "media", "mediaId"),
            (value) => applyPicked(background(), value, "media", "mediaId"),
        ),
        logoMedia: writable(
            () => pickerModel(banner.value, "logo", "logoMediaId"),
            (value) => applyPicked(banner.value, value, "logo", "logoMediaId"),
        ),
    };

    const hasBackgroundImage = computed(() => null !== background().media);
    const isSolidFill = computed(() => "solid" === background().type);
    const isGradientFill = computed(() => "gradient" === background().type);

    /**
     * A live swatch of the fill being composed. Cheap to derive here, and it
     * spares an author from saving just to find out which way the gradient
     * runs — the panel has no preview of the banner itself yet.
     */
    const fillPreviewStyle = computed(() => {
        const { type, color, gradientFrom, gradientTo, gradientAngle } =
            background();

        if ("solid" === type && color) {
            return { backgroundColor: color };
        }

        if ("gradient" === type && gradientFrom && gradientTo) {
            return {
                backgroundImage: `linear-gradient(${gradientAngle}deg, ${gradientFrom}, ${gradientTo})`,
            };
        }

        return null;
    });

    // Built once per slot and cached: the template calls slotFields(index) on
    // every render, and handing back a fresh set of computeds each time would
    // throw away their caching for nothing.
    const slotFieldsCache = new Map();

    function slotFields(index) {
        if (!slotFieldsCache.has(index)) {
            const slot = () => banner.value.slots[index];
            const scalar = (key) =>
                writable(
                    () => slot()[key],
                    (value) => {
                        slot()[key] = value;
                    },
                );

            slotFieldsCache.set(index, {
                type: scalar("type"),
                title: scalar("title"),
                description: scalar("description"),
                titleColor: scalar("titleColor"),
                descriptionColor: scalar("descriptionColor"),
                align: scalar("align"),
                alt: scalar("alt"),
                media: writable(
                    () => pickerModel(slot(), "media", "mediaId"),
                    (value) => applyPicked(slot(), value, "media", "mediaId"),
                ),
            });
        }

        return slotFieldsCache.get(index);
    }

    return {
        heightOptions,
        ratioOptions,
        slotTypeOptions,
        alignOptions,
        fillOptions,
        presetOptions,
        applyPreset,
        bothSlotsFilled,
        hasBackgroundImage,
        isSolidFill,
        isGradientFill,
        fillPreviewStyle,
        fields,
        slotFields,
    };
}
