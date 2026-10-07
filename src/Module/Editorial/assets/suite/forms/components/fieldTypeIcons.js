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
 * One icon per question type, the same everywhere: in the type picker, on
 * each row of the list, in the panel. A question is recognised by its shape
 * before its label is read.
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
