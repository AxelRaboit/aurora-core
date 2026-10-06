/**
 * The two stages a step of a board can stand for, in board order, with the
 * key that names each one: where the client answers, and what is out.
 *
 * Mirrors `SpaceContentColumnRoleEnum`. Written out rather than built from
 * the value, so every key is a literal the translation checks can find.
 */
export const COLUMN_ROLES = [
    {
        value: "review",
        labelKey: "suite.studio.space_content.column_roles.review",
    },
    {
        value: "published",
        labelKey: "suite.studio.space_content.column_roles.published",
    },
];
