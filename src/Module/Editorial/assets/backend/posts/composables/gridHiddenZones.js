/**
 * Keeps the zones a grid does not offer out of what is pasted into it.
 *
 * A deliverable's editor hides some zone types from its picker (a comments
 * thread, a form, a shared block...) because they cannot belong in a document
 * handed to a client. A section copied from a publication, or taken from the
 * library, bypassed the picker: such a zone was saved, and only the page's
 * render dropped it - a silent hole between what the author pasted and what
 * the client reads. Dropped here instead, and counted, so the author is told.
 *
 * @param {{zones?: object[], content?: object}} payload zones to insert, with their content
 * @param {string[]} hiddenTypes the types the target grid does not offer
 *
 * @returns {{payload: object, dropped: number}}
 */
export function withoutHiddenTypes(payload, hiddenTypes) {
    if (!hiddenTypes?.length || !Array.isArray(payload?.zones))
        return { payload, dropped: 0 };

    let dropped = 0;

    const keep = (zones) =>
        zones.flatMap((zone) => {
            if (hiddenTypes.includes(zone?.type)) {
                dropped +=
                    1 +
                    (Array.isArray(zone.children) ? zone.children.length : 0);

                return [];
            }

            if (!Array.isArray(zone?.children)) return [zone];

            const children = keep(zone.children);

            return [{ ...zone, children }];
        });

    return { payload: { ...payload, zones: keep(payload.zones) }, dropped };
}
