/* Operator dashboard: opens, changes, suspends and closes schools through /api/central. */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('operator-config').textContent);
    var STORE = 'mudarisy-operator-token';
    var SLUG = /^[a-z0-9][a-z0-9-]{0,39}$/;
    var state = { token: readToken(), schools: [], filter: 'all', query: '', loading: false };

    function $(selector, root) { return (root || document).querySelector(selector); }
    function el(tag, attrs) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') node.textContent = attrs[key];
            else if (key === 'class') node.className = attrs[key];
            else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2), attrs[key]);
            else node.setAttribute(key, attrs[key]);
        });
        for (var i = 2; i < arguments.length; i++) if (arguments[i]) node.appendChild(arguments[i]);
        return node;
    }
    function readToken() { try { return window.sessionStorage.getItem(STORE) || ''; } catch (e) { return ''; } }
    function writeToken(value) { try { value ? window.sessionStorage.setItem(STORE, value) : window.sessionStorage.removeItem(STORE); } catch (e) { /* private mode */ } }

    // ---- talking to the API ---------------------------------------------------------------
    function api(path, options) {
        options = options || {};
        return fetch('/api/central' + path, {
            method: options.method || 'GET',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer ' + state.token },
            body: options.body ? JSON.stringify(options.body) : undefined
        }).then(function (res) {
            if (res.status === 204) return null;
            return res.json().catch(function () { return {}; }).then(function (body) {
                if (res.ok) return body;
                var err = new Error(messageOf(res.status, body));
                err.status = res.status;
                throw err;
            });
        });
    }
    function messageOf(status, body) {
        if (status === 401) return 'رمز المشغّل غير صحيح.';
        if (status === 404 && !body.message) return 'واجهة المشغّل غير مفعّلة على الخادم (CENTRAL_API_TOKEN غير مضبوط).';
        if (body && body.errors) {
            // Laravel's default validation text is English; say what is wrong in Arabic for the fields on this page.
            var arabic = {
                domain: 'صيغة النطاق غير صحيحة. مثال: school.example.com',
                slug: 'كود المركز غير صالح أو مستخدم من قبل.',
                name: 'اسم المركز مطلوب (حتى 120 حرفاً).',
                plan: 'الباقة غير صحيحة.',
                max_students: 'حد الطلاب عدد صحيح أكبر من صفر.',
                trial_ends_at: 'تاريخ نهاية التجربة غير صحيح.',
                'admin.name': 'اسم المدير مطلوب.',
                'admin.email': 'بريد المدير غير صحيح.',
                'admin.password': 'كلمة مرور المدير 8 أحرف على الأقل.'
            };
            return Object.keys(body.errors).map(function (k) { return arabic[k] || body.errors[k][0]; }).join(' ');
        }
        return (body && body.message) || 'تعذّر تنفيذ الطلب. حاول مرة أخرى.';
    }

    // ---- sign in / out --------------------------------------------------------------------
    var gate = $('#gate'), app = $('#app');
    function showApp(on) { gate.hidden = on; app.hidden = !on; }
    function signOut(message) {
        state.token = ''; writeToken('');
        showApp(false);
        var box = $('#gate-error');
        box.hidden = !message; box.textContent = message || '';
        $('#token').value = '';
        $('#token').focus();
    }
    $('#gate-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var value = $('#token').value.trim();
        if (!value) return;
        state.token = value;
        $('#gate-error').hidden = true;
        load().then(function (ok) { if (ok) writeToken(value); });
    });
    $('#logout').addEventListener('click', function () { signOut(''); });
    $('#refresh').addEventListener('click', function () { load(); });

    // ---- the list -------------------------------------------------------------------------
    function stateOf(s) {
        if (s.status === 'suspended') return 'locked';
        return s.active ? 'active' : 'trial-ended';
    }
    var STATE_LABEL = { active: 'نشطة', locked: 'موقوفة', 'trial-ended': 'انتهت التجربة' };
    function planLabel(key) {
        var plan = cfg.plans.filter(function (p) { return p.key === key; })[0];
        return plan ? plan.label : key;
    }

    function load() {
        state.loading = true;
        renderLoading();
        return api('/schools?usage=1').then(function (body) {
            state.schools = body.data || [];
            state.loading = false;
            showApp(true);
            $('#list-error').hidden = true;
            render();
            return true;
        }).catch(function (err) {
            state.loading = false;
            if (err.status === 401 || err.status === 404) { signOut(err.message); return false; }
            showApp(true);
            var box = $('#list-error'); box.hidden = false; box.textContent = err.message;
            $('#table').replaceChildren();
            return false;
        });
    }

    function renderLoading() {
        if (state.schools.length) return;
        var table = $('#table');
        table.replaceChildren();
        for (var i = 0; i < 3; i++) table.appendChild(el('div', { class: 'row' }, el('div', { class: 'skeleton' }), el('div', { class: 'skeleton' }), el('div', { class: 'skeleton' })));
    }

    function visible() {
        var q = state.query.trim().toLowerCase();
        return state.schools.filter(function (s) {
            if (state.filter === 'active' && stateOf(s) !== 'active') return false;
            if (state.filter === 'locked' && stateOf(s) !== 'locked') return false;
            if (state.filter === 'trial-ended' && stateOf(s) !== 'trial-ended') return false;
            if (!q) return true;
            return [s.name, s.id].concat(s.domains || []).some(function (v) { return String(v).toLowerCase().indexOf(q) !== -1; });
        });
    }

    function render() {
        var all = state.schools;
        $('#stat-total').textContent = all.length;
        $('#stat-active').textContent = all.filter(function (s) { return stateOf(s) === 'active'; }).length;
        $('#stat-locked').textContent = all.filter(function (s) { return stateOf(s) !== 'active'; }).length;
        $('#stat-students').textContent = all.reduce(function (sum, s) { return sum + (s.students_count || 0); }, 0);

        var rows = visible(), table = $('#table');
        table.replaceChildren();
        $('#empty').hidden = rows.length > 0;
        if (!rows.length) return;

        table.appendChild(el('div', { class: 'row head', role: 'row' },
            el('div', { text: 'المركز' }), el('div', { text: 'الباقة' }), el('div', { text: 'الحالة' }),
            el('div', { text: 'الطلاب' }), el('div', { text: 'العناوين' }), el('div', { text: '' })));
        rows.forEach(function (school) { table.appendChild(row(school)); });
    }

    function row(s) {
        var st = stateOf(s);
        var limit = s.student_limit, count = s.students_count;
        var usage = el('div', { class: 'usage', 'data-label': 'الطلاب' });
        usage.appendChild(el('span', { class: 'count', text: (count === null || count === undefined ? '–' : count) + ' / ' + (limit === null ? '∞' : limit) }));
        if (limit && count !== null && count !== undefined) {
            var pct = Math.min(100, Math.round(count / limit * 100));
            usage.appendChild(el('span', { class: 'bar-mini' }, el('i', { class: pct >= 100 ? 'full' : '', style: 'width:' + pct + '%' })));
        }

        var domains = el('div', { class: 'domains' });
        (s.domains || []).forEach(function (d) {
            domains.appendChild(el('a', { href: location.protocol + '//' + d + '/login', target: '_blank', rel: 'noopener', text: d }));
        });

        var actions = el('div', { class: 'actions' },
            el('button', { type: 'button', class: 'btn ghost small', text: 'تعديل', onclick: function () { openEdit(s); } }));
        if (st !== 'trial-ended') {
            var suspended = st === 'locked';
            actions.appendChild(el('button', { type: 'button', class: 'btn ghost small', text: suspended ? 'تفعيل' : 'إيقاف', onclick: function () { toggleStatus(s, suspended ? 'active' : 'suspended'); } }));
        }
        actions.appendChild(el('button', { type: 'button', class: 'btn ghost small', text: 'نطاق', onclick: function () { openDomain(s); } }));
        actions.appendChild(el('button', { type: 'button', class: 'btn ghost small', text: 'حذف', onclick: function () { openDelete(s); } }));

        return el('div', { class: 'row', role: 'row' },
            el('div', { class: 'cell-school' }, el('b', { text: s.name }), el('small', { text: s.id })),
            el('div', { 'data-label': 'الباقة' }, el('span', { class: 'badge plan', text: planLabel(s.plan) }),
                s.trial_ends_at && s.desired_plan && s.desired_plan !== s.plan ? el('small', { class: 'desired', text: 'يريد ' + planLabel(s.desired_plan) }) : null),
            el('div', { 'data-label': 'الحالة' }, el('span', { class: 'badge ' + st, text: STATE_LABEL[st] })),
            usage,
            el('div', { class: 'domains-cell', 'data-label': 'العناوين' }, domains),
            el('div', { class: 'actions-cell' }, actions));
    }

    $('#q').addEventListener('input', function (e) { state.query = e.target.value; render(); });
    $('#filters').addEventListener('click', function (e) {
        var pill = e.target.closest('[data-filter]');
        if (!pill) return;
        state.filter = pill.getAttribute('data-filter');
        Array.prototype.forEach.call(document.querySelectorAll('#filters .pill'), function (p) { p.classList.toggle('is-on', p === pill); });
        render();
    });

    // ---- dialogs --------------------------------------------------------------------------
    function openDialog(dlg) {
        var error = $('[data-error]', dlg);
        if (error) { error.hidden = true; error.textContent = ''; }
        if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    }
    function closeDialog(dlg) { if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open'); }
    Array.prototype.forEach.call(document.querySelectorAll('dialog'), function (dlg) {
        dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.closest('[data-close]')) closeDialog(dlg); });
        var title = $('h2', dlg);
        if (title) { title.id = title.id || dlg.id + '-title'; dlg.setAttribute('aria-labelledby', title.id); }
    });
    function fail(dlg, err) {
        if (err.status === 401) { closeDialog(dlg); signOut(err.message); return; }
        var box = $('[data-error]', dlg); box.hidden = false; box.textContent = err.message;
    }
    function busy(button, on) { button.setAttribute('aria-busy', String(on)); }
    function toast(text, isError) {
        var node = el('div', { class: 'toast' + (isError ? ' error' : ''), text: text });
        $('#toasts').appendChild(node);
        setTimeout(function () { node.remove(); }, 4500);
    }
    function fillPlans(select, selected) {
        select.replaceChildren();
        cfg.plans.forEach(function (p) {
            var hint = p.max_students === null ? 'بلا حد' : p.max_students + ' طالب';
            var option = el('option', { value: p.key, text: p.label + ' (' + hint + ')' });
            if (p.key === selected) option.selected = true;
            select.appendChild(option);
        });
    }
    function field(form, name) { return form.elements[name]; }
    function intOrNull(value) { value = String(value).trim(); return value === '' ? null : parseInt(value, 10); }

    // Open a school
    var createForm = $('#form-create'), createDlg = $('#dlg-create');
    $('#c-base').textContent = '.' + cfg.base_domain;
    $('#new-school').addEventListener('click', function () {
        createForm.reset();
        fillPlans($('#c-plan'), cfg.default_plan);
        openDialog(createDlg);
        $('#c-slug').focus();
    });
    $('#gen-pass').addEventListener('click', function () {
        var chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', bytes = new Uint32Array(16), out = '';
        window.crypto.getRandomValues(bytes);
        for (var i = 0; i < bytes.length; i++) out += chars[bytes[i] % chars.length];
        $('#c-apass').value = out + '!7';
    });
    createForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var slug = field(createForm, 'slug').value.trim().toLowerCase();
        if (!SLUG.test(slug)) return fail(createDlg, new Error('الكود حروف إنجليزية صغيرة وأرقام وشرطة، ويبدأ بحرف أو رقم.'));
        var button = $('[type=submit]', createForm);
        var payload = {
            slug: slug, name: field(createForm, 'name').value.trim(), plan: field(createForm, 'plan').value,
            demo: field(createForm, 'demo').checked,
            admin: { name: field(createForm, 'admin_name').value.trim(), email: field(createForm, 'admin_email').value.trim(), password: field(createForm, 'admin_password').value }
        };
        var max = intOrNull(field(createForm, 'max_students').value);
        if (max !== null) payload.max_students = max;
        busy(button, true);
        api('/schools', { method: 'POST', body: payload }).then(function (school) {
            busy(button, false);
            closeDialog(createDlg);
            showCreated(school, payload.admin);
            return load();
        }).catch(function (err) { busy(button, false); fail(createDlg, err); });
    });
    function showCreated(school, admin) {
        var url = location.protocol + '//' + (school.domains && school.domains[0] || school.id + '.' + cfg.base_domain) + '/login';
        var list = $('#created-creds');
        list.replaceChildren();
        [['عنوان الدخول', url], ['البريد', admin.email], ['كلمة المرور', admin.password]].forEach(function (pair) {
            list.appendChild(el('div', {}, el('dt', { text: pair[0] }), el('dd', { text: pair[1] })));
        });
        openDialog($('#dlg-created'));
    }
    $('#copy-creds').addEventListener('click', function () {
        var lines = Array.prototype.map.call($('#created-creds').children, function (row) { return row.children[0].textContent + ': ' + row.children[1].textContent; }).join('\n');
        (navigator.clipboard ? navigator.clipboard.writeText(lines) : Promise.reject()).then(function () { toast('تم النسخ.'); }, function () { toast('تعذّر النسخ، انسخ البيانات يدوياً.', true); });
    });

    // Edit
    var editForm = $('#form-edit'), editDlg = $('#dlg-edit'), editing = null;
    function openEdit(school) {
        editing = school;
        $('#edit-title').textContent = 'تعديل ' + school.name;
        field(editForm, 'name').value = school.name;
        fillPlans($('#e-plan'), school.plan);
        field(editForm, 'max_students').value = school.max_students === null ? '' : school.max_students;
        field(editForm, 'trial_ends_at').value = school.trial_ends_at ? school.trial_ends_at.slice(0, 10) : '';
        field(editForm, 'activate').checked = false;
        var desired = $('#e-desired');
        desired.hidden = !school.desired_plan;
        desired.textContent = school.desired_plan
            ? 'طلب عند التسجيل: ' + planLabel(school.desired_plan) + (school.preferred_billing === 'yearly' ? ' (سنوي)' : ' (شهري)')
            : '';
        openDialog(editDlg);
    }
    editForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var button = $('[type=submit]', editForm), trial = field(editForm, 'trial_ends_at').value;
        var activate = field(editForm, 'activate').checked;
        busy(button, true);
        api('/schools/' + encodeURIComponent(editing.id), { method: 'PATCH', body: {
            name: field(editForm, 'name').value.trim(), plan: field(editForm, 'plan').value,
            max_students: intOrNull(field(editForm, 'max_students').value), trial_ends_at: activate ? null : (trial || null),
            activate: activate
        } }).then(function (school) {
            busy(button, false); closeDialog(editDlg); toast('تم حفظ التعديلات.');
            // A plan smaller than what the center uses is saved; say what is over (nothing is removed).
            var CAP = { students: 'الطلاب', teachers: 'المدرسين', subjects: 'المواد', classes_per_subject: 'الفصول لكل مادة', assistants: 'المساعدين', storage_gb: 'التخزين' };
            ((school && school.warnings) || []).forEach(function (w) {
                toast('تنبيه: ' + (CAP[w.cap] || w.cap) + ' ' + w.used + ' والحد الجديد ' + w.limit + '. لن يُحذف شيء، والإضافة موقوفة حتى يقل العدد.', true);
            });
            return load();
        })
            .catch(function (err) { busy(button, false); fail(editDlg, err); });
    });

    // A reversible action asks first, in the page's own dialog.
    var confirmDlg = $('#dlg-confirm');
    function confirmBox(title, text, label) {
        $('#confirm-title').textContent = title;
        $('#confirm-text').textContent = text;
        $('#confirm-go').textContent = label;
        openDialog(confirmDlg);
        return new Promise(function (resolve) {
            function done(result) { confirmDlg.removeEventListener('close', onClose); $('#confirm-go').removeEventListener('click', onGo); resolve(result); }
            function onGo() { done(true); closeDialog(confirmDlg); }
            function onClose() { done(false); }
            $('#confirm-go').addEventListener('click', onGo);
            confirmDlg.addEventListener('close', onClose);
        });
    }

    function toggleStatus(school, status) {
        var suspending = status === 'suspended';
        var proceed = suspending
            ? confirmBox('إيقاف ' + school.name, 'لن يستطيع أحد من المركز الدخول حتى تعيد تفعيلها. بياناتها تبقى كما هي.', 'إيقاف المركز')
            : Promise.resolve(true);
        proceed.then(function (ok) {
            if (!ok) return;
            return api('/schools/' + encodeURIComponent(school.id), { method: 'PATCH', body: { status: status } })
                .then(function () { toast(suspending ? 'تم إيقاف المركز.' : 'تم تفعيل المركز.'); return load(); })
                .catch(function (err) { if (err.status === 401) signOut(err.message); else toast(err.message, true); });
        });
    }

    // Domain
    var domainForm = $('#form-domain'), domainDlg = $('#dlg-domain'), domainFor = null;
    function openDomain(school) {
        domainFor = school;
        $('#domain-title').textContent = 'إضافة نطاق لـ ' + school.name;
        domainForm.reset();
        openDialog(domainDlg);
        $('#d-domain').focus();
    }
    domainForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var button = $('[type=submit]', domainForm);
        busy(button, true);
        api('/schools/' + encodeURIComponent(domainFor.id) + '/domains', { method: 'POST', body: { domain: field(domainForm, 'domain').value.trim() } })
            .then(function () { busy(button, false); closeDialog(domainDlg); toast('تمت إضافة النطاق.'); return load(); })
            .catch(function (err) { busy(button, false); fail(domainDlg, err); });
    });

    // Delete: the school's code has to be typed
    var deleteForm = $('#form-delete'), deleteDlg = $('#dlg-delete'), deleting = null;
    function openDelete(school) {
        deleting = school;
        $('#x-slug').textContent = school.id;
        $('#x-confirm').value = '';
        $('#x-go').disabled = true;
        openDialog(deleteDlg);
        $('#x-confirm').focus();
    }
    $('#x-confirm').addEventListener('input', function (e) { $('#x-go').disabled = e.target.value.trim() !== deleting.id; });
    deleteForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var button = $('#x-go');
        busy(button, true);
        api('/schools/' + encodeURIComponent(deleting.id) + '?confirm=' + encodeURIComponent(deleting.id), { method: 'DELETE' })
            .then(function () { busy(button, false); closeDialog(deleteDlg); toast('تم حذف المركز.'); return load(); })
            .catch(function (err) { busy(button, false); fail(deleteDlg, err); });
    });

    // ---- plan requests inbox ------------------------------------------------------------------
    var REQ_TYPE = { custom: 'باقة مخصصة', upgrade: 'ترقية', lead: 'تواصل' };
    var REQ_STATUS = { 'new': 'جديد', contacted: 'تم التواصل', closed: 'مغلق' };
    var AUDIENCE = { solo: 'مدرس فردي', group: 'مجموعة مدرسين', center: 'سنتر دروس' };
    state.reqFilter = 'new';

    function loadRequests() {
        var query = state.reqFilter ? '?status=' + encodeURIComponent(state.reqFilter) : '';
        return api('/plan-requests' + query).then(function (body) {
            $('#req-error').hidden = true;
            var counts = (body.meta && body.meta.counts) || {};
            Array.prototype.forEach.call(document.querySelectorAll('[data-count]'), function (n) {
                var c = counts[n.getAttribute('data-count')];
                n.textContent = c ? '(' + c + ')' : '';
            });
            renderRequests(body.data || []);
        }).catch(function (err) {
            if (err.status === 401) { signOut(err.message); return; }
            var box = $('#req-error'); box.hidden = false; box.textContent = err.message;
        });
    }

    function renderRequests(rows) {
        var table = $('#req-table');
        table.replaceChildren();
        $('#req-empty').hidden = rows.length > 0;
        if (!rows.length) return;
        table.appendChild(el('div', { class: 'req-row head', role: 'row' },
            el('div', { text: 'صاحب الطلب' }), el('div', { text: 'النوع' }), el('div', { text: 'الاحتياج' }), el('div', { text: 'الحالة' })));
        rows.forEach(function (r) {
            var who = el('div', {}, el('b', { text: r.name }));
            if (r.phone) who.appendChild(el('small', {}, el('a', { href: 'tel:' + r.phone, dir: 'ltr', text: r.phone })));
            if (r.email) who.appendChild(el('small', { dir: 'ltr', text: r.email }));
            if (r.org_name) who.appendChild(el('small', { text: r.org_name }));

            var kind = el('div', {}, el('b', { text: REQ_TYPE[r.type] || r.type }));
            if (r.school_id) kind.appendChild(el('small', { dir: 'ltr', text: r.school_id }));
            if (r.plan_slug) kind.appendChild(el('small', { text: 'الباقة: ' + planLabel(r.plan_slug) }));
            if (r.audience) kind.appendChild(el('small', { text: AUDIENCE[r.audience] || r.audience }));
            if (r.students_estimate) kind.appendChild(el('small', { text: r.students_estimate + ' طالب تقريباً' }));

            var select = el('select', { 'aria-label': 'حالة طلب ' + r.name, onchange: function (e) { moveRequest(r, e.target.value); } });
            Object.keys(REQ_STATUS).forEach(function (key) {
                var option = el('option', { value: key, text: REQ_STATUS[key] });
                if (key === r.status) option.selected = true;
                select.appendChild(option);
            });
            var when = el('small', { text: r.created_at ? new Date(r.created_at).toLocaleString('ar-EG') : '' });

            table.appendChild(el('div', { class: 'req-row', role: 'row' }, who, kind, el('div', { class: 'msg', text: r.message }), el('div', {}, select, when)));
        });
    }

    function moveRequest(r, status) {
        api('/plan-requests/' + encodeURIComponent(r.id), { method: 'PATCH', body: { status: status } })
            .then(function () { toast('تم تحديث حالة الطلب.'); return loadRequests(); })
            .catch(function (err) { if (err.status === 401) signOut(err.message); else toast(err.message, true); });
    }

    $('#req-filters').addEventListener('click', function (e) {
        var pill = e.target.closest('[data-req-filter]');
        if (!pill) return;
        state.reqFilter = pill.getAttribute('data-req-filter');
        Array.prototype.forEach.call(document.querySelectorAll('#req-filters .pill'), function (p) { p.classList.toggle('is-on', p === pill); });
        loadRequests();
    });
    // Loading the centers also refreshes the inbox (sign-in, the refresh button, after every change).
    var loadSchools = load;
    load = function () { return loadSchools().then(function (ok) { if (ok) loadRequests(); return ok; }); };

    // ---- start ----------------------------------------------------------------------------
    if (state.token) { load().then(function (ok) { if (!ok && gate.hidden && app.hidden) signOut(''); }); } else { $('#token').focus(); }
})();
