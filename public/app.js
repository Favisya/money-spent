(() => {
    'use strict';

    const tip = document.getElementById('tip');

    if (tip) {
        const place = (event) => {
            const pad = 14;
            const box = tip.getBoundingClientRect();
            let x = event.clientX + pad;
            let y = event.clientY + pad;

            if (x + box.width > window.innerWidth - 8) {
                x = event.clientX - box.width - pad;
            }

            if (y + box.height > window.innerHeight - 8) {
                y = event.clientY - box.height - pad;
            }

            tip.style.transform = `translate(${Math.max(8, x)}px, ${Math.max(8, y)}px)`;
        };

        document.addEventListener('pointerover', (event) => {
            const target = event.target instanceof Element ? event.target.closest('[data-tip]') : null;

            if (!target) {
                tip.hidden = true;

                return;
            }

            tip.textContent = target.getAttribute('data-tip');
            tip.hidden = false;
            place(event);
        });
        document.addEventListener('pointermove', (event) => {
            if (!tip.hidden) {
                place(event);
            }
        });
        document.addEventListener('pointerleave', () => {
            tip.hidden = true;
        });
    }

    document.querySelectorAll('svg.chord').forEach((chart) => {
        const ribbons = chart.querySelectorAll('.chord__ribbons path');
        const focus = (key) => {
            chart.classList.toggle('is-focused', key !== null);
            ribbons.forEach((ribbon) => {
                const groups = (ribbon.getAttribute('data-group') || '').split(' ');
                ribbon.classList.toggle('is-on', key !== null && groups.includes(key));
            });
        };

        chart.querySelectorAll('.chord__group').forEach((group) => {
            group.addEventListener('pointerenter', () => focus(group.getAttribute('data-key')));
            group.addEventListener('pointerleave', () => focus(null));
        });
    });
})();

