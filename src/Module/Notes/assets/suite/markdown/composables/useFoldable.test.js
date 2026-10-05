import { describe, expect, it } from "vitest";
import { nextTick } from "vue";
import { useFoldable } from "./useFoldable.js";

/**
 * Un contrôle replié, et le piège de le brancher directement sur un clic.
 */
describe("useFoldable", () => {
    it("opens, and hands the keyboard to what appeared", async () => {
        const { open, box, reveal } = useFoldable();
        const field = document.createElement("input");
        const host = document.createElement("div");

        host.append(field);
        document.body.append(host);
        box.value = host;

        await reveal();

        expect(open.value).toBe(true);
        expect(document.activeElement).toBe(field);

        host.remove();
    });

    /**
     * Branché tel quel sur un `@click`, `reveal` reçoit l'événement à la
     * place du sélecteur. `querySelector` le refusait en levant, la promesse
     * partait en échec, et Vue remontait cet échec jusqu'au `errorCaptured`
     * de la page, qui remplaçait la bibliothèque entière par son écran
     * d'erreur : cliquer la loupe vidait l'écran.
     */
    it("survives being handed a click event instead of a selector", async () => {
        const { open, box, reveal } = useFoldable();
        const field = document.createElement("input");
        const host = document.createElement("div");

        host.append(field);
        document.body.append(host);
        box.value = host;

        await expect(reveal(new MouseEvent("click"))).resolves.toBeUndefined();

        expect(open.value).toBe(true);
        expect(document.activeElement).toBe(field);

        host.remove();
    });

    it("folds back", async () => {
        const { open, reveal, fold } = useFoldable();

        await reveal();
        await nextTick();
        fold();

        expect(open.value).toBe(false);
    });
});
