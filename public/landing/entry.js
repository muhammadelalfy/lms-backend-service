/* Landing: mobile menu and the "enter your school code" box. Without JS the page still reads fine. */
(function () {
    'use strict';
    var bar = document.getElementById('top-bar');
    var toggle = document.getElementById('nav-toggle');
    var links = document.getElementById('nav-links');

    // ---- mobile menu -------------------------------------------------------------------------
    function setMenu(open) {
        if (!bar || !toggle) return;
        bar.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'إغلاق القائمة' : 'القائمة');
    }
    function menuOpen() { return !!bar && bar.classList.contains('nav-open'); }

    if (toggle && bar) {
        toggle.addEventListener('click', function () { setMenu(!menuOpen()); });
        if (links) links.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
        document.addEventListener('click', function (e) { if (menuOpen() && !bar.contains(e.target)) setMenu(false); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && menuOpen()) { setMenu(false); toggle.focus(); }
        });
        window.addEventListener('resize', function () { if (window.innerWidth > 900 && menuOpen()) setMenu(false); });
        // The header tucks away while scrolling down; an open menu would be left hanging.
        window.addEventListener('scroll', function () { if (menuOpen()) setMenu(false); }, { passive: true });
    }

    // ---- school code -------------------------------------------------------------------------
    var dialog = document.getElementById('school-dialog');
    if (!dialog) return;
    var form = document.getElementById('school-form');
    var input = document.getElementById('school-code');
    var status = document.getElementById('school-status');
    var go = form.querySelector('.school-go');
    var STORE = 'mudarisy-school-code';
    var CODE = /^[a-z0-9][a-z0-9-]{0,40}$/;

    function remembered() { try { return window.localStorage.getItem(STORE) || ''; } catch (e) { return ''; } }
    function remember(code) { try { window.localStorage.setItem(STORE, code); } catch (e) { /* private mode */ } }
    function say(text, kind) { status.textContent = text; status.className = 'school-status' + (kind ? ' is-' + kind : ''); }
    function busy(on) { go.setAttribute('aria-busy', String(on)); go.disabled = on; }

    function open() {
        setMenu(false);
        say('');
        input.value = remembered();
        if (typeof dialog.showModal === 'function') { if (!dialog.open) dialog.showModal(); } else { dialog.setAttribute('open', ''); }
        input.focus();
        input.select();
    }
    function close() { if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open'); }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-open-school]')) { e.preventDefault(); open(); }
        else if (e.target.closest('[data-close-school]')) { close(); }
        else if (e.target === dialog) { close(); } // a click on the dimmed backdrop
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var code = input.value.trim().toLowerCase();
        if (!CODE.test(code)) { say('الرمز يتكوّن من حروف إنجليزية صغيرة وأرقام وشرطة فقط.', 'error'); input.focus(); return; }

        busy(true);
        say('جارٍ البحث عن مركزك…');
        fetch('/api/school-lookup?code=' + encodeURIComponent(code), { headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (res.status === 429) { throw { kind: 'limit' }; }
                if (res.status === 404) { throw { kind: 'none' }; }
                if (!res.ok) { throw { kind: 'fail' }; }
                return res.json();
            })
            .then(function (school) {
                remember(code);
                say('تم العثور على ' + school.name + '، جارٍ التحويل…', 'ok');
                window.location.assign(school.url);
            })
            .catch(function (err) {
                busy(false);
                if (err && err.kind === 'none') say('لم نجد مركز بهذا الرمز. تأكد من الرمز الذي أرسلته لك مركزك.', 'error');
                else if (err && err.kind === 'limit') say('محاولات كثيرة. انتظر دقيقة ثم حاول مرة أخرى.', 'error');
                else say('تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.', 'error');
                input.focus();
            });
    });
})();
