// <aa-conditions>: the rows of named conditions that AA\Search\ConditionsInput prints - a name, one
// field or more, an operator and a value in plain form fields. The element only adds rows and removes them:
// [data-aa-add] puts in the row of the <template> named by the next number, [data-aa-add-field] another field
// select into its row, [data-aa-remove] takes its row away. Attribute: first - the lowest number a new row gets.
// A number is never given twice in one editing, also after its row was removed or renamed: calls address
// a condition by its name.

/** in the <template> row: takes the place of the key of the row and of its name */
export const NUMBER = '__N__';

/** the number for a new row: above every number the element ever had, and not below the first one
 *  @param {number[]} used names of the rows as numbers - NaN for a name of letters @param {number} first @returns {number} */
export const nextNumber = (used, first) => Math.max(first - 1, ...used.filter(Number.isFinite)) + 1;

/** the text with the placeholder of the template row replaced by the number */
export const numbered = (text, number) => text.split(NUMBER).join(String(number));

if (globalThis.customElements && !customElements.get('aa-conditions')) {
    customElements.define('aa-conditions', class extends HTMLElement {
        connectedCallback() {
            if (this.wired) {
                return;
            }
            this.wired = true;
            // the numbers the rows came with or got here - kept also when a row is removed or renamed
            this.used = this.names();
            this.addEventListener('click', (event) => {
                const button = event.target.closest('button');
                if (!button || !this.contains(button)) {
                    return;
                }
                if (button.hasAttribute('data-aa-add')) {
                    this.addRow();
                } else if (button.hasAttribute('data-aa-add-field')) {
                    this.addField(button);
                } else if (button.hasAttribute('data-aa-remove')) {
                    button.closest('.aa-conditions-row')?.remove();
                }
            });
        }

        /** the names in the rows now, as numbers */
        names() {
            return [...this.querySelectorAll(':scope > .aa-conditions-row input[data-aa-name]')].map((input) => (/^\d+$/.test(input.value) ? Number(input.value) : NaN));
        }

        addRow() {
            const template = this.querySelector(':scope > template');
            const number = nextNumber([...this.used, ...this.names()], Number(this.getAttribute('first') ?? 1));
            this.used.push(number);
            const holder = this.ownerDocument.createElement('div');
            holder.innerHTML = numbered(template.innerHTML, number);
            const row = holder.firstElementChild;
            template.before(row);
            row.querySelector('select')?.focus();
        }

        addField(button) {
            const select = button.parentElement.querySelector('select').cloneNode(true);
            select.value = '';
            button.before(select);
            select.focus();
        }
    });
}
