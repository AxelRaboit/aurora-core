import { nextTick, ref, watch, onBeforeUnmount } from "vue";
import { filterGraphBySpace, nodeColor } from "./noteGraphFamilies.js";

/**
 * Render a wiki-link graph onto a `<canvas>` using a tiny custom
 * force-directed simulation: nodes repel each other, edges act like
 * springs pulling endpoints together, and a faint center-gravity keeps
 * disconnected nodes from drifting off-screen. Runs for a fixed number
 * of iterations on open (no infinite ticking - once at rest, we just
 * redraw on drag).
 *
 * Ported from Onyx (`resources/js/components/notes/NoteGraph.vue`), the
 * predecessor app this module replaces. Picked over Cytoscape because:
 *   - The cytoscape build was ~140 kB gzip just to draw ~100 dots.
 *   - The default `cose` layout produced cramped, arrow-heavy graphs
 *     that didn't match the Obsidian-flavored look users expect from
 *     a notes app.
 *   - Onyx's renderer is ~150 LoC, easier to tune visually.
 *
 * The composable owns the simulation state, the rAF loop, and the
 * pointer interaction. The SFC binds the returned event handlers onto
 * its canvas and provides a parent with non-zero dimensions.
 *
 * @param {object} deps
 * @param {() => Promise<{ok: boolean, payload: any}>} deps.fetchGraph
 * @param {import('vue').Ref<HTMLCanvasElement|null>} deps.canvasRef
 * @param {string} deps.untitledLabel
 * @param {(id: number|string) => void} deps.onNavigate
 * @param {import('vue').Ref<boolean>} [deps.showRef] Optional reactive
 *   visibility flag. When provided, the composable opens/closes the
 *   simulation automatically as the flag toggles + cleans up on
 *   component unmount - saving the consumer SFC the `watch` + the
 *   `onBeforeUnmount` boilerplate.
 */
