/* Landing pricing: tabs, monthly/yearly toggle, FAQ/table analytics, and the free-trial sign-up dialog.
   The section is fully server-rendered (no JS needed); this only enhances it. It never computes a price, a limit
   or an eligibility rule: it reads the catalog the server embedded (#plans-data) and shows the server's answers. */
(function () {
    'use strict';

    // ---- analytics shim: no-op without a provider; never receives a name, phone or email ------------
    window.mudarisyTrack = window.mudarisyTrack || function (name, props) {
        try {
            if (Array.isArray(window.dataLayer)) window.dataLayer.push(Object.assign({ event: name }, props || {}));
            if (typeof window.plausible === 'function') window.plausible(name, { props: props || {} });
            if (typeof window.gtag === 'function') window.gtag('event', name, props || {});
        } catch (e) { /* analytics must never break the page */ }
    };
    var track = function (n, p) { window.mudarisyTrack(n, p); };

    var root = document.getElementById('pricing');
    if (!root) return;
    var dataEl = document.getElementById('plans-data');
    var data = {};
    try { data = JSON.parse(dataEl.textContent); } catch (e) { data = { plans: [], reserved_slugs: [] }; }
    var plans = {};
    (data.plans || []).forEach(function (p) { plans[p.slug] = p; });
    var nf = new Intl.NumberFormat('en-US');
    var state = { tab: 'center', billing: 'monthly' };

    // ---- tabs --------------------------------------------------------------------------------------
    var tabs = Array.prototype.slice.call(root.querySelectorAll('.pr-tab'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('.pr-panel'));

    // Keep the selected tab inside the horizontally scrolling tab bar (narrow screens) without moving the page.
    function revealTab(t) {
        var bar = t.parentNode;
        var b = bar.getBoundingClientRect(), r = t.getBoundingClientRect();
        if (r.left < b.left) bar.scrollLeft -= b.left - r.left + 8;
        else if (r.right > b.right) bar.scrollLeft += r.right - b.right + 8;
    }
    function selectTab(key, focus, silent) {
        state.tab = key;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === key;
            t.setAttribute('aria-selected', String(on));
            t.tabIndex = on ? 0 : -1;
            if (on) revealTab(t);
            if (on && focus) t.focus({ preventScroll: true });
        });
        panels.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-audience') === key); });
        if (!silent) track('pricing_tab_change', { tab: key });
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { selectTab(t.getAttribute('data-tab'), false); });
        t.addEventListener('keydown', function (e) {
            var rtl = getComputedStyle(root).direction === 'rtl';
            var next = i;
            // Logical order: the next tab is the one visually after this one in reading direction.
            if (e.key === 'ArrowRight') next = rtl ? i - 1 : i + 1;
            else if (e.key === 'ArrowLeft') next = rtl ? i + 1 : i - 1;
            else if (e.key === 'Home') next = 0;
            else if (e.key === 'End') next = tabs.length - 1;
            else return;
            e.preventDefault();
            next = (next + tabs.length) % tabs.length;
            selectTab(tabs[next].getAttribute('data-tab'), true);
        });
    });
    // A link such as /#pr-panel-solo opens that tab.
    var hash = (location.hash || '').match(/^#pr-panel-(\w+)$/);
    if (hash && tabs.some(function (t) { return t.getAttribute('data-tab') === hash[1]; })) selectTab(hash[1], false, true);

    function revealSelected() { tabs.forEach(function (t) { if (t.getAttribute('aria-selected') === 'true') revealTab(t); }); }
    revealSelected();
    window.addEventListener('load', revealSelected);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(revealSelected);

    // ---- billing toggle (persists across tabs: one attribute on the section) -----------------------
    var bills = Array.prototype.slice.call(root.querySelectorAll('.pr-bill'));
    bills.forEach(function (b) {
        b.addEventListener('click', function () {
            state.billing = b.getAttribute('data-billing');
            root.setAttribute('data-billing', state.billing);
            bills.forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
            track('pricing_billing_toggle', { billing: state.billing });
            hint();
        });
    });

    // ---- one-off analytics -------------------------------------------------------------------------
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            if (entries.some(function (en) { return en.isIntersecting; })) { track('pricing_view'); io.disconnect(); }
        }, { threshold: 0 });
        io.observe(root);
    }
    Array.prototype.forEach.call(root.querySelectorAll('.pr-faq details'), function (d) {
        d.addEventListener('toggle', function () { if (d.open) track('faq_open', { index: Number(d.getAttribute('data-faq')) }); });
    });
    Array.prototype.forEach.call(root.querySelectorAll('.pr-scroll'), function (s) {
        s.addEventListener('scroll', function once() { s.removeEventListener('scroll', once); track('comparison_scroll'); }, { passive: true });
    });

    // ---- sign-up dialog ----------------------------------------------------------------------------
    var dialog = document.getElementById('signup-dialog');
    if (!dialog) return;
    var form = document.getElementById('signup-form');
    var done = document.getElementById('signup-done');
    var status = document.getElementById('signup-status');
    var go = form.querySelector('.school-go');
    var f = form.elements;
    var slug = f.slug;
    var slugBox = slug.closest('.school-field');
    var slugMsg = document.getElementById('su-slug-err');
    var hintEl = document.getElementById('su-plan-hint');
    var SLUG = /^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/;
    var opener = null;
    var canModal = typeof dialog.showModal === 'function';
    var fieldMsg = { name: 'su-name-err', slug: 'su-slug-err', 'admin.name': 'su-admin-err', 'admin.email': 'su-email-err', 'admin.password': 'su-pass-err' };
    var fieldInput = { name: f.name, slug: f.slug, 'admin.name': f.admin_name, 'admin.email': f.email, 'admin.password': f.password };
    var MSG = {
        taken: 'هذا العنوان محجوز أو مستخدم.',
        bad: 'العنوان: حروف إنجليزية صغيرة وأرقام وشرطات فقط.',
        free: 'العنوان متاح.',
        throttle: 'طلبات كتير في وقت قصير، جرّب بعد شوية.',
        offline: 'تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.',
        generic: 'حصلت مشكلة، جرّب تاني بعد شوية.',
        short: 'كلمة المرور 8 أحرف على الأقل.',
        required: 'الحقل ده مطلوب.'
    };

    function say(text, kind) { status.textContent = text; status.className = 'school-status' + (kind ? ' is-' + kind : ''); }
    function busy(on) { go.setAttribute('aria-busy', String(on)); go.disabled = on; }
    function setErr(name, text) {
        var msg = document.getElementById(fieldMsg[name]);
        var input = fieldInput[name];
        if (!msg) return;
        msg.textContent = text || '';
        msg.classList.remove('is-ok');
        if (input) {
            if (text) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
        }
        if (name === 'slug') slugBox.classList.remove('is-invalid', 'is-ok');
        if (name === 'slug' && text) slugBox.classList.add('is-invalid');
    }
    function clearErrors() { Object.keys(fieldMsg).forEach(function (k) { setErr(k, ''); }); say(''); }

    function money(n) { return nf.format(n); }
    function hint() {
        var p = plans[f.plan.value];
        if (!p) { hintEl.textContent = ''; return; }
        var yearly = f.billing.value === 'yearly';
        var price = yearly ? money(p.price.shown_yearly) + ' جنيه / سنة' : money(p.price.shown_monthly) + ' جنيه / شهر';
        hintEl.textContent = 'تبدأ بتجربة مجانية ' + daysText(data.trial_days) + '، وبعدها ' + price + ' (باقة ' + p.name + ').';
    }
    function daysText(n) {
        if (n === 1) return 'يوم';
        if (n === 2) return 'يومين';
        return n >= 3 && n <= 10 ? n + ' أيام' : n + ' يوم';
    }
    f.plan.addEventListener('change', hint);
    f.billing.addEventListener('change', hint);

    function open(opts) {
        clearErrors();
        form.hidden = false; done.hidden = true;
        if (opts.kind) f.kind.value = opts.kind;
        if (opts.plan && plans[opts.plan]) f.plan.value = opts.plan;
        f.billing.value = opts.billing || 'monthly';
        hint();
        opener = opts.opener || null;
        if (!dialog.open) dialog.showModal();
        f.name.focus();
        track('signup_open', { plan: f.plan.value, tab: state.tab });
    }
    function close() { if (dialog.open) dialog.close(); }

    dialog.addEventListener('close', function () {
        if (opener && document.contains(opener)) opener.focus();
        opener = null;
    });
    dialog.addEventListener('click', function (e) {
        if (e.target === dialog || e.target.closest('[data-close-signup]')) close();
    });

    root.addEventListener('click', function (e) {
        var cta = e.target.closest('[data-signup]');
        if (!cta) return;
        var panel = cta.closest('.pr-panel');
        track('pricing_cta_click', { plan: cta.getAttribute('data-plan'), tab: state.tab, billing: state.billing, kind: cta.getAttribute('data-kind-of-card') });
        if (!canModal) return; // old browser: the link (mailto) still works
        e.preventDefault();
        open({ kind: panel && panel.getAttribute('data-kind'), plan: cta.getAttribute('data-plan'), billing: state.billing, opener: cta });
    });

    // ---- address: lower-case as typed, then a debounced availability check ------------------------
    var lookupTimer = null;
    var lookupSeq = 0;
    slug.addEventListener('input', function () {
        slug.value = slug.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
        setErr('slug', '');
        clearTimeout(lookupTimer);
        var v = slug.value;
        if (!v) return;
        if (!SLUG.test(v)) { if (v.length > 1) setErr('slug', MSG.bad); return; }
        if ((data.reserved_slugs || []).indexOf(v) !== -1) { setErr('slug', MSG.taken); return; }
        lookupTimer = setTimeout(function () { checkSlug(v); }, 400);
    });
    function checkSlug(v) {
        var seq = ++lookupSeq;
        fetch('/api/school-lookup?code=' + encodeURIComponent(v), { headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (seq !== lookupSeq || slug.value !== v) return;
                if (res.status === 200) setErr('slug', MSG.taken);
                else if (res.status === 404) {
                    slugMsg.textContent = MSG.free; slugMsg.classList.add('is-ok'); slugBox.classList.add('is-ok');
                }
                // 429 or others: say nothing; the server decides on submit.
            })
            .catch(function () { /* offline: the server decides on submit */ });
    }

    // ---- submit ------------------------------------------------------------------------------------
    function focusFirstError() {
        var bad = form.querySelector('[aria-invalid="true"]');
        if (bad) bad.focus();
    }
    function showErrors(errors) {
        var general = [];
        Object.keys(errors).forEach(function (k) {
            var text = errors[k][0];
            if (fieldMsg[k]) setErr(k, text); else general.push(text);
        });
        if (general.length) say(general[0], 'error');
        focusFirstError();
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();
        var local = {};
        if (!f.name.value.trim()) local.name = [MSG.required];
        if (!SLUG.test(slug.value)) local.slug = [slug.value ? MSG.bad : MSG.required];
        else if ((data.reserved_slugs || []).indexOf(slug.value) !== -1) local.slug = [MSG.taken];
        if (!f.admin_name.value.trim()) local['admin.name'] = [MSG.required];
        if (!f.email.value.trim()) local['admin.email'] = [MSG.required];
        if (f.password.value.length < 8) local['admin.password'] = [MSG.short];
        if (Object.keys(local).length) { showErrors(local); return; }

        busy(true);
        say('جارٍ تجهيز مركزك… قد يستغرق ذلك لحظات.');
        track('signup_submit', { plan: f.plan.value });
        fetch('/api/signup', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                kind: f.kind.value, plan: f.plan.value, billing: f.billing.value,
                name: f.name.value.trim(), slug: slug.value,
                admin: { name: f.admin_name.value.trim(), email: f.email.value.trim(), password: f.password.value },
                website: f.website.value || undefined
            })
        })
            .then(function (res) { return res.json().catch(function () { return {}; }).then(function (body) { return { ok: res.ok, status: res.status, body: body }; }); })
            .then(function (r) {
                busy(false);
                if (r.ok) { success(r.body); return; }
                track('signup_error', { status: r.status });
                if (r.status === 429) { say(MSG.throttle, 'error'); return; }
                if (r.status === 422 && r.body.errors) { say(''); showErrors(r.body.errors); return; }
                say(MSG.generic, 'error');
            })
            .catch(function () { busy(false); track('signup_error', { status: 0 }); say(MSG.offline, 'error'); });
    });

    function success(body) {
        track('signup_success', { plan: body.plan });
        var date = body.trial_ends_at
            ? new Date(body.trial_ends_at).toLocaleDateString('ar-EG-u-nu-latn', { day: 'numeric', month: 'long', year: 'numeric' })
            : '';
        var got = plans[body.plan] ? plans[body.plan].name : body.plan;
        var wanted = body.desired_plan && plans[body.desired_plan] ? plans[body.desired_plan].name : '';
        var text = 'مركز «' + body.name + '» جاهز على باقة ' + got + '.' + (date ? ' تجربتك المجانية لحد ' + date + '.' : '');
        if (wanted && body.desired_plan !== body.plan) text += ' اخترت باقة ' + wanted + '، وهنفعّلها معاك بعد التجربة.';
        document.getElementById('signup-done-text').textContent = text;
        document.getElementById('signup-done-link').href = body.url;
        form.hidden = true; done.hidden = false;
        document.getElementById('signup-done-title').focus();
    }
})();
