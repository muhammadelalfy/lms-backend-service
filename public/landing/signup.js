/* Landing: "start your free trial" box. Opens a trial academy and sends the owner to its sign-in. */
(function () {
    'use strict';
    var dialog = document.getElementById('signup-dialog');
    if (!dialog) return;
    var form = document.getElementById('signup-form');
    var status = document.getElementById('signup-status');
    var go = form.querySelector('.school-go');
    var slug = document.getElementById('su-slug');
    var SLUG = /^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/;

    function say(text, kind) { status.textContent = text; status.className = 'school-status' + (kind ? ' is-' + kind : ''); }
    function busy(on) { go.setAttribute('aria-busy', String(on)); go.disabled = on; }
    function open(kind) {
        say('');
        if (kind) form.elements.kind.value = kind;
        if (typeof dialog.showModal === 'function') { if (!dialog.open) dialog.showModal(); } else { dialog.setAttribute('open', ''); }
        form.elements.name.focus();
    }
    function close() { if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open'); }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-open-signup]');
        if (opener) { e.preventDefault(); open(opener.getAttribute('data-open-signup')); }
        else if (e.target.closest('[data-close-signup]') || e.target === dialog) { close(); }
    });

    slug.addEventListener('input', function () { slug.value = slug.value.toLowerCase().replace(/[^a-z0-9-]/g, ''); });

    function firstError(body) {
        var errors = body && body.errors;
        if (errors) { var key = Object.keys(errors)[0]; if (key) return errors[key][0]; }
        return (body && body.message) || 'تعذّر إنشاء المركز. حاول مرة أخرى.';
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var f = form.elements;
        if (!SLUG.test(f.slug.value)) { say('العنوان: حروف إنجليزية صغيرة وأرقام وشرطات فقط.', 'error'); f.slug.focus(); return; }
        if (f.password.value.length < 8) { say('كلمة المرور 8 أحرف على الأقل.', 'error'); f.password.focus(); return; }

        busy(true);
        say('جارٍ تجهيز مركزك… قد يستغرق ذلك لحظات.');
        fetch('/api/signup', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                kind: f.kind.value, name: f.name.value.trim(), slug: f.slug.value,
                admin: { name: f.admin_name.value.trim(), email: f.email.value.trim(), password: f.password.value },
                website: f.website.value || undefined,
            }),
        })
            .then(function (res) { return res.json().catch(function () { return {}; }).then(function (body) { return { ok: res.ok, status: res.status, body: body }; }); })
            .then(function (r) {
                if (r.status === 429) { say('محاولات كثيرة. حاول بعد قليل.', 'error'); busy(false); return; }
                if (!r.ok) { say(firstError(r.body), 'error'); busy(false); return; }
                say('تم! جارٍ تحويلك إلى ' + r.body.name + '…', 'ok');
                window.location.assign(r.body.url);
            })
            .catch(function () { say('تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.', 'error'); busy(false); });
    });
})();
