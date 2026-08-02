import { describe, expect, it } from "vitest";
import { buildPath } from "@/shared/utils/http/buildPath.js";

describe("buildPath", () => {
    it("replaces a single placeholder", () => {
        expect(
            buildPath("/backend/platform/users/__id__/edit", { id: 42 }),
        ).toBe("/backend/platform/users/42/edit");
    });

    it("replaces multiple placeholders", () => {
        expect(
            buildPath("/backend/ged/documents/__id__/versions/__versionId__", {
                id: 1,
                versionId: 7,
            }),
        ).toBe("/backend/ged/documents/1/versions/7");
    });

    it("URI-encodes values with special characters", () => {
        expect(
            buildPath("/backend/parameters/__key__", { key: "site/name" }),
        ).toBe("/backend/parameters/site%2Fname");
    });

    it("URI-encodes emails", () => {
        expect(
            buildPath("/_login_as/__email__", { email: "foo+bar@x.io" }),
        ).toBe("/_login_as/foo%2Bbar%40x.io");
    });

    it("returns the template untouched when params is empty", () => {
        expect(buildPath("/backend/platform/users", {})).toBe(
            "/backend/platform/users",
        );
        expect(buildPath("/backend/platform/users")).toBe(
            "/backend/platform/users",
        );
    });

    it("leaves unknown placeholders intact", () => {
        // Caller should know what to fill — silent tolerance keeps it composable.
        expect(buildPath("/backend/__a__/__b__", { a: 1 })).toBe(
            "/backend/1/__b__",
        );
    });

    it("replaces every occurrence of a placeholder", () => {
        expect(buildPath("__id__-__id__", { id: 5 })).toBe("5-5");
    });

    it("coerces numeric and string values", () => {
        expect(buildPath("/x/__a__", { a: 12 })).toBe("/x/12");
        expect(buildPath("/x/__a__", { a: "abc" })).toBe("/x/abc");
    });
});
