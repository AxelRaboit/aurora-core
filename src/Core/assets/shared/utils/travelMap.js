/**
 * The travel map - `[data-travel-map]`.
 *
 * Draws the stops read from `data-travel-stops` with Leaflet, on the public
 * OpenStreetMap tiles: no key, no account. Each marker opens a bubble with
 * the photo taken there, when there is one.
 * The library is only loaded on a page that has a map.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_travel_map.html.twig
 */
const SELECTOR = "[data-travel-map]";

function popupFor(stop) {
    if (!stop.photo) {
        return stop.label;
    }

    const photo = document.createElement("img");
    photo.src = stop.photo.url;
    photo.alt = "";
    photo.style.cssText =
        "display:block;width:180px;height:135px;object-fit:cover;border-radius:6px;margin-bottom:6px";

    const wrapper = document.createElement("div");
    wrapper.append(photo, document.createTextNode(stop.label));

    return wrapper;
}

/**
 * A dot in the accent colour, drawn rather than Leaflet's default pin.
 *
 * The default pin is an image Leaflet looks for next to the page, by a
 * relative address: on `/fr/page/…` it asked for `/fr/page/marker-icon.png`,
 * got a 404, and every stop showed as a broken image. Drawn, there is no
 * file to find, and the marker follows the theme.
 */
function stopIcon(Leaflet) {
    return Leaflet.divIcon({
        className: "",
        html: '<span class="block h-4 w-4 rounded-full border-2 border-white bg-accent-500 shadow-md"></span>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
        popupAnchor: [0, -10],
    });
}

async function arm() {
    const maps = [...document.querySelectorAll(SELECTOR)];

    if (0 === maps.length) {
        return;
    }

    const Leaflet = (await import("leaflet")).default;

    for (const mapElement of maps) {
        let stops = [];

        try {
            stops = JSON.parse(mapElement.dataset.travelStops ?? "[]");
        } catch {
            continue;
        }

        if (0 === stops.length) {
            continue;
        }

        const map = Leaflet.map(mapElement, { scrollWheelZoom: false });
        Leaflet.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "© OpenStreetMap",
            maxZoom: 18,
        }).addTo(map);

        const markers = stops.map((stop) =>
            Leaflet.marker([stop.lat, stop.lng], { icon: stopIcon(Leaflet) })
                .bindPopup(popupFor(stop))
                .addTo(map),
        );

        if (1 === markers.length) {
            map.setView([stops[0].lat, stops[0].lng], 11);
        } else {
            map.fitBounds(Leaflet.featureGroup(markers).getBounds().pad(0.2));
        }
    }
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", () => void arm());
} else {
    void arm();
}
