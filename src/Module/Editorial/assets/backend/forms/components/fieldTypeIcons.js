import {
    AlignLeft,
    AtSign,
    CalendarDays,
    ChevronsUpDown,
    CircleDot,
    Hash,
    Phone,
    SquareCheck,
    Type,
} from "lucide-vue-next";

/**
 * Une icône par type de question, la même partout : dans le choix du type,
 * sur chaque ligne de la liste, dans le panneau. On reconnaît une question à
 * sa forme avant de lire son libellé.
 */
export const FIELD_TYPE_ICONS = {
    text: Type,
    email: AtSign,
    textarea: AlignLeft,
    select: ChevronsUpDown,
    checkbox: SquareCheck,
    radio: CircleDot,
    number: Hash,
    date: CalendarDays,
    tel: Phone,
};

export const iconForType = (type) => FIELD_TYPE_ICONS[type] ?? Type;
