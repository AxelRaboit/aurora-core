import { markRaw } from "vue";

/**
 * The global search sections modules contribute.
 *
 * The PHP side already keeps the search controller free of business modules -
 * results arrive through `BackendSearchProviderInterface` - and this is the same
 * arrangement for the Vue side, which did not have one.
 *
 * Before this, the section keys were written out twice in core: once in the
 * composable that copies them off the response and once in the palette that draws
 * them. A module contributing a section without editing both files had its results
 * **silently dropped** - the provider ran, the rows came back, and the composable
 * never looked at the key. So the registry the interface promised did not exist on
 * the half a reader can see.
 *
 * A module registers from a `*.register.js` file; app.js eager-globs those before
 * any Vue app mounts, so the registry is full by the time the palette reads it.
 */
const sections = [];

/**
 * @param {object}   section
 * @param {string}   section.key      the key the PHP provider returns rows under
 * @param {string}   section.kind     the singular name the palette branches on
 * @param {string}   section.labelKey i18n key for the section heading
 * @param {object}   section.icon     lucide component for that heading
 * @param {number}   [section.order]  where it sits; lower comes first
 */
export function registerSearchSection({
    key,
    kind,
    labelKey,
    icon,
    order = 100,
}) {
    // Idempotent, like the dashboard's: HMR re-runs the register files, and a
    // duplicated section is a confusing way to find that out.
    const existing = sections.findIndex((section) => section.key === key);
    const entry = { key, kind, labelKey, icon: markRaw(icon), order };

    if (-1 === existing) {
        sections.push(entry);
    } else {
        sections[existing] = entry;
    }

    sections.sort((a, b) => a.order - b.order || a.key.localeCompare(b.key));
}

/** @returns {Array<{key: string, kind: string, labelKey: string, icon: object, order: number}>} */
export function searchSections() {
    return sections;
}

/** The response keys to copy, which is exactly what has been registered. */
export function searchSectionKeys() {
    return sections.map((section) => section.key);
}
