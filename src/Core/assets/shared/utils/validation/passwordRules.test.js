import { describe, it, expect } from "vitest";
import { PASSWORD_RULES, passwordValidator } from "./passwordRules.js";

describe("PASSWORD_RULES", () => {
    it("fails the length rule for short passwords", () => {
        const rule = PASSWORD_RULES.find(
            (candidate) => candidate.key === "length",
        );
        expect(rule.test("short")).toBe(false);
        expect(rule.test("longpassword")).toBe(true);
    });

    it("fails the uppercase rule when no uppercase letter", () => {
        const rule = PASSWORD_RULES.find(
            (candidate) => candidate.key === "uppercase",
        );
        expect(rule.test("alllower1!")).toBe(false);
        expect(rule.test("HasUpper1!")).toBe(true);
    });

    it("fails the number rule when no digit", () => {
        const rule = PASSWORD_RULES.find(
            (candidate) => candidate.key === "number",
        );
        expect(rule.test("NoDigits!A")).toBe(false);
        expect(rule.test("Has1Digit!")).toBe(true);
    });

    it("fails the special rule when no special character", () => {
        const rule = PASSWORD_RULES.find(
            (candidate) => candidate.key === "special",
        );
        expect(rule.test("NoSpecial1A")).toBe(false);
        expect(rule.test("Has@Special1")).toBe(true);
    });
});

describe("passwordValidator", () => {
    it("returns an error key for a short password", () => {
        const t = (key) => key;
        const validate = passwordValidator(t);
        expect(validate("short")).toBe("shared.password.errors.too_short");
    });

    it("returns null for a valid password", () => {
        const t = (key) => key;
        const validate = passwordValidator(t);
        expect(validate("ValidPass1!")).toBeNull();
    });
});
