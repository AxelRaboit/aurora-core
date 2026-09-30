/**
 * Today's date as `YYYY-MM-DD`, in the reader's own time zone.
 *
 * `new Date().toISOString().slice(0, 10)` reads the date in UTC: between
 * midnight and two in the morning in Paris it gave yesterday, and a contract
 * signed at one in the morning was dated the day before, in its PDF.
 *
 * @param {Date} [date]
 * @returns {string}
 */
export function localIsoDate(date = new Date()) {
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${date.getFullYear()}-${month}-${day}`;
}
