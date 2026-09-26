/**
 * La carte de voyage - `[data-travel-map]`.
 *
 * Dessine les étapes lues dans `data-travel-stops` avec Leaflet, sur les
 * tuiles publiques d'OpenStreetMap : aucune clé, aucun compte. Chaque
 * marqueur ouvre une bulle avec la photo prise là, quand il y en a une.
 * La bibliothèque n'est chargée que sur une page qui a une carte.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_travel_map.html.twig
 */
const SELECTOR = "[data-travel-map]";

function popupFor(stop) {
    if (!stop.photo) {
        return stop.label;
    }

    const img = document.createElement("img");
    img.src = stop.photo.url;
    img.alt = "";
    img.style.cssText =
        "display:block;width:180px;height:135px;object-fit:cover;border-radius:6px;margin-bottom:6px";

    const wrapper = document.createElement("div");
    wrapper.append(img, document.createTextNode(stop.label));

    return wrapper;
}

async function arm() {
    const maps = [...document.querySelectorAll(SELECTOR)];

    if (0 === maps.length) {
        return;
    }

    const L = (await import("leaflet")).default;

    for (const el of maps) {
        let stops = [];

        try {
            stops = JSON.parse(el.dataset.travelStops ?? "[]");
        } catch {
            continue;
        }

        if (0 === stops.length) {
            continue;
        }

        const map = L.map(el, { scrollWheelZoom: false });
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "© OpenStreetMap",
            maxZoom: 18,
        }).addTo(map);

        const markers = stops.map((stop) =>
            L.marker([stop.lat, stop.lng]).bindPopup(popupFor(stop)).addTo(map),
        );

        if (1 === markers.length) {
            map.setView([stops[0].lat, stops[0].lng], 11);
        } else {
            map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2));
        }
    }
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", () => void arm());
} else {
    void arm();
}
