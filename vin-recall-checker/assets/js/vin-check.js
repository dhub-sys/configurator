(function () {
    'use strict';
    if (!window.VRC || !window.fetch) return;

    var VIN_RE = /^[A-HJ-NPR-Z0-9]{17}$/;

    function clean(value) {
        // Upper-case, drop separators; O/Q → 0 and I → 1, letters a VIN never contains.
        return value.toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/[OQ]/g, '0').replace(/I/g, '1').slice(0, 17);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function render(box, data) {
        box.innerHTML = '';
        box.className = 'vrc-result vrc-result--' + (data.status || 'error');
        box.appendChild(el('p', 'vrc-result__message', data.message || VRC.i18n.error));
        if (data.vin) box.appendChild(el('p', 'vrc-result__vin', 'VIN: ' + data.vin));
        if (data.labor) box.appendChild(el('p', 'vrc-result__labor', data.labor));
        if (data.note) box.appendChild(el('p', 'vrc-result__note', data.note));
        if (data.cta) {
            var actions = el('p', 'vrc-result__actions');
            if (data.cta.url) {
                var a = el('a', 'vrc-cta', data.cta.label);
                a.href = data.cta.url;
                actions.appendChild(a);
            }
            if (data.cta.phone) {
                var tel = el('a', 'vrc-phone', data.cta.phone);
                tel.href = 'tel:' + data.cta.tel;
                actions.appendChild(tel);
            }
            box.appendChild(actions);
        }
        box.hidden = false;
    }

    function setup(root) {
        var form = root.querySelector('.vrc-form');
        var input = root.querySelector('.vrc-input');
        var button = root.querySelector('.vrc-button');
        var counter = root.querySelector('.vrc-counter span');
        var honeypot = root.querySelector('.vrc-hp');
        var box = root.querySelector('.vrc-result');
        if (!form || !input || !box) return;

        input.addEventListener('input', function () {
            var cleaned = clean(input.value);
            if (cleaned !== input.value) input.value = cleaned;
            if (counter) counter.textContent = cleaned.length;
            input.classList.toggle('vrc-input--complete', cleaned.length === 17);
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var vin = clean(input.value);
            if (!VIN_RE.test(vin)) {
                render(box, { status: 'invalid', message: VRC.i18n.invalid });
                input.focus();
                return;
            }
            button.disabled = true;
            root.classList.add('vrc-checker--loading');
            render(box, { status: 'loading', message: VRC.i18n.loading });

            fetch(VRC.endpoint, {
                method: 'POST',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ vin: vin, website: honeypot ? honeypot.value : '' })
            })
                .then(function (r) { return r.json().catch(function () { return { status: 'error' }; }); })
                .then(function (data) { render(box, data && data.status ? data : { status: 'error' }); })
                .catch(function () { render(box, { status: 'error' }); })
                .then(function () {
                    button.disabled = false;
                    root.classList.remove('vrc-checker--loading');
                });
        });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.vrc-checker'), setup);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
