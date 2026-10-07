/**
 * The shapes a free slide can draw, as paths in a 100 by 100 box.
 *
 * **Drawn by a mask, filled by a background.** A shape is a box whose paint is
 * a CSS background - a colour or a gradient, exactly what the paint field
 * produces - cut by an SVG of its own outline used as a mask, stretched to the
 * box. An SVG `fill` cannot take a CSS gradient, and a `clip-path` cannot take
 * a curve that stretches with its box: the mask takes both, and the shadow,
 * drawn around the box by a `drop-shadow`, follows the cut.
 *
 * The outline, when a stroke is asked for, is the same path drawn again on top
 * with `vector-effect: non-scaling-stroke`, so a stretched star keeps an even
 * line rather than one twice as thick on its long sides.
 *
 * The rectangle and the ellipse are not here: they are boxes with a radius,
 * which a border draws better than any path, and the line is a bar with
 * heads. The list of names is `FreeSlideNormalizer::SHAPES`, sent to the
 * editor, so a shape missing from here draws as a rectangle rather than
 * nothing.
 */
const polygon = (points) => `M${points.trim().split(/\s+/).join(" L")} Z`;

export const PATHS = {
    triangle: polygon("50,0 100,100 0,100"),
    right_triangle: polygon("0,0 100,100 0,100"),
    diamond: polygon("50,0 100,50 50,100 0,50"),
    pentagon: polygon("50,0 100,38 81,100 19,100 0,38"),
    hexagon: polygon("25,0 75,0 100,50 75,100 25,100 0,50"),
    octagon: polygon("30,0 70,0 100,30 100,70 70,100 30,100 0,70 0,30"),
    star: polygon("50,0 61,35 98,35 68,57 79,91 50,70 21,91 32,57 2,35 39,35"),
    star4: polygon("50,0 62,38 100,50 62,62 50,100 38,62 0,50 38,38"),
    heart: "M50,95 C22,74 0,56 0,31 C0,13 13,3 28,3 C39,3 46,9 50,17 C54,9 61,3 72,3 C87,3 100,13 100,31 C100,56 78,74 50,95 Z",
    cross: polygon(
        "35,0 65,0 65,35 100,35 100,65 65,65 65,100 35,100 35,65 0,65 0,35 35,35",
    ),
    parallelogram: polygon("25,0 100,0 75,100 0,100"),
    trapezoid: polygon("20,0 80,0 100,100 0,100"),
    arrow_right: polygon("0,30 60,30 60,0 100,50 60,100 60,70 0,70"),
    arrow_left: polygon("100,30 40,30 40,0 0,50 40,100 40,70 100,70"),
    chevron: polygon("0,0 70,0 100,50 70,100 0,100 30,50"),
    speech: "M8,0 H92 Q100,0 100,8 V62 Q100,70 92,70 H42 L20,100 L26,70 H8 Q0,70 0,62 V8 Q0,0 8,0 Z",
    ring: "M50,0 A50,50 0 1,0 50,100 A50,50 0 1,0 50,0 Z M50,24 A26,26 0 1,1 50,76 A26,26 0 1,1 50,24 Z",
};

/** The two shapes drawn as boxes, with the radius each implies. */
export const BOXES = { rect: null, ellipse: "50%" };

/**
 * The mask image for a shape, stretched to its box.
 *
 * `evenodd` so the ring keeps its hole; it changes nothing for the others,
 * none of which crosses itself.
 */
export function maskImage(shape) {
    const path = PATHS[shape];

    if (!path) return null;

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path fill-rule="evenodd" d="${path}"/></svg>`;

    return `url("data:image/svg+xml;utf8,${encodeURIComponent(svg)}")`;
}