export function useNoteGraph({
    fetchGraph,
    canvasRef,
    untitledLabel,
    onNavigate,
    showRef = null,
}) {
    const loading = ref(false);
    const empty = ref(false);
    // The spaces present in the graph, as the server lists them, and the
    // one shown ("" for all of them). Reactive: the screen draws the select
    // and the legend from them.
    const spaces = ref([]);
    const spaceFilter = ref("");

    // Plain (non-reactive) state - drawing happens 60×/sec, reactivity
    // would just churn proxies for no benefit. `allNodes`/`allEdges` hold
    // the whole graph, `nodes`/`edges` what the space filter keeps.
    let allNodes = [];
    let allEdges = [];
    let nodes = [];
    let edges = [];
    let animationFrame = null;
    let dragNode = null;
    let offsetX = 0;
    let offsetY = 0;
    let dragged = false;
    let resizeObserver = null;

    function truncate(text, max) {
        return text.length > max ? `${text.slice(0, max)}…` : text;
    }

    /**
     * Size the canvas backing buffer to its parent's pixel dimensions,
     * scaled by devicePixelRatio so retina/HiDPI screens don't render a
     * blurry upscale. Drawing then happens in CSS-pixel coordinates
     * because we pre-scale the 2D context. CSS width/height stay at
     * the parent's layout size (the canvas is `block w-full h-full`).
     */
    function resizeCanvas() {
        const canvas = canvasRef.value;
        if (!canvas) return false;
        const parent = canvas.parentElement;
        if (!parent) return false;
        const cssWidth = parent.clientWidth;
        const cssHeight = parent.clientHeight;
        if (cssWidth === 0 || cssHeight === 0) return false;
        const dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(cssWidth * dpr);
        canvas.height = Math.round(cssHeight * dpr);
        const context = canvas.getContext("2d");
        // Reset any prior transform before re-applying the DPR scale,
        // otherwise repeated resizes compound it.
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.scale(dpr, dpr);
        return true;
    }

    function canvasCssSize() {
        const canvas = canvasRef.value;
        if (!canvas) return { width: 0, height: 0 };
        const dpr = window.devicePixelRatio || 1;
        return {
            width: canvas.width / dpr,
            height: canvas.height / dpr,
        };
    }

    /**
     * Iterate forces for a fixed number of steps. Each step: apply
     * repulsion + edge attraction + center gravity, integrate
     * velocities with damping, redraw. We stop after `iterations`
     * because the simulation visibly converges within ~60 frames at
     * the tuned constants - letting it run forever would just heat
     * laptops without moving anything.
     */
    function simulate() {
        if (animationFrame) cancelAnimationFrame(animationFrame);
        const iterations = 120;
        let frame = 0;

        function step() {
            if (frame >= iterations) {
                draw();
                return;
            }
            frame++;
            applyForces();
            draw();
            animationFrame = requestAnimationFrame(step);
        }

        step();
    }

    function applyForces() {
        const canvas = canvasRef.value;
        if (!canvas) return;
        const { width, height } = canvasCssSize();
        const nodeMap = Object.fromEntries(
            nodes.map((node) => [node.id, node]),
        );

        // Pairwise repulsion - O(n²) but fine for our note counts (a
        // user with >1000 wiki-linked notes is rare). Magnitude scales
        // with 1/d² so close nodes push hard, far ones barely matter.
        for (let firstIndex = 0; firstIndex < nodes.length; firstIndex++) {
            for (
                let secondIndex = firstIndex + 1;
                secondIndex < nodes.length;
                secondIndex++
            ) {
                const firstNode = nodes[firstIndex];
                const secondNode = nodes[secondIndex];
                let deltaX = firstNode.x - secondNode.x;
                let deltaY = firstNode.y - secondNode.y;
                const distance =
                    Math.sqrt(deltaX * deltaX + deltaY * deltaY) || 1;
                const force = 800 / (distance * distance);
                deltaX = (deltaX / distance) * force;
                deltaY = (deltaY / distance) * force;
                firstNode.vx += deltaX;
                firstNode.vy += deltaY;
                secondNode.vx -= deltaX;
                secondNode.vy -= deltaY;
            }
        }

        // Edge spring - rest length 100, weak constant so visual drift
        // is smooth rather than snappy.
        edges.forEach((edge) => {
            const source = nodeMap[edge.source];
            const target = nodeMap[edge.target];
            if (!source || !target) return;
            const deltaX = target.x - source.x;
            const deltaY = target.y - source.y;
            const distance = Math.sqrt(deltaX * deltaX + deltaY * deltaY) || 1;
            const force = (distance - 100) * 0.01;
            const forceX = (deltaX / distance) * force;
            const forceY = (deltaY / distance) * force;
            source.vx += forceX;
            source.vy += forceY;
            target.vx -= forceX;
            target.vy -= forceY;
        });

        // Center gravity keeps orphan nodes anchored near the middle.
        nodes.forEach((node) => {
            node.vx += (width / 2 - node.x) * 0.001;
            node.vy += (height / 2 - node.y) * 0.001;
        });

        // Integrate with damping; clamp inside the canvas so nodes can
        // never disappear off the edge.
        nodes.forEach((node) => {
            if (node === dragNode) return;
            node.vx *= 0.85;
            node.vy *= 0.85;
            node.x += node.vx;
            node.y += node.vy;
            node.x = Math.max(20, Math.min(width - 20, node.x));
            node.y = Math.max(20, Math.min(height - 20, node.y));
        });
    }

    function draw() {
        const canvas = canvasRef.value;
        const context = canvas?.getContext("2d");
        if (!context || !canvas) return;
        const { width, height } = canvasCssSize();
        const nodeMap = Object.fromEntries(
            nodes.map((node) => [node.id, node]),
        );

        context.clearRect(0, 0, width, height);

        // The theme's muted text colour, read from the canvas (which carries
        // `text-muted`) on every frame: a fixed grey read badly on the dark
        // theme, and the theme can change while the graph is open.
        const mutedColor =
            getComputedStyle(canvas).color || "rgb(107, 114, 128)";

        // Edges - thin and neutral: the colour belongs to the families.
        context.strokeStyle = mutedColor;
        context.globalAlpha = 0.35;
        context.lineWidth = 1;
        edges.forEach((edge) => {
            const source = nodeMap[edge.source];
            const target = nodeMap[edge.target];
            if (!source || !target) return;
            context.beginPath();
            context.moveTo(source.x, source.y);
            context.lineTo(target.x, target.y);
            context.stroke();
        });
        context.globalAlpha = 1;

        const linkedIds = new Set();
        edges.forEach((edge) => {
            linkedIds.add(edge.source);
            linkedIds.add(edge.target);
        });

        nodes.forEach((node) => {
            const hasEdges = linkedIds.has(node.id);

            // A note without links is the same colour, dimmed.
            context.beginPath();
            context.arc(node.x, node.y, hasEdges ? 6 : 4, 0, Math.PI * 2);
            context.fillStyle = node.color;
            context.globalAlpha = hasEdges ? 1 : 0.45;
            context.fill();
            context.globalAlpha = 1;

            context.font = "10px system-ui, sans-serif";
            context.fillStyle = mutedColor;
            context.textAlign = "center";
            context.fillText(
                truncate(node.title || untitledLabel, 20),
                node.x,
                node.y + 16,
            );
        });
    }

    function getMouseNode(event) {
        const canvas = canvasRef.value;
        if (!canvas) return null;
        const rect = canvas.getBoundingClientRect();
        const mouseX = event.clientX - rect.left;
        const mouseY = event.clientY - rect.top;
        return nodes.find((node) => {
            const deltaX = node.x - mouseX;
            const deltaY = node.y - mouseY;
            return deltaX * deltaX + deltaY * deltaY < 100; // ~10px hit radius
        });
    }

    function onMouseDown(event) {
        const node = getMouseNode(event);
        if (!node) return;
        dragNode = node;
        dragged = false;
        const canvas = canvasRef.value;
        const rect = canvas.getBoundingClientRect();
        offsetX = event.clientX - rect.left - node.x;
        offsetY = event.clientY - rect.top - node.y;
        // Resume simulation so neighbours re-arrange around the dragged node.
        simulate();
    }

    function onMouseMove(event) {
        const canvas = canvasRef.value;
        if (!canvas) return;
        if (!dragNode) {
            const node = getMouseNode(event);
            canvas.style.cursor = node ? "pointer" : "default";
            return;
        }
        dragged = true;
        const rect = canvas.getBoundingClientRect();
        dragNode.x = event.clientX - rect.left - offsetX;
        dragNode.y = event.clientY - rect.top - offsetY;
        draw();
    }

    function onMouseUp() {
        dragNode = null;
    }

    function onClick(event) {
        // If the user was dragging, swallow the click so we don't
        // accidentally navigate after re-positioning a node.
        if (dragged) {
            dragged = false;
            return;
        }
        const node = getMouseNode(event);
        if (node) onNavigate(node.id);
    }

    /**
     * Wait until the canvas's parent has non-zero size, then run the
     * given callback. AppModal's enter transition (200ms scale+opacity)
     * means the parent's clientHeight is briefly 0; ResizeObserver
     * notifies us when it settles. The safety timeout keeps us from
     * hanging if a parent never paints.
     */
    function whenSized(callback) {
        const canvas = canvasRef.value;
        if (!canvas) return;
        const parent = canvas.parentElement;
        if (!parent) return;
        if (parent.clientWidth > 0 && parent.clientHeight > 0) {
            callback();
            return;
        }
        if (resizeObserver) resizeObserver.disconnect();
        let done = false;

        // `safety` is declared here, before the function that clears it, and
        // initialised to null so it is genuinely written twice - `const` would
        // force the declaration below `finish`, and then `finish` would name a
        // binding that does not exist yet. The original order only worked
        // because the observer never fires synchronously, which nothing stated.
        let safety = null;

        function finish() {
            if (done) return;
            done = true;
            resizeObserver?.disconnect();
            resizeObserver = null;
            clearTimeout(safety);
            callback();
        }

        safety = setTimeout(finish, 500);
        resizeObserver = new ResizeObserver(() => {
            if (parent.clientWidth > 0 && parent.clientHeight > 0) finish();
        });
        resizeObserver.observe(parent);
    }

    /**
     * Keep only the chosen space's notes and the links between them, from
     * the graph already loaded. The node objects are the same, so the notes
     * that stay keep their place and the simulation only settles the rest.
     */
    function applySpaceFilter() {
        const filtered = filterGraphBySpace(
            allNodes,
            allEdges,
            spaceFilter.value,
        );
        nodes = filtered.nodes;
        edges = filtered.edges;
        if (canvasRef.value) simulate();
    }

    watch(spaceFilter, () => {
        if (allNodes.length > 0) applySpaceFilter();
    });

    async function open() {
        loading.value = true;
        empty.value = false;
        allNodes = [];
        allEdges = [];
        nodes = [];
        edges = [];
        spaces.value = [];
        spaceFilter.value = "";

        const { ok, payload } = await fetchGraph();
        if (!ok) {
            loading.value = false;
            empty.value = true;
            return;
        }

        const raw = payload?.nodes ?? [];
        if (raw.length === 0) {
            empty.value = true;
            loading.value = false;
            return;
        }

        loading.value = false;

        // The spaces first, and one render: the select they bring above the
        // canvas takes height from it, and the canvas must be measured after
        // that, or its drawing would be stretched and its clicks offset.
        spaces.value = payload.spaces ?? [];
        await nextTick();

        whenSized(() => {
            if (!resizeCanvas()) return;
            const { width, height } = canvasCssSize();

            allNodes = raw.map((rawNode) => ({
                id: rawNode.id,
                title: rawNode.title,
                color: nodeColor(rawNode),
                spaceId: rawNode.spaceId ?? null,
                x: Math.random() * (width - 80) + 40,
                y: Math.random() * (height - 80) + 40,
                vx: 0,
                vy: 0,
            }));
            allEdges = (payload.edges ?? []).slice();

            applySpaceFilter();
        });
    }

    function close() {
        if (animationFrame) {
            cancelAnimationFrame(animationFrame);
            animationFrame = null;
        }
        if (resizeObserver) {
            resizeObserver.disconnect();
            resizeObserver = null;
        }
        allNodes = [];
        allEdges = [];
        nodes = [];
        edges = [];
        dragNode = null;
        // Clear the canvas so the next open() starts from a blank slate
        // instead of flashing the previous graph during the modal
        // re-entry transition.
        const canvas = canvasRef.value;
        const context = canvas?.getContext("2d");
        if (context && canvas) {
            const { width, height } = canvasCssSize();
            context.clearRect(0, 0, width, height);
        }
    }

    if (showRef) {
        watch(showRef, (visible) => {
            if (visible) open();
            else close();
        });
        onBeforeUnmount(close);
    }

    return {
        loading,
        empty,
        spaces,
        spaceFilter,
        open,
        close,
        onMouseDown,
        onMouseMove,
        onMouseUp,
        onClick,
    };
}
