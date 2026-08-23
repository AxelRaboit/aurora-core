<script>
import { defineComponent } from "vue";

/**
 * Renders its slot, and keeps rendering the last version once told to stop.
 *
 * This exists for one reason: a modal's panel outlives the state that filled it.
 * `AppModal` holds its panel for the length of a leave transition, but the slot
 * inside it is rendered by the caller - so the moment a caller does
 * `openThing = null`, its own `v-if` goes false and the body empties while the
 * panel is still fading. Every modal in the application whose content is guarded
 * by the same value that drives `show` flashes empty on the way out.
 *
 * Holding the vnodes freezes the DOM instead. Returning the same vnode objects on
 * a later render is a no-op patch - Vue's `patch()` returns immediately when the
 * old and new vnodes are the same object - so nothing is re-created and nothing
 * is re-read. The held vnodes never outlive their mount: `AppModal` unmounts the
 * whole wrapper when the transition ends, which takes this component with it, and
 * the next open starts from a fresh instance with nothing held.
 *
 * A render function rather than a template, because a template cannot return the
 * same vnodes twice - that is the entire mechanism.
 */
export default defineComponent({
    name: "AppRetainedSlot",
    props: {
        /** True renders the slot live; false holds whatever it rendered last. */
        live: { type: Boolean, default: true },
    },
    setup(props, { slots }) {
        let held = null;

        return () => {
            if (props.live) {
                held = slots.default?.();
            }
            return held;
        };
    },
});
</script>
