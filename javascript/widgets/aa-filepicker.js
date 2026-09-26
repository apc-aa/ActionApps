/**
 * File picker of the rich-text editor: the files of one slice from api/files.php,
 * newest first, with thumbnails, a name filter and an upload button.
 * Only for editors of the slice - the API refuses the list to anybody else.
 *
 *   const file = await openFilePicker({ url, token, slice, kind: 'image', upload, l10n });
 *   // {url, name}, or null when closed
 */

/**
 * @param {object}   options
 * @param {string}   options.url     api/files.php
 * @param {string}   options.token   X-AA-Upload-Token
 * @param {string}   options.slice   slice id
 * @param {string}   [options.kind]  'image' lists images only
 * @param {(blob: Blob, name: string) => Promise<{url: string, name: string}>} options.upload
 * @param {Object<string, string>} [options.l10n]
 * @returns {Promise<{url: string, name: string} | null>}
 */
export function openFilePicker({ url, token, slice, kind = 'all', upload, l10n = {} }) {
    const t = (key, fallback) => l10n[key] ?? fallback;
    const dialog = document.createElement('dialog');
    dialog.className = 'aa-filepicker';
    dialog.innerHTML = `
        <div class="aa-filepicker-head">
          <input type="search">
          <label class="btn"><span></span><input type="file" hidden></label>
          <button type="button" class="btn">×</button>
        </div>
        <p class="aa-filepicker-msg" role="status"></p>
        <ul class="aa-filepicker-list"></ul>`;
    const search = dialog.querySelector('input[type=search]');
    const file   = dialog.querySelector('input[type=file]');
    const msg    = dialog.querySelector('[role=status]');
    const list   = dialog.querySelector('ul');
    search.placeholder = t('search', 'Search');
    dialog.querySelector('label span').textContent = t('upload', 'Upload');
    dialog.querySelector('.aa-filepicker-head > button').title = t('close', 'Close');
    if (kind === 'image') {
        file.accept = 'image/*';
    }

    return new Promise((resolve) => {
        let chosen = null;
        let timer  = 0;
        const choose = (picked) => {
            chosen = { url: picked.url, name: picked.name };
            dialog.close();
        };
        const render = (files) => {
            list.replaceChildren(...files.map((f) => {
                const button = document.createElement('button');
                button.type  = 'button';
                button.title = f.name;
                if (f.thumb) {
                    const img = document.createElement('img');
                    img.src = f.thumb;
                    img.alt = '';
                    img.loading = 'lazy';
                    button.append(img);
                }
                const name = document.createElement('span');
                name.textContent = f.name;
                button.append(name);
                button.addEventListener('click', () => choose(f));
                const li = document.createElement('li');
                li.append(button);
                return li;
            }));
        };
        const load = async () => {
            msg.textContent = t('loading', 'Loading...');
            const query = new URLSearchParams({ slice, kind, q: search.value });
            try {
                const response = await fetch(url + (url.includes('?') ? '&' : '?') + query, { headers: { 'X-AA-Upload-Token': token }, credentials: 'same-origin' });
                const json = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(json.error || response.statusText);
                }
                render(json.files ?? []);
                msg.textContent = json.files?.length ? '' : t('empty', 'No files');
            } catch (e) {
                list.replaceChildren();
                msg.textContent = e.message;
            }
        };

        search.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(load, 250);    // one request per pause, not per keystroke
        });
        file.addEventListener('change', async () => {
            const [picked] = file.files;
            if (!picked) {
                return;
            }
            msg.textContent = t('uploading', 'Uploading...');
            try {
                choose(await upload(picked, picked.name));
            } catch (e) {
                msg.textContent = e.message;
            }
        });
        dialog.querySelector('.aa-filepicker-head > button').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => {
            clearTimeout(timer);
            dialog.remove();
            resolve(chosen);
        });
        document.body.append(dialog);
        dialog.showModal();                   // top layer - above TinyMCE's own dialog
        load();
    });
}
