import { createI18n } from "vue-i18n";

/**
 * Build a minimal vue-i18n instance for component tests.
 * Messages are merged on top of a small default that covers the translation
 * keys shared by many admin components (common, locales, posts labels).
 */
export function createTestI18n(messages = {}, locale = "fr") {
    return createI18n({
        legacy: false,
        locale,
        fallbackLocale: locale,
        missingWarn: false,
        fallbackWarn: false,
        messages: {
            [locale]: deepMerge(baseMessages, messages),
        },
    });
}

const baseMessages = {
    shared: {
        common: {
            cancel: "Annuler",
            save: "Enregistrer",
            confirm: "Confirmer",
            error: "Erreur",
            deleted: "Supprimé.",
            close: "Fermer",
            loadMore: "Charger plus",
            no_options: "Aucune option",
            no_result: "Aucun résultat",
            pagination: "Page {page} sur {totalPages}",
            select_placeholder: "Sélectionner…",
            add_tag_hint: "Ajouter un tag",
        },
        drop_zone: {
            cta: "Cliquez ou déposez un fichier ici",
            drop: "Déposez pour téléverser",
            uploading: "Téléversement…",
        },
        media: {
            change: "Changer",
            choose: "Choisir",
            remove: "Retirer",
            upload: "Parcourir",
            uploading: "Import en cours",
            upload_failed: "L'import a échoué.",
        },
        pagination: {
            previous: "Précédent",
            next: "Suivant",
        },
        password: {
            criteria: {
                length: "Au moins 8 caractères",
                uppercase: "Une majuscule",
                number: "Un chiffre",
                special: "Un caractère spécial",
            },
        },
        locales: {
            fr: "Français",
            en: "English",
            es: "Español",
            de: "Deutsch",
        },
    },
    backend: {
        settings: {
            saved: "Réglages sauvegardés.",
            confirmPasswordInvalid: "Mot de passe incorrect.",
            cascadeLocked: "Activez d'abord « {parent} ».",
        },
    },
};

function deepMerge(target, source) {
    const out = { ...target };
    for (const [key, value] of Object.entries(source)) {
        if (value && typeof value === "object" && !Array.isArray(value)) {
            out[key] = deepMerge(out[key] ?? {}, value);
        } else {
            out[key] = value;
        }
    }
    return out;
}
