/*
// https://chatgpt.com/share/6978c4f1-fe14-8001-81e0-516b541692ea

<label for="customer">Customer</label>
<input
    id="customer"
    name="customer_label"
    type="text"
    list="customer-suggestions"
    autocomplete="off"
    aria-describedby="customer-help"
/>
<div id="customer-help">Type at least 2 characters, then pick a suggestion.</div>

<datalist id="customer-suggestions"></datalist>

<input type="hidden" id="customer_id" name="customer_id">
<input type="text" id="email" name="email">
<input type="text" id="phone" name="phone">

<script type="module">
    import { RecordSuggester } from "./suggester.js";
    new RecordSuggester("#customer", { searchUrl: "/api/customers" });

    document.addEventListener("record-picked", async (e) => {
      const { record } = e.detail;

      document.querySelector("#customer_id").value = record.id;

      // optionally fetch details by id
      const r = await fetch(`/api/customers/${encodeURIComponent(record.id)}`, {
        headers: { Accept: "application/json" },
      });
      if (!r.ok) return;
      const d = await r.json();

      document.querySelector("#email").value = d.email ?? "";
      document.querySelector("#phone").value = d.phone ?? "";
    });
</script>
*/

// suggester.js (ESM)

const _debounce = (fn, ms = 200) => {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
};

export class RecordSuggester {
    #input;
    #datalist;
    #aborter = null;
    #cacheByLabel = new Map();
    #minChars;
    #limit;
    #searchUrl;

    constructor(input, { searchUrl, minChars = 2, limit = 15 } = {}) {
        this.#input = typeof input === "string" ? document.querySelector(input) : input;
        if (!this.#input) throw new Error("Input not found");

        this.#searchUrl = searchUrl;
        this.#minChars = minChars;
        this.#limit = limit;

        // Ensure datalist exists and is connected
        const listId = this.#input.getAttribute("list") || `${this.#input.id || "s"}-list`;
        this.#input.setAttribute("list", listId);

        this.#datalist = document.getElementById(listId);
        if (!this.#datalist) {
            this.#datalist = document.createElement("datalist");
            this.#datalist.id = listId;
            this.#input.insertAdjacentElement("afterend", this.#datalist);
        }

        this.#wire();
    }

    #wire() {
        const onInput = _debounce(() => this.#handleInput(), 200);
        this.#input.addEventListener("input", onInput);
        this.#input.addEventListener("change", () => this.#handleChange());
    }

    async #handleInput() {
        const q = this.#input.value.trim();
        if (q.length < this.#minChars) {
            this.#datalist.replaceChildren();
            this.#cacheByLabel.clear();
            return;
        }

        try {
            const items = await this.#search(q);
            this.#cacheByLabel.clear();

            this.#datalist.replaceChildren(
                ...items.slice(0, this.#limit).map((item) => {
                    this.#cacheByLabel.set(item[0], item);
                    const opt = document.createElement("option");
                    // stringify json here
                    opt.value = JSON.stringify(item);
                    return opt;
                })
            );
        } catch (e) {
            if (e.name !== "AbortError") console.error(e);
        }
    }

    async #handleChange() {
        const label = this.#input.value.trim();
        const picked = this.#cacheByLabel.get(label);
        if (!picked) return;

        // Emit an event so your form-filling logic can be outside the widget
        this.#input.dispatchEvent(
            new CustomEvent("record-picked", { bubbles: true, detail: { record: picked } })
        );
    }

    async #search(q) {
        this.#aborter?.abort();
        this.#aborter = new AbortController();

        const url = new URL(this.#searchUrl + encodeURIComponent(q), window.location.origin);
        // url.searchParams.set("q", q);

        const res = await fetch(url, {
            signal: this.#aborter.signal,
            headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error(`Search failed: ${res.status}`);
        return res.json(); // [{id,label,...}]
    }
}
