/**
 * The shared stages a step of a board can stand for, in board order, with
 * the key that names each one.
 *
 * Mirrors `SpaceContentColumnRoleEnum`. Written out rather than built from
 * the value, so every key is a literal the translation checks can find.
 */
export const COLUMN_ROLES = [
    {
        value: "idea",
        labelKey: "suite.studio.space_content.column_roles.idea",
    },
    {
        value: "production",
        labelKey: "suite.studio.space_content.column_roles.production",
    },
    {
        value: "review",
        labelKey: "suite.studio.space_content.column_roles.review",
    },
    {
        value: "scheduled",
        labelKey: "suite.studio.space_content.column_roles.scheduled",
    },
    {
        value: "published",
        labelKey: "suite.studio.space_content.column_roles.published",
    },
];
