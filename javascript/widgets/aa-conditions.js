// <aa-conditions>: the rows of numbered conditions that AA\Search\ConditionsInput prints - a number, one
// field or more, an operator and a value in plain form fields. The element only adds rows and removes them:
// [data-add] puts in the row of the <template> under the next number, [data-add-field] another field select
// into its row, [data-remove] takes its row away. Attribute: first - the lowest number a new row gets.
// A number is never given twice in one editing and the rows left keep theirs: calls address a condition
// by its number.

/** takes the place of the number in the <template> row */
export const NUMBER = '__N__';

/** the number for a new row: above every number the element ever had, and not below the first one
 *  @param {number[]} used @param {number} first @returns {number} */
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
            // also the numbers of rows removed since - a new row never takes the number of one just removed
            this.used = [...this.querySelectorAll(':scope > [data-number]')].map((row) => Number(row.dataset.number));
            this.addEventListener('click', (event) => {
                const button = event.target.closest('button');
                if (!button || !this.contains(button)) {
                    return;
                }
                if (button.hasAttribute('data-add')) {
                    this.addRow();
                } else if (button.hasAttribute('data-add-field')) {
                    this.addField(button);
                } else if (button.hasAttribute('data-remove')) {
                    button.closest('[data-number]')?.remove();
                }
            });
        }

        addRow() {
            const template = this.querySelector(':scope > template');
            const number = nextNumber(this.used, Number(this.getAttribute('first') ?? 1));
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
