import { SOCIAL_NETWORKS, socialNetworkSvg } from "./socialNetworks.js";

/**
 * The accounts a brand is found on: one row per network, its mark, the
 * handle as it appears there, and the address when there is one. The page
 * draws each row with the network's colours (`BlocksRenderer::renderSocials`).
 */
export default class SocialsBlock {
    #wrapper = null;
    #items;
    #labels;

    static get toolbox() {
        return {
            title: "Social networks",
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/></svg>',
        };
    }

    constructor({ data, config = {} }) {
        const known = SOCIAL_NETWORKS.map(({ value }) => value);
        const items = Array.isArray(data.items) ? data.items : [];
        this.#items = items
            .filter((item) => item && known.includes(item.network))
            .map((item) => ({
                network: item.network,
                handle: String(item.handle ?? ""),
                url: String(item.url ?? ""),
            }));
        if (0 === this.#items.length)
            this.#items.push({ network: "instagram", handle: "", url: "" });
        this.#labels = {
            handle: config.handlePlaceholder ?? "@handle",
            url: config.urlPlaceholder ?? "https://…",
            add: config.addLabel ?? "Add a network",
            remove: config.removeLabel ?? "Remove",
        };
    }

    render() {
        this.#wrapper = document.createElement("div");
        this.#wrapper.className = "socials-block";
        this.#rebuild();

        return this.#wrapper;
    }

    #rebuild() {
        this.#wrapper.innerHTML = "";
        this.#items.forEach((item, index) =>
            this.#wrapper.appendChild(this.#createRow(item, index)),
        );

        const add = document.createElement("button");
        add.type = "button";
        add.className = "socials-block__add";
        add.textContent = `+ ${this.#labels.add}`;
        add.addEventListener("click", () => {
            this.#items.push({ network: "instagram", handle: "", url: "" });
            this.#rebuild();
        });
        this.#wrapper.appendChild(add);
    }

    #createRow(item, index) {
        const row = document.createElement("div");
        row.className = `socials-block__row social-list__item--${item.network}`;

        // The network is picked from its marks, the way a callout picks its
        // icon: no native select in the editor, and the mark is what one
        // recognises anyway.
        const networks = document.createElement("div");
        networks.className = "socials-block__networks";
        SOCIAL_NETWORKS.forEach(({ value, label }) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = `socials-block__network social-list__item--${value}${value === item.network ? " socials-block__network--active" : ""}`;
            button.title = label;
            button.setAttribute("aria-label", label);
            button.setAttribute(
                "aria-pressed",
                value === item.network ? "true" : "false",
            );
            button.innerHTML = `<span class="social-list__icon">${socialNetworkSvg(value)}</span>`;
            button.addEventListener("click", () => {
                item.network = value;
                this.#rebuild();
            });
            networks.appendChild(button);
        });
        row.appendChild(networks);

        row.appendChild(this.#createInput(item, "handle", this.#labels.handle));
        row.appendChild(this.#createInput(item, "url", this.#labels.url));

        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "socials-block__remove";
        remove.title = this.#labels.remove;
        remove.setAttribute("aria-label", this.#labels.remove);
        remove.innerHTML = "&times;";
        remove.addEventListener("click", () => {
            this.#items.splice(index, 1);
            if (0 === this.#items.length)
                this.#items.push({ network: "instagram", handle: "", url: "" });
            this.#rebuild();
        });
        row.appendChild(remove);

        return row;
    }

    #createInput(item, key, placeholder) {
        const input = document.createElement("input");
        input.type = "text";
        input.className = `socials-block__${key}`;
        input.placeholder = placeholder;
        input.value = item[key];
        input.addEventListener("input", () => {
            item[key] = input.value;
        });
        // Editor.js reads Enter and Backspace as block commands; inside a
        // field they are typing.
        input.addEventListener("keydown", (event) => event.stopPropagation());

        return input;
    }

    save() {
        return {
            items: this.#items
                .map((item) => ({
                    network: item.network,
                    handle: item.handle.trim(),
                    url: item.url.trim(),
                }))
                .filter((item) => "" !== item.handle),
        };
    }

    validate(data) {
        return Array.isArray(data.items) && data.items.length > 0;
    }
}
