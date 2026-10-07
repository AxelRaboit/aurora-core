import { EMAIL_REGEX } from "./validation.js";

export const required = (message) => (value) => {
    if (value === null || value === undefined) return message;
    if (typeof value === "string" && !value.trim()) return message;
    if (Array.isArray(value) && value.length === 0) return message;
    return null;
};

export const email = (message) => (value) => {
    if (!value || !String(value).trim()) return null;
    return EMAIL_REGEX.test(String(value).trim()) ? null : message;
};

export const url = (message) => (value) => {
    if (!value || !String(value).trim()) return null;
    try {
        new URL(String(value).trim());
        return null;
    } catch {
        return message;
    }
};

export const compose =
    (...validators) =>
    (value) => {
        for (const validator of validators) {
            const error = validator(value);
            if (error) return error;
        }
        return null;
    };
