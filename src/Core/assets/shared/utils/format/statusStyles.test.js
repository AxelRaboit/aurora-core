import { describe, it, expect } from "vitest";
import {
    statusBadge,
    statusBadgeColor,
    accessRequestStatusBadge,
    accessRequestStatusBadgeColor,
    POST_STATUS_COLORS,
    POST_STATUS_CHART_SLOTS,
} from "./statusStyles.js";

describe("statusBadge", () => {
    it("returns the correct classes for known statuses", () => {
        expect(statusBadge("published")).toBe(
            "bg-emerald-500/15 text-emerald-400",
        );
        expect(statusBadge("draft")).toBe("bg-surface-2 text-secondary");
        expect(statusBadge("pending_review")).toBe(
            "bg-amber-500/15 text-amber-400",
        );
    });

    it("returns fallback classes for unknown status", () => {
        expect(statusBadge("unknown")).toBe("bg-surface-2 text-secondary");
    });
});

describe("statusBadgeColor", () => {
    it("returns color name for known status", () => {
        expect(statusBadgeColor("scheduled")).toBe("sky");
        expect(statusBadgeColor("archived")).toBe("zinc");
    });

    it("returns gray for unknown status", () => {
        expect(statusBadgeColor("whatever")).toBe("gray");
    });
});

describe("post status palette", () => {
    it("gives every status a badge colour and a chart slot", () => {
        const statuses = [
            "draft",
            "pending_review",
            "scheduled",
            "published",
            "archived",
        ];

        expect(Object.keys(POST_STATUS_COLORS).sort()).toEqual(
            [...statuses].sort(),
        );
        expect(Object.keys(POST_STATUS_CHART_SLOTS).sort()).toEqual(
            [...statuses].sort(),
        );
    });

    it("never gives two statuses the same chart colour", () => {
        const slots = Object.values(POST_STATUS_CHART_SLOTS);

        expect(new Set(slots).size).toBe(slots.length);
    });
});

describe("accessRequestStatusBadge", () => {
    it("returns correct classes for access request statuses", () => {
        expect(accessRequestStatusBadge("pending")).toBe(
            "bg-amber-500/15 text-amber-400",
        );
        expect(accessRequestStatusBadge("approved")).toBe(
            "bg-emerald-500/15 text-emerald-400",
        );
    });

    it("returns fallback for unknown status", () => {
        expect(accessRequestStatusBadge("other")).toBe(
            "bg-surface-2 text-secondary",
        );
    });
});

describe("accessRequestStatusBadgeColor", () => {
    it("returns correct color for known status", () => {
        expect(accessRequestStatusBadgeColor("rejected")).toBe("gray");
    });
});
