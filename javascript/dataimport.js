/*
 * The import detail page (admin/se_dataimport.php): asks before a form marked data-aa-confirm is sent,
 * and follows a running run - while the page is visible it asks for the run's progress every 5 s and,
 * when it differs from what the page shows, replaces the sections marked data-aa-live by freshly
 * rendered ones. Nothing of the progress JSON goes into the page: the server renders and escapes it.
 *
 * Copyright (C) 2026 Association for Progressive Communications, https://www.apc.org/
 * GNU General Public License version 2 or later.
 */
(() => {
    'use strict';

    const POLL_MS = 5000;
    const MAX_MS  = 60000;

    // delegated, so the forms of a replaced section behave as the first ones
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const question = form.dataset.aaConfirm;
        if (question && !confirm(question)) {
            e.preventDefault();
            form.classList.remove('aa-updating');
            return;
        }
        form.classList.add('aa-updating');
    });

    /** what the user set in a section stays in its fresh copy: open details, chosen options */
    const keepState = (old, fresh) => {
        fresh.open = old.open;
        old.querySelectorAll('details[id]').forEach((d) => {
            const twin = fresh.querySelector('#' + CSS.escape(d.id));
            if (twin) {
                twin.open = d.open;
            }
        });
        old.querySelectorAll('select[name]').forEach((s) => {
            const twin = fresh.querySelector('select[name="' + CSS.escape(s.name) + '"]');
            if (twin && [...twin.options].some((o) => o.value === s.value)) {
                twin.value = s.value;
            }
        });
    };

    /** a button by what it sends - the action of its form, its own name and value */
    const buttonKey = (b) => [b.form?.querySelector('input[name="action"]')?.value ?? '', b.name, b.value].join('\n');

    /** where the focus that was on $el in the replaced section goes in the fresh one $section */
    const focusTwin = (el, section) => {
        let twin = null;
        if (el.matches('summary')) {
            const d = el.closest('details');
            twin = ((d.id === '') || (d.id === section.id)) ? null : section.querySelector('#' + CSS.escape(d.id) + ' > summary');
        } else if (el.matches('select[name]')) {
            twin = section.querySelector('select[name="' + CSS.escape(el.name) + '"]');
        } else if (el.matches('button')) {
            twin = [...section.querySelectorAll('button')].find((b) => !b.disabled && (buttonKey(b) === buttonKey(el)));
        }
        (twin ?? section.querySelector(':scope > summary'))?.focus({preventScroll: true});
    };

    const follow = () => {
        // the page renders it only while a run goes on by itself
        let live = document.getElementById('aa-import-live');
        if (!live?.dataset.aaProgress || !live.dataset.aaSections) {
            return;
        }
        const visible   = () => document.visibilityState === 'visible';
        const canonical = (json) => {
            try {
                return JSON.stringify(JSON.parse(json ?? ''));
            } catch {
                return null;
            }
        };
        let shown   = canonical(live.dataset.aaShown);   // the progress the sections show
        let delay   = POLL_MS;
        let timer   = null;
        let busy    = false;
        let stopped = false;

        const schedule = () => {
            if (!stopped && !busy && (timer === null) && visible()) {
                timer = setTimeout(poll, delay);
            }
        };

        // the session is gone or the run is no longer the user's: only a reload helps
        const outdated = () => {
            stopped = true;
            live.classList.add('aa-err');
            live.textContent = live.dataset.aaStale ?? '';
        };

        /** @return {Promise<boolean>} whether the page now shows a fresh state */
        const refresh = async () => {
            const res = await fetch(live.dataset.aaSections, {credentials: 'same-origin', cache: 'no-store'});
            if ((res.status === 401) || (res.status === 403)) {
                outdated();
                return false;
            }
            if (!res.ok) {
                return false;
            }
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            if (!doc.querySelector('details[data-aa-live]')) {
                return false;
            }
            const active = document.activeElement;
            let skipped  = false;
            document.querySelectorAll('details[data-aa-live]').forEach((old) => {
                const fresh = doc.getElementById(old.id);
                if (!fresh?.matches('details[data-aa-live]')) {
                    return;
                }
                const focused = old.contains(active) ? active : null;
                // text being typed stays as it is; the section comes again with the next change
                if (focused?.matches('input, textarea')) {
                    skipped = true;
                    return;
                }
                const node = document.adoptNode(fresh);
                keepState(old, node);
                old.replaceWith(node);
                if (focused) {
                    focusTwin(focused, node);
                }
            });
            // the run the fresh page follows: none once it is over, another one started right after
            const next = doc.getElementById('aa-import-live');
            if (next) {
                const node = document.adoptNode(next);
                live.replaceWith(node);
                live  = node;
                shown = canonical(live.dataset.aaShown);
            } else if (!skipped) {
                live.remove();
                stopped = true;
            }
            // a finished run with a section left out keeps polling: the next answer refreshes it again
            return true;
        };

        const poll = async () => {
            timer = null;
            if (stopped || busy || !visible()) {
                return;
            }
            busy = true;
            let ok = false;
            try {
                const res = await fetch(live.dataset.aaProgress, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
                if ((res.status === 401) || (res.status === 403)) {
                    outdated();
                } else if (res.ok) {
                    const progress = await res.json();
                    ok = ((progress.state === 'running') && (JSON.stringify(progress) === shown)) || await refresh();
                }
            } catch {
                // a lost connection or a broken answer: the next poll waits longer
            } finally {
                busy = false;
            }
            delay = ok ? POLL_MS : Math.min(delay * 2, MAX_MS);
            schedule();
        };

        document.addEventListener('visibilitychange', schedule);
        schedule();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', follow);
    } else {
        follow();
    }
})();
