import { readonly, ref } from "vue";

/**
 * Ouvert ou replié : un seul choix pour tous les encarts « Comment ça marche ».
 *
 * Replier un encart les replie tous, sur l'écran ouvert comme sur les autres,
 * et le choix est gardé dans le navigateur ; le déplier les rouvre tous. Le
 * lecteur qui connaît l'outil ne referme pas vingt encarts un par un, celui
 * qui le découvre les garde ouverts.
 *
 * L'état vit au niveau du module, donc partagé par toutes les instances de la
 * page, et suivi d'un onglet à l'autre par l'événement `storage`. Le stockage
 * peut manquer (navigation privée) : les encarts restent alors d'accord sur la
 * page, sans mémoire d'une visite à l'autre.
 */
const STORAGE_KEY = "aurora.guides.open";

function read() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return null === value ? null : "1" === value;
    } catch {
        return null;
    }
}

/** `null` tant que le lecteur n'a rien choisi. */
const choice = ref(read());

if ("undefined" !== typeof window) {
    window.addEventListener("storage", (event) => {
        if (STORAGE_KEY === event.key) choice.value = read();
    });
}

function remember(open) {
    choice.value = open;
    try {
        window.localStorage.setItem(STORAGE_KEY, open ? "1" : "0");
    } catch {
        // Sans stockage, les encarts restent d'accord sur la page, sans mémoire.
    }
}

/** Pour les tests : oublier le choix, comme un navigateur neuf. */
function forget() {
    choice.value = null;
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Rien à oublier.
    }
}

export function useGuidePreference() {
    return { choice: readonly(choice), remember, forget };
}
