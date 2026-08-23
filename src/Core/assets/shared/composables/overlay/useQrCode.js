import { ref } from "vue";

/**
 * Holds the QR-code modal state for any list view: the currently displayed
 * `item` and the `open` / `close` helpers. Pair with `AppQrCodeModal` -
 * pass `qrItem` to its `item` prop and wire `closeQr` to its `close` event.
 *
 * Why a composable instead of inlined refs in each App.vue: same reason
 * the rest of Aurora's list pages do it - business state (even thin) lives
 * outside the template, so each consumer (Media, GED, future modules)
 * shares one tested implementation.
 */
export function useQrCode() {
    const qrItem = ref(null);

    function openQr(item) {
        qrItem.value = item;
    }

    function closeQr() {
        qrItem.value = null;
    }

    return { qrItem, openQr, closeQr };
}