(() => {
    'use strict';

    // Inside the macOS app: ask the server for new entries by others and hand them to native notifications.
    // Expense tables keep one line per row while it fits the column, and fold each row into two lines when it
    // does not: the width depends on zoom, language and the amounts, so it is measured rather than guessed.
    const fit = (table) => {
        table.classList.remove('is-folded');
        table.classList.toggle('is-folded', table.offsetWidth > table.parentElement.clientWidth + 1);
    };
    const widths = new WeakMap();
    const folding = 'ResizeObserver' in window ? new ResizeObserver((entries) => entries.forEach((entry) => {
        const width = Math.round(entry.contentRect.width);
        const table = entry.target.querySelector('.ledger--expenses');

        if (table && widths.get(entry.target) !== width) {
            widths.set(entry.target, width);
            fit(table);
        }
    })) : null;

    document.querySelectorAll('.ledger--expenses').forEach((table) => {
        fit(table);
        folding?.observe(table.parentElement);
    });

    const bridge = window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.notify;

    if (!bridge) {
        return;
    }

    const key = 'events-cursor';
    const every = 30000;
    const read = () => {
        try {
            return window.localStorage.getItem(key) || '';
        } catch (error) {
            return '';
        }
    };
    const write = (value) => {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            // Storage can be unavailable; the next poll starts a fresh cursor.
        }
    };
    const poll = async () => {
        try {
            const response = await fetch('/api/events?since=' + encodeURIComponent(read()), {
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            (data.events || []).forEach((event) => bridge.postMessage({
                id: String(event.id),
                title: String(event.title),
                body: String(event.body),
            }));
            write(String(data.cursor || ''));
        } catch (error) {
            // Offline or the host Mac is asleep: try again on the next tick.
        }
    };

    poll();
    window.setInterval(poll, every);
})();

(() => {
    'use strict';

    // Receipt photos are re-encoded in the browser before upload: HEIC from an iPhone becomes JPEG, which every
    // model reads, and a 12-megapixel photo shrinks to a size the models use anyway.
    const MAX_EDGE = 2560;
    const QUALITY = 0.9;

    const decode = (file) => new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Image cannot be decoded'));
        };
        image.src = url;
    });

    const toJpeg = async (file) => {
        let image;

        try {
            image = await decode(file);
        } catch (error) {
            return file;
        }

        const width = image.naturalWidth;
        const height = image.naturalHeight;
        const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
        const canvas = document.createElement('canvas');

        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));

        return blob ? new File([blob], file.name.replace(/\.[^.]*$/, '') + '.jpg', {type: 'image/jpeg'}) : file;
    };

    document.querySelectorAll('[data-recognize]').forEach((form) => {
        const zone = form.querySelector('[data-dropzone]');
        const input = form.querySelector('[data-photo]');
        const preview = form.querySelector('[data-preview]');
        const button = form.querySelector('button[type="submit"]');
        const markBusy = () => {
            button.disabled = true;
            button.textContent = button.dataset.busyText || button.textContent;
            form.classList.add('is-busy');
        };
        const accept = async (file) => {
            if (!file || form.classList.contains('is-busy') || button.disabled) {
                return;
            }

            const prepared = await toJpeg(file);
            const transfer = new DataTransfer();

            transfer.items.add(prepared);
            input.files = transfer.files;
            preview.src = URL.createObjectURL(prepared);
            preview.hidden = false;
            markBusy();
            form.submit();
        };

        ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (event) => {
            event.preventDefault();
            zone.classList.add('is-over');
        }));
        ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, (event) => {
            event.preventDefault();
            zone.classList.remove('is-over');
        }));
        zone.addEventListener('drop', (event) => accept(event.dataTransfer && event.dataTransfer.files[0]));
        input.addEventListener('change', () => accept(input.files[0]));

        // A screenshot copied to the clipboard (⌘⇧⌃4 on a Mac) is pasted anywhere on the page; pasted text still goes
        // into the list as usual.
        document.addEventListener('paste', (event) => {
            const items = Array.from((event.clipboardData && event.clipboardData.items) || []);
            const image = items.find((item) => item.kind === 'file' && item.type.startsWith('image/'));

            if (image) {
                event.preventDefault();
                accept(image.getAsFile());
            }
        });
        form.addEventListener('submit', markBusy);

        // A photo dropped next to the zone must not open in the window instead of being uploaded.
        ['dragover', 'drop'].forEach((type) => document.addEventListener(type, (event) => event.preventDefault()));
    });

    const number = (value) => {
        const text = String(value || '').replace(/[\s  ']/g, '');
        const plain = text.includes(',') && text.includes('.') ? text.replace(/,/g, '') : text.replace(',', '.');
        const parsed = Number(plain);

        return plain === '' || !Number.isFinite(parsed) ? null : parsed;
    };

    document.querySelectorAll('[data-receipt]').forEach((form) => {
        const exponent = Number(form.dataset.exponent || 0);
        const rows = form.querySelector('[data-rows]');
        const template = form.querySelector('[data-row-template]');
        const total = form.querySelector('[data-total]');
        const itemsTotal = form.querySelector('[data-items-total]');
        const delivery = form.querySelector('[data-delivery]');
        const deliveryTotal = form.querySelector('[data-delivery-total]');
        const parts = form.querySelector('[data-parts]');
        const format = (value) => value.toLocaleString(document.documentElement.lang || 'ru', {
            minimumFractionDigits: exponent,
            maximumFractionDigits: exponent,
        });
        let next = rows.querySelectorAll('[data-row]').length;

        const recount = () => {
            let sum = 0;

            rows.querySelectorAll('[data-row]').forEach((row) => {
                const removed = row.querySelector('[data-remove]').checked;

                row.classList.toggle('is-removed', removed);

                if (removed) {
                    return;
                }

                const amount = number(row.querySelector('[data-amount]').value);
                const price = number(row.querySelector('[data-price]').value);
                const quantity = number(row.querySelector('[data-qty]').value);

                sum += amount !== null ? amount : (price || 0) * (quantity === null ? 1 : quantity);
            });

            const extra = Math.max(0, number(delivery.value) || 0);

            itemsTotal.textContent = format(sum);
            deliveryTotal.textContent = format(extra);
            parts.hidden = extra === 0;
            total.textContent = format(sum + extra);
        };

        form.querySelector('[data-add-row]').addEventListener('click', () => {
            rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__i__', String(next)));
            next += 1;
            rows.lastElementChild.querySelector('input').focus();
        });
        form.addEventListener('input', recount);
        form.addEventListener('change', recount);
        recount();
    });

    document.querySelectorAll('[data-lightbox]').forEach((dialog) => {
        const opener = dialog.parentElement.querySelector('[data-lightbox-open]');
        const image = dialog.querySelector('[data-lightbox-image]');

        opener.addEventListener('click', () => dialog.showModal());
        image.addEventListener('click', () => image.classList.toggle('is-zoomed'));
        // A click on the dimmed backdrop lands on the dialog itself and closes it, like Esc and the button.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    }));
})();
