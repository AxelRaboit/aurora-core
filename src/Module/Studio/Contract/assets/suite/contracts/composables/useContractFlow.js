import { useI18n } from "vue-i18n";
import {
    Ban,
    BellRing,
    CalendarX,
    Copy,
    Eye,
    FileDown,
    FilePen,
    FilePlus2,
    FileSignature,
    FileX2,
    Lock,
    Mail,
    PenLine,
    Pencil,
    Send,
    Trash2,
} from "lucide-vue-next";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";

const PREFIX = "suite.studio.contracts.flow";

/**
 * What can be done with a contract, and which of it comes next.
 *
 * One answer for the list and for the contract's own screen. They used to
 * build their menus separately, and disagreed: the list offered « Envoyer au
 * client » on a contract already concluded, refused or expired, while the
 * contract's screen offered nothing at all until it was countersigned.
 *
 * `next` is the step a reader came to take, shown as the page's main button;
 * `others` is everything else that is allowed now. Both depend on the status
 * and on the reader's rights, and nothing is offered that the server would
 * refuse.
 *
 * An action is `{ key, title, description, icon, color }`; the caller decides
 * what each key does (a modal, a request, a navigation).
 */
export function useContractFlow({ linkDays = 30 } = {}) {
    const { t } = useI18n();
    const { can } = usePrivileges();

    const ICONS = {
        open: FileSignature,
        edit: Pencil,
        adapt: FilePen,
        preview: Eye,
        freeze: Lock,
        send: Mail,
        resend: Send,
        remind: BellRing,
        revoke: Ban,
        countersign: PenLine,
        download: FileDown,
        export: FileDown,
        amend: FilePlus2,
        terminate: CalendarX,
        cancel: FileX2,
        duplicate: Copy,
        delete: Trash2,
    };

    const COLORS = {
        freeze: "amber",
        revoke: "amber",
        terminate: "amber",
        cancel: "rose",
        delete: "rose",
    };

    function action(key) {
        return {
            key,
            title: t(`${PREFIX}.actions.${key}.title`),
            // `days` for the two that hand out an address; ignored by the rest.
            description: t(`${PREFIX}.actions.${key}.description`, { days: linkDays }),
            icon: ICONS[key],
            color: COLORS[key],
        };
    }

    /**
     * The keys, before rights: what the status allows.
     *
     * @returns {{ next: string|null, others: Array<string> }}
     */
    function keysFor(contract) {
        const concluded = "countersigned" === contract.status;
        const running =
            concluded && !contract.amends && !contract.termination?.isEffective;

        switch (contract.status) {
            case "draft":
                return {
                    next: "freeze",
                    // « Adapter le texte » sits next to « Modifier » : one
                    // changes the contract's choices, the other its wording,
                    // for this client alone.
                    others: [
                        "edit",
                        "adapt",
                        "preview",
                        "export",
                        "duplicate",
                        "delete",
                    ],
                };
            case "sealed":
                return {
                    next: "send",
                    others: ["export", "cancel", "duplicate", "delete"],
                };
            case "sent":
            case "opened":
                return {
                    next: "remind",
                    others: ["revoke", "export", "duplicate"],
                };
            case "refused":
            case "expired":
            case "revoked":
                return {
                    next: "resend",
                    others: ["cancel", "duplicate", "export", "delete"],
                };
            case "signed_by_customer":
                return { next: "countersign", others: ["export"] };
            case "countersigned":
                return {
                    next: contract.hasPdf ? "download" : "export",
                    others: [
                        ...(running ? ["amend"] : []),
                        ...(running && !contract.termination
                            ? ["terminate"]
                            : []),
                        "delete",
                    ],
                };
            case "cancelled":
                return {
                    next: null,
                    others: ["duplicate", "export", "delete"],
                };
            default:
                return { next: null, others: ["export"] };
        }
    }

    const PRIVILEGE = {
        edit: "studio.contracts.edit",
        adapt: "studio.contracts.edit",
        freeze: "studio.contracts.edit",
        cancel: "studio.contracts.edit",
        terminate: "studio.contracts.edit",
        send: "studio.contracts.send",
        resend: "studio.contracts.send",
        remind: "studio.contracts.send",
        revoke: "studio.contracts.send",
        countersign: "studio.contracts.countersign",
        amend: "studio.contracts.create",
        duplicate: "studio.contracts.create",
        delete: "studio.contracts.delete",
    };

    function allowed(contract, key) {
        if (PRIVILEGE[key] && !can(PRIVILEGE[key])) return false;

        // A sealed contract goes only once its retention has run out; the
        // server says when, and the menu does not offer what it would refuse.
        if ("delete" === key) return true === contract.isDeletable;

        return true;
    }

    /**
     * @param {object} contract as the server serializes it
     * @param {object} [options]
     * @param {boolean} [options.list] adds « Ouvrir » first, for a row
     * @returns {{ next: object|null, others: Array<object> }}
     */
    function flowOf(contract, { list = false } = {}) {
        const keys = keysFor(contract);
        const next =
            keys.next && allowed(contract, keys.next)
                ? action(keys.next)
                : null;
        const others = keys.others
            .filter((key) => allowed(contract, key))
            .map(action);

        // On the contract's own screen the document is already on the page,
        // so « Aperçu » has nothing to add there.
        const shown = list
            ? [action("open"), ...others]
            : others.filter((each) => "preview" !== each.key);

        return { next, others: shown };
    }

    /**
     * One sentence saying where the contract stands, with the dates that
     * matter: sent to whom and until when, signed on which day.
     *
     * @returns {string}
     */
    function summaryOf(contract, formatDate) {
        const date = (value) => (value ? formatDate(value) : "");

        if (contract.termination) {
            return contract.termination.isEffective
                ? t(`${PREFIX}.summary.terminated`, {
                      date: date(contract.termination.effectiveAt),
                  })
                : t(`${PREFIX}.summary.notice`, {
                      date: date(contract.termination.effectiveAt),
                  });
        }

        // Without a link, the dates of one cannot be quoted: say where it
        // stands and no more, rather than « envoyé le . ».
        if (["sent", "opened"].includes(contract.status) && !contract.link) {
            return t(`${PREFIX}.summary.waiting`);
        }

        if ("countersigned" === contract.status && !contract.hasPdf) {
            return t(`${PREFIX}.summary.concluded_no_pdf`);
        }

        const customerSigned = (contract.signatures ?? []).find(
            (each) => "customer" === each.role,
        );

        const params = {
            sealed: { date: date(contract.frozenAt) },
            refused: { date: date(contract.refusal?.refusedAt) },
            sent: {
                email:
                    contract.link?.recipientEmail ??
                    contract.customerEmail ??
                    "",
                date: date(contract.link?.sentAt),
                until: date(contract.link?.expiresAt),
            },
            opened: {
                date: date(contract.link?.firstOpenedAt),
                until: date(contract.link?.expiresAt),
            },
            signed_by_customer: { date: date(customerSigned?.signedAt) },
        };

        return t(
            `${PREFIX}.summary.${contract.status}`,
            params[contract.status] ?? {},
        );
    }

    return { flowOf, summaryOf, keysFor };
}
