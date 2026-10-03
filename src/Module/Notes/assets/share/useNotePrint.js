/**
 * Une note sur papier, ou en PDF : l'impression du navigateur.
 *
 * Comme pour les présentations, **l'impression du navigateur est l'export** :
 * « Enregistrer en PDF » est dans le même dialogue, et le navigateur qui a
 * dessiné la note imprime la note qu'il a dessinée, encadrés, tableaux et
 * images compris. dompdf, qui sert les contrats, ne connaît ni flex ni grid :
 * il faudrait refaire le rendu d'une note une seconde fois, et tenir les deux
 * d'accord.
 *
 * Deux choses que la feuille de style ne peut pas faire seule :
 *
 * - **Le thème sombre ne s'imprime pas.** Le navigateur retire les fonds à
 *   l'impression et garde les encres : un texte presque blanc sur du papier
 *   blanc. Le thème clair se pose donc le temps de l'impression, sur Ctrl+P
 *   comme sur le bouton, et le thème de la personne revient après.
 * - **Une image différée n'est peut-être pas chargée.** `loading="lazy"`
 *   attend qu'on fasse défiler la page : imprimée d'office, la note sortait
 *   avec des cadres vides. Les images se chargent toutes avant le dialogue.
 */

/**
 * Le thème clair pendant l'impression, celui de la personne ensuite.
 *
 * @returns {() => void} de quoi retirer les écouteurs
 */
export function lightWhilePrinting() {
    const root = document.documentElement;
    let wasDark = false;

    const before = () => {
        wasDark = root.classList.contains("dark");
        if (wasDark) root.classList.remove("dark");
        eagerImages(document);
    };
    const after = () => {
        if (wasDark) root.classList.add("dark");
        wasDark = false;
    };

    window.addEventListener("beforeprint", before);
    window.addEventListener("afterprint", after);

    return () => {
        window.removeEventListener("beforeprint", before);
        window.removeEventListener("afterprint", after);
    };
}

function eagerImages(root) {
    const images = [...root.querySelectorAll("img")];
    images.forEach((image) => {
        if ("lazy" === image.getAttribute("loading"))
            image.setAttribute("loading", "eager");
    });

    return images;
}

/**
 * Le dialogue d'impression, une fois chaque image chargée ou tombée en
 * erreur : une image cassée ne doit pas bloquer l'impression du reste.
 *
 * @param {ParentNode} root
 * @param {number} timeout au-delà, on imprime quand même
 */
export async function printWhenReady(root = document, timeout = 5000) {
    const pending = eagerImages(root)
        .filter((image) => !image.complete)
        .map(
            (image) =>
                new Promise((resolve) => {
                    image.addEventListener("load", resolve, { once: true });
                    image.addEventListener("error", resolve, { once: true });
                }),
        );

    await Promise.race([
        Promise.all(pending),
        new Promise((resolve) => setTimeout(resolve, timeout)),
    ]);

    // Après le dessin, sans quoi la première page sort vide. `print` bloque
    // jusqu'à la fermeture du dialogue : rien ne le suit.
    requestAnimationFrame(() => window.print());
}
