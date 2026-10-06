/**
 * Does a breakdown have anything to show?
 *
 * A breakdown bar receives one segment per known category, filled or not:
 * three comment statuses give three segments even without a single comment.
 * Counting the segments therefore answered "yes" for a site that has
 * nothing, and the dashboard showed a titled card above an empty bar.
 *
 * This is the useful question, and it is asked in the same place for the
 * four breakdowns of the dashboard.
 *
 * @param {Array<{value?: number}>} segments
 */
export function hasAnyShare(segments) {
    return (segments ?? []).some((segment) => (segment?.value ?? 0) > 0);
}
