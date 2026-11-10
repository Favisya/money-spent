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
