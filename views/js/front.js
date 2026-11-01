/**
 * josrachat - widget del asistente.
 * Se monta solo en DOMContentLoaded: no depende de ningún hook del tema.
 * @author josra
 */
(function () {
    'use strict';

    if (window.josrachatLoaded) {
        return;
    }
    window.josrachatLoaded = true;

    function boot() {
        var cfg = window.josrachatCfg;
        if (!cfg || !cfg.url || document.getElementById('josrachat')) {
            return;
        }
        if (!cfg.mobile && window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
            return;
        }
        var T = cfg.texts || {};
        var state = { token: '', busy: false, loaded: false, open: false };

        // ------------------------------------------------------------ utilidades
        function el(tag, cls, text) {
            var e = document.createElement(tag);
            if (cls) { e.className = cls; }
            if (text) { e.textContent = text; }
            return e;
        }
        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function safeUrl(u) {
            u = String(u).replace(/&amp;/g, '&');
            return /^(https?:\/\/|\/|mailto:|tel:)/i.test(u) ? u : '';
        }
        // Markdown mínimo sobre texto ya escapado: enlaces, negrita, listas y saltos.
        function render(text) {
            var h = esc(text);
            h = h.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, label, url) {
                var u = safeUrl(url);
                return u ? '<a href="' + u.replace(/"/g, '&quot;') + '">' + label + '</a>' : label;
            });
            h = h.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, function (m, pre, url) {
                return pre + '<a href="' + url + '">' + url + '</a>';
            });
            h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            h = h.replace(/^\s*[-*]\s+(.+)$/gm, '&bull; $1');
            h = h.replace(/\n/g, '<br>');
            return h;
        }
        function storeGet(k) {
            try { return window.sessionStorage.getItem(k); } catch (e) { return null; }
        }
        function storeSet(k, v) {
            try { window.sessionStorage.setItem(k, v); } catch (e) { /* sin almacenamiento */ }
        }
        function post(data, cb) {
            var body = [];
            for (var k in data) {
                if (Object.prototype.hasOwnProperty.call(data, k)) {
                    body.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
                }
            }
            var x = new XMLHttpRequest();
            x.open('POST', cfg.url, true);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            x.timeout = 120000;
            x.onload = function () {
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) { r = null; }
                cb(r);
            };
            x.onerror = x.ontimeout = function () { cb(null); };
            x.send(body.join('&'));
        }
        function viewingProduct() {
            if (!document.body || document.body.id !== 'product') { return 0; }
            var i = document.getElementById('product_page_product_id') || document.querySelector('input[name="id_product"]');
            var v = i ? parseInt(i.value, 10) : 0;
            return isNaN(v) ? 0 : v;
        }

        // ------------------------------------------------------------ estructura
        var root = el('div', 'jrc jrc-' + (cfg.position === 'left' ? 'left' : 'right'));
        root.id = 'josrachat';
        root.style.setProperty('--jrc-color', cfg.color || '#2563eb');

        var bubble = el('button', 'jrc-bubble');
        bubble.type = 'button';
        bubble.setAttribute('aria-label', T.open || 'Chat');
        bubble.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm3 7a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/></svg>';

        var panel = el('div', 'jrc-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', cfg.name);

        var head = el('div', 'jrc-head');
        var title = el('div', 'jrc-title');
        title.appendChild(el('strong', '', cfg.name));
        title.appendChild(el('span', 'jrc-sub', T.subtitle));
        var btnPerson = el('button', 'jrc-icon', '');
        btnPerson.type = 'button';
        btnPerson.title = T.person;
        btnPerson.setAttribute('aria-label', T.person);
        btnPerson.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4 0-8 2-8 5v2h16v-2c0-3-4-5-8-5z"/></svg>';
        var btnClose = el('button', 'jrc-icon', '');
        btnClose.type = 'button';
        btnClose.title = T.close;
        btnClose.setAttribute('aria-label', T.close);
        btnClose.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg>';
        head.appendChild(title);
        head.appendChild(btnPerson);
        head.appendChild(btnClose);

        var list = el('div', 'jrc-list');
        list.setAttribute('aria-live', 'polite');

        // Panel de contacto con personas
        var contact = el('div', 'jrc-contact');
        contact.appendChild(el('p', 'jrc-contact-title', T.contactTitle));
        var opts = el('div', 'jrc-options');
        if (cfg.whatsapp) {
            var wa = el('a', 'jrc-opt jrc-wa', 'WhatsApp');
            wa.href = 'https://wa.me/' + cfg.whatsapp + '?text=' + encodeURIComponent(T.waText || '');
            wa.target = '_blank';
            wa.rel = 'noopener';
            opts.appendChild(wa);
        }
        if (cfg.phone) {
            var ph = el('a', 'jrc-opt jrc-phone', T.call + ' ' + cfg.phone);
            ph.href = 'tel:' + cfg.phone.replace(/[^0-9+]/g, '');
            opts.appendChild(ph);
        }
        var btnMail = el('button', 'jrc-opt jrc-mail', T.write);
        btnMail.type = 'button';
        opts.appendChild(btnMail);
        contact.appendChild(opts);

        var form = el('form', 'jrc-form');
        form.setAttribute('novalidate', 'novalidate');
        var fName = el('input');
        fName.type = 'text';
        fName.placeholder = T.name;
        fName.maxLength = 100;
        fName.value = cfg.customerName || '';
        var fEmail = el('input');
        fEmail.type = 'email';
        fEmail.placeholder = T.email;
        fEmail.maxLength = 128;
        fEmail.value = cfg.customerEmail || '';
        var fMsg = el('textarea');
        fMsg.placeholder = T.message;
        fMsg.rows = 4;
        fMsg.maxLength = 3000;
        var fSend = el('button', 'jrc-send-form', T.send);
        fSend.type = 'submit';
        var fErr = el('div', 'jrc-form-error');
        form.appendChild(fName);
        form.appendChild(fEmail);
        form.appendChild(fMsg);
        form.appendChild(fErr);
        form.appendChild(fSend);
        contact.appendChild(form);
        var back = el('button', 'jrc-back', T.back);
        back.type = 'button';
        contact.appendChild(back);

        var foot = el('form', 'jrc-foot');
        var input = el('textarea', 'jrc-input');
        input.rows = 1;
        input.maxLength = 800;
        input.placeholder = T.placeholder;
        input.setAttribute('aria-label', T.placeholder);
        var send = el('button', 'jrc-send');
        send.type = 'submit';
        send.setAttribute('aria-label', T.send);
        send.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M2 21 23 12 2 3v7l15 2-15 2z"/></svg>';
        foot.appendChild(input);
        foot.appendChild(send);

        var note = el('div', 'jrc-note', T.note);

        panel.appendChild(head);
        panel.appendChild(list);
        panel.appendChild(contact);
        panel.appendChild(foot);
        panel.appendChild(note);
        root.appendChild(panel);
        root.appendChild(bubble);
        document.body.appendChild(root);

        // ------------------------------------------------------------ mensajes
        function addMsg(role, text) {
            var row = el('div', 'jrc-msg jrc-' + (role === 'user' ? 'user' : 'bot'));
            var b = el('div', 'jrc-txt');
            if (role === 'user') {
                b.textContent = text;
            } else {
                b.innerHTML = render(text);
            }
            row.appendChild(b);
            list.appendChild(row);
            list.scrollTop = list.scrollHeight;
        }
        var typing = null;
        function setTyping(on) {
            if (on && !typing) {
                typing = el('div', 'jrc-msg jrc-bot jrc-typing');
                typing.innerHTML = '<div class="jrc-txt"><i></i><i></i><i></i></div>';
                list.appendChild(typing);
                list.scrollTop = list.scrollHeight;
            } else if (!on && typing) {
                typing.parentNode.removeChild(typing);
                typing = null;
            }
        }
        function showContact(on) {
            root.className = root.className.replace(/\s?jrc-show-contact/g, '') + (on ? ' jrc-show-contact' : '');
            if (!on) {
                root.className = root.className.replace(/\s?jrc-show-form/g, '');
            }
        }

        function load(cb) {
            if (state.loaded) { cb(); return; }
            post({ action: 'init' }, function (r) {
                if (!r || !r.ok) {
                    addMsg('bot', T.error);
                    return;
                }
                state.loaded = true;
                state.token = r.token;
                addMsg('bot', cfg.welcome);
                for (var i = 0; i < r.history.length; i++) {
                    addMsg(r.history[i].role, r.history[i].text);
                }
                cb();
            });
        }

        function openPanel() {
            state.open = true;
            root.className += ' jrc-open';
            storeSet('josrachat_open', '1');
            load(function () {
                if (window.matchMedia && !window.matchMedia('(max-width: 767px)').matches) {
                    input.focus();
                }
            });
        }
        function closePanel() {
            state.open = false;
            root.className = root.className.replace(/\s?jrc-open/g, '');
            storeSet('josrachat_open', '0');
        }

        function sendMessage(text) {
            if (state.busy || !text) { return; }
            state.busy = true;
            send.disabled = true;
            addMsg('user', text);
            setTyping(true);
            var retried = false;
            var payload = { action: 'send', token: state.token, message: text, viewing: viewingProduct() };
            post(payload, function done(r) {
                if (r && !r.ok && r.error === 'token' && !retried) {
                    // La sesión ha caducado: se pide un token nuevo y se reintenta una vez.
                    retried = true;
                    post({ action: 'init' }, function (i) {
                        if (i && i.ok) {
                            state.token = i.token;
                            payload.token = i.token;
                            post(payload, done);
                        } else {
                            done(null);
                        }
                    });
                    return;
                }
                setTyping(false);
                state.busy = false;
                send.disabled = false;
                if (!r || !r.ok) {
                    addMsg('bot', T.error);
                    showContact(true);
                    return;
                }
                addMsg('bot', r.reply);
                if (r.contact) {
                    showContact(true);
                }
            });
        }

        // ------------------------------------------------------------ eventos
        bubble.addEventListener('click', function () {
            if (state.open) { closePanel(); } else { openPanel(); }
        });
        btnClose.addEventListener('click', closePanel);
        btnPerson.addEventListener('click', function () { showContact(root.className.indexOf('jrc-show-contact') === -1); });
        back.addEventListener('click', function () { showContact(false); });
        btnMail.addEventListener('click', function () {
            root.className = root.className.replace(/\s?jrc-show-form/g, '') + ' jrc-show-form';
            (fName.value ? (fEmail.value ? fMsg : fEmail) : fName).focus();
        });
        foot.addEventListener('submit', function (e) {
            e.preventDefault();
            var t = input.value.replace(/^\s+|\s+$/g, '');
            input.value = '';
            input.style.height = '';
            sendMessage(t);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                foot.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });
        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 110) + 'px';
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            fErr.textContent = '';
            if (!fName.value || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(fEmail.value) || !fMsg.value) {
                fErr.textContent = T.formError;
                return;
            }
            fSend.disabled = true;
            load(function () {
                post({ action: 'contact', token: state.token, name: fName.value, email: fEmail.value, message: fMsg.value }, function (r) {
                    fSend.disabled = false;
                    if (!r || !r.ok) {
                        fErr.textContent = (r && r.error) ? r.error : T.error;
                        return;
                    }
                    fMsg.value = '';
                    showContact(false);
                    addMsg('bot', r.reply);
                });
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && state.open) { closePanel(); }
        });

        if (storeGet('josrachat_open') === '1') {
            openPanel();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
