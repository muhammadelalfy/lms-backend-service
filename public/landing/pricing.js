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
    var nf = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
    var state = { tab: 'center', billing: 'monthly' };
    var tabListeners = []; // the calculator follows the active tab

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
        tabListeners.forEach(function (fn) { fn(key); });
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

    // ---- add-on calculator -------------------------------------------------------------------------
    // An estimate from the rates the server embedded, with the spec formula:
    //   total_list = plan.list + extra_students × rate.student + extra_storage_gb × rate.storage_gb
    //   total_shown = round(total_list × (1 − promo%/100)); yearly = total_shown × yearly_months_billed.
    // The plan itself is activated by the operator; nothing here decides what a center pays.
    var STORAGE_MAX = 2000;
    var LAST_TIER_EXTRA = 5000;
    var ordered = (data.plans || []).slice().sort(function (a, b) { return a.tier - b.tier; });
    var audiences = {};
    (data.audiences || []).forEach(function (a) { audiences[a.key] = a; });
    var calcEl = document.getElementById('pr-calc');
    var calc = calcEl && data.addons ? (function () {
        var $ = function (id) { return document.getElementById(id); };
        var el = {
            plan: $('pc-plan'), st: $('pc-students'), stRange: $('pc-students-range'), stHelp: $('pc-students-help'),
            gb: $('pc-storage'), gbRange: $('pc-storage-range'), gbHelp: $('pc-storage-help'),
            baseName: $('pc-base-name'), base: $('pc-base'), stCalc: $('pc-st-calc'), stCost: $('pc-st'),
            gbCalc: $('pc-gb-calc'), gbCost: $('pc-gb'), list: $('pc-list'), off: $('pc-off'),
            monthly: $('pc-monthly'), yearly: $('pc-yearly'), hint: $('pc-hint'), hintText: $('pc-hint-text'),
            hintCta: $('pc-hint-cta'), cta: $('pc-cta')
        };
        var rateSt = Number(data.addons.extra_student.list);
        var rateGb = Number(data.addons.extra_storage_gb.list);
        var pct = data.promo && data.promo.active ? Number(data.promo.percent) : 0;
        var trackTimer = null;

        function nextOf(slug) {
            var p = plans[slug];
            for (var i = 0; i < ordered.length; i++) if (ordered[i].tier > p.tier) return ordered[i];
            return null;
        }
        function maxExtra(slug) {
            var p = plans[slug], n = nextOf(slug);
            if (n && n.limits.students != null && p.limits.students != null) return n.limits.students - p.limits.students;
            return LAST_TIER_EXTRA;
        }
        function intOf(input) {
            var v = parseInt(input.value, 10);
            return isNaN(v) ? 0 : v;
        }
        function stHelp(slug, clamped) {
            var p = plans[slug], max = maxExtra(slug), more = nextOf(slug);
            el.stHelp.classList.toggle('is-warn', clamped);
            el.stHelp.textContent = clamped
                ? 'أقصى زيادة في باقة ' + p.name + ' هي ' + nf.format(max) + ' طالب، لأن بعدها ' + (more ? 'باقة ' + more.name + ' أوفر لك' : 'الأنسب باقة مخصصة') + '.'
                : 'حتى ' + nf.format(max) + ' طالب إضافي في باقة ' + p.name + '، بـ' + nf.format(rateSt) + ' جنيه للطالب في الشهر.';
        }
        function gbHelp(clamped) {
            el.gbHelp.classList.toggle('is-warn', clamped);
            el.gbHelp.textContent = clamped
                ? 'أقصى مساحة إضافية ' + nf.format(STORAGE_MAX) + ' جيجا. لو محتاج أكتر اطلب باقة مخصصة.'
                : 'حتى ' + nf.format(STORAGE_MAX) + ' جيجا، بـ' + nf.format(rateGb) + ' جنيه للجيجا في الشهر.';
        }
        // Keep a number field inside [0, max]. Returns whether to explain the limit: it had to be cut down just now, or
        // the explanation is already showing and the value still sits at the limit.
        function clamp(input, range, max, rewriteEmpty, help) {
            var v = intOf(input), cut = v > max || (help.classList.contains('is-warn') && v === max);
            v = Math.max(0, Math.min(max, v));
            if (v > max || input.value.trim() !== '' || rewriteEmpty) {
                if (String(v) !== input.value) input.value = String(v);
            }
            range.max = String(max); input.max = String(max);
            range.value = String(v);
            return cut;
        }

        function render() {
            var slug = el.plan.value, p = plans[slug];
            if (!p) return null;
            var es = intOf(el.st), eg = intOf(el.gb);
            var stCost = es * rateSt, gbCost = eg * rateGb;
            var totalList = p.price.list_monthly + stCost + gbCost;
            var shown = Math.round(totalList * (100 - pct) / 100 + 1e-9);
            var yearly = shown * data.yearly_months_billed;

            el.baseName.textContent = p.name;
            el.base.textContent = nf.format(p.price.list_monthly);
            el.stCalc.textContent = '(' + nf.format(es) + ' × ' + nf.format(rateSt) + ')';
            el.stCost.textContent = nf.format(stCost);
            el.gbCalc.textContent = '(' + nf.format(eg) + ' × ' + nf.format(rateGb) + ')';
            el.gbCost.textContent = nf.format(gbCost);
            if (el.list) el.list.textContent = nf.format(totalList);
            if (el.off) el.off.textContent = nf.format(Math.round((totalList - shown) * 100) / 100);
            el.monthly.textContent = nf.format(shown);
            el.yearly.textContent = nf.format(yearly);
            el.cta.setAttribute('data-plan', slug);

            var next = nextOf(slug);
            var better = next && shown >= next.price.shown_monthly;
            el.hint.hidden = !better;
            if (better) {
                el.hintText.textContent = 'باقة ' + next.name + ' بـ' + nf.format(next.price.shown_monthly) + ' جنيه / شهر، وفيها حتى '
                    + nf.format(next.limits.students) + ' طالب و' + nf.format(next.limits.storage_gb) + ' جيجا تخزين.';
                el.hintCta.textContent = 'ابدأ بباقة ' + next.name;
                el.hintCta.setAttribute('data-plan', next.slug);
            }
            return { plan: slug, extra_students: es, extra_storage_gb: eg };
        }
        function changed(fromUser) {
            var props = render();
            if (!fromUser || !props) return;
            clearTimeout(trackTimer);
            trackTimer = setTimeout(function () { track('calculator_change', props); }, 800);
        }

        function onStudents(input, finalize) {
            if (input === el.stRange) el.st.value = el.stRange.value;
            stHelp(el.plan.value, clamp(el.st, el.stRange, maxExtra(el.plan.value), finalize, el.stHelp));
            changed(true);
        }
        function onStorage(input, finalize) {
            if (input === el.gbRange) el.gb.value = el.gbRange.value;
            gbHelp(clamp(el.gb, el.gbRange, STORAGE_MAX, finalize, el.gbHelp));
            changed(true);
        }
        el.st.addEventListener('input', function () { onStudents(el.st, false); });
        el.st.addEventListener('change', function () { onStudents(el.st, true); });
        el.stRange.addEventListener('input', function () { onStudents(el.stRange, true); });
        el.gb.addEventListener('input', function () { onStorage(el.gb, false); });
        el.gb.addEventListener('change', function () { onStorage(el.gb, true); });
        el.gbRange.addEventListener('input', function () { onStorage(el.gbRange, true); });
        el.plan.addEventListener('change', function () { onStudents(el.st, true); });
        document.getElementById('pr-calc-form').addEventListener('submit', function (e) { e.preventDefault(); });
        el.hintCta.addEventListener('click', function () { track('calculator_upgrade_hint_click'); });

        // The plan list is the active tab's plans; keep the chosen plan when the new tab has it.
        function setAudience(key) {
            var a = audiences[key];
            if (!a) return;
            var current = el.plan.value;
            var keep = a.plans.indexOf(current) !== -1 ? current : a.recommended;
            el.plan.innerHTML = '';
            a.plans.forEach(function (slug) {
                var p = plans[slug];
                if (!p) return;
                var o = document.createElement('option');
                o.value = slug;
                o.textContent = p.name + ' — حتى ' + nf.format(p.limits.students) + ' طالب';
                el.plan.appendChild(o);
            });
            el.plan.value = keep;
            stHelp(keep, clamp(el.st, el.stRange, maxExtra(keep), true, el.stHelp));
            changed(false);
        }
        tabListeners.push(setAudience);
        setAudience(state.tab);
        gbHelp(false);

        return {
            summary: function () {
                var p = plans[el.plan.value], es = intOf(el.st), eg = intOf(el.gb);
                if (!p) return { plan: '', text: '' };
                var parts = ['باقة ' + p.name];
                if (es) parts.push(nf.format(es) + ' طالب إضافي');
                if (eg) parts.push(nf.format(eg) + ' جيجا تخزين إضافية');
                return { plan: p.slug, text: parts.length > 1 ? 'محتاج ' + parts.join(' + ') + '.' : '' };
            }
        };
    })() : null;

    // ---- custom-plan request (POST /api/plan-requests) ---------------------------------------------
    var crDialog = document.getElementById('custom-dialog');
    if (crDialog && typeof crDialog.showModal === 'function') (function () {
        var form = document.getElementById('custom-form');
        var done = document.getElementById('custom-done');
        var status = document.getElementById('custom-status');
        var go = form.querySelector('.school-go');
        var count = document.getElementById('cr-message-count');
        var f = form.elements;
        var opener = null;
        var PHONE = /^(?:(?:\+?20|0)?1[0125]\d{8}|\+\d{8,15})$/;
        var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        var fields = { name: 'cr-name', phone: 'cr-phone', email: 'cr-email', org_name: 'cr-org', audience: 'cr-audience', students_estimate: 'cr-students', message: 'cr-message' };
        var MSG = {
            name: 'اكتب اسمك (حرفين على الأقل).',
            phone: 'اكتب رقم موبايل صحيح.',
            email: 'البريد الإلكتروني غير صحيح.',
            org: 'اسم المركز طويل، الحد الأقصى 120 حرف.',
            audience: 'اختار نوع نشاطك.',
            students: 'عدد الطلاب لازم يكون رقم صحيح.',
            msgShort: 'اوصف احتياجك في 10 حروف على الأقل.',
            msgLong: 'الوصف طويل، الحد الأقصى 800 حرف.',
            links: 'ماينفعش أكتر من رابطين في الرسالة.',
            throttle: 'طلبات كتير في وقت قصير، جرّب بعد شوية.',
            offline: 'تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.',
            generic: 'حصلت مشكلة، جرّب تاني بعد شوية.',
            ok: 'وصلنا طلبك. هنكلمك على الموبايل في أقرب وقت.'
        };

        function say(text, kind) { status.textContent = text; status.className = 'school-status' + (kind ? ' is-' + kind : ''); }
        function busy(on) { go.setAttribute('aria-busy', String(on)); go.disabled = on; }
        function setErr(name, text) {
            var input = document.getElementById(fields[name]);
            var msg = document.getElementById(fields[name] + '-err');
            if (!input || !msg) return false;
            msg.textContent = text || '';
            if (text) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
            return true;
        }
        function clearErrors() { Object.keys(fields).forEach(function (k) { setErr(k, ''); }); say(''); }
        function showErrors(errors) {
            var general = [];
            Object.keys(errors).forEach(function (k) { if (!setErr(k, errors[k][0])) general.push(errors[k][0]); });
            if (general.length) say(general[0], 'error');
            var bad = form.querySelector('[aria-invalid="true"]');
            if (bad) bad.focus();
        }
        function updateCount() { count.textContent = nf.format(f.message.value.length) + ' / 800'; }
        f.message.addEventListener('input', updateCount);
        Object.keys(fields).forEach(function (k) {
            f[k].addEventListener('input', function () { if (f[k].getAttribute('aria-invalid')) setErr(k, ''); });
        });

        function open(source, el) {
            clearErrors();
            form.hidden = false; done.hidden = true;
            if (audiences[state.tab]) f.audience.value = state.tab;
            f.plan_slug.value = '';
            if (source === 'calculator' && calc) {
                var s = calc.summary();
                f.plan_slug.value = s.plan;
                if (s.text && !f.message.value.trim()) f.message.value = s.text;
            }
            updateCount();
            opener = el || null;
            if (!crDialog.open) crDialog.showModal();
            f.name.focus();
            track('custom_request_open', { source: source });
        }
        crDialog.addEventListener('close', function () {
            if (opener && document.contains(opener)) opener.focus();
            opener = null;
        });
        crDialog.addEventListener('click', function (e) {
            if (e.target === crDialog || e.target.closest('[data-close-custom]')) crDialog.close();
        });
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-custom-request]');
            if (!btn) return;
            e.preventDefault();
            open(btn.getAttribute('data-source') || 'card', btn);
        });

        function validate() {
            var e = {};
            var name = f.name.value.trim(), phone = f.phone.value.replace(/[\s\-().]/g, ''), email = f.email.value.trim();
            var msg = f.message.value.trim(), students = f.students_estimate.value.trim();
            if (name.length < 2 || name.length > 80) e.name = [MSG.name];
            if (!PHONE.test(phone)) e.phone = [MSG.phone];
            if (email && !EMAIL.test(email)) e.email = [MSG.email];
            if (f.org_name.value.trim().length > 120) e.org_name = [MSG.org];
            if (!audiences[f.audience.value]) e.audience = [MSG.audience];
            if (students && !(/^\d+$/.test(students) && +students >= 1 && +students <= 1000000)) e.students_estimate = [MSG.students];
            if (msg.length < 10) e.message = [MSG.msgShort];
            else if (msg.length > 800) e.message = [MSG.msgLong];
            else if ((msg.match(/(?:https?:\/\/|www\.)/gi) || []).length > 2) e.message = [MSG.links];
            return e;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors();
            var local = validate();
            if (Object.keys(local).length) { showErrors(local); return; }
            var opt = function (v) { v = v.trim(); return v === '' ? undefined : v; };
            busy(true);
            say('جارٍ إرسال طلبك…');
            track('custom_request_submit');
            fetch('/api/plan-requests', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: f.name.value.trim(), phone: f.phone.value.trim(), email: opt(f.email.value),
                    org_name: opt(f.org_name.value), audience: f.audience.value,
                    students_estimate: f.students_estimate.value.trim() ? Number(f.students_estimate.value) : undefined,
                    plan_slug: opt(f.plan_slug.value), message: f.message.value.trim(), website: f.website.value
                })
            })
                .then(function (res) { return res.json().catch(function () { return {}; }).then(function (body) { return { ok: res.ok, status: res.status, body: body }; }); })
                .then(function (r) {
                    busy(false);
                    if (r.ok) {
                        track('custom_request_success');
                        form.reset(); updateCount(); say('');
                        document.getElementById('custom-done-text').textContent = r.body.message || MSG.ok;
                        form.hidden = true; done.hidden = false;
                        document.getElementById('custom-done-title').focus();
                        return;
                    }
                    track('custom_request_error', { status: r.status });
                    if (r.status === 429) { say(r.body.message || MSG.throttle, 'error'); return; }
                    if (r.status === 422 && r.body.errors) { say(''); showErrors(r.body.errors); return; }
                    say(MSG.generic, 'error');
                })
                .catch(function () { busy(false); track('custom_request_error', { status: 0 }); say(MSG.offline, 'error'); });
        });
    })();

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
        var panel = cta.closest('.pr-panel') || root.querySelector('.pr-panel.is-active'); // calculator: the active tab
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
