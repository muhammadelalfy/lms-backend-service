<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>لوحة المشغّل — {{ config('landing.name') }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b1d3a">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/favicon-32.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('operator/operator.css') }}">
</head>
<body>
<script type="application/json" id="operator-config">{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>

{{-- Sign in: the operator token, kept only for this browser tab. --}}
<main class="gate" id="gate">
    <form class="gate-card" id="gate-form" autocomplete="off">
        <img src="{{ asset('brand/mudarisy_logo_navy.png') }}" alt="{{ config('landing.name') }}" width="160" height="48">
        <h1>لوحة المشغّل</h1>
        <p>إدارة المراكز على المنصة. أدخل رمز المشغّل للمتابعة.</p>
        <label for="token">رمز المشغّل</label>
        <input id="token" type="password" dir="ltr" autocomplete="off" autocapitalize="none" spellcheck="false" required>
        <p class="note error" id="gate-error" role="alert" hidden></p>
        <button class="btn primary" type="submit">دخول</button>
        <p class="hint">الرمز هو <span dir="ltr">CENTRAL_API_TOKEN</span> على الخادم. لا يُحفظ إلا في هذه النافذة.</p>
    </form>
</main>

<div class="app" id="app" hidden>
    <header class="bar">
        <div class="brand">
            <img src="{{ asset('brand/mudarisy_mark_reversed.png') }}" alt="" width="28" height="36">
            <strong>{{ config('landing.name') }}</strong>
            <span class="chip">لوحة المشغّل</span>
        </div>
        <div class="bar-actions">
            <button class="btn ghost" type="button" id="refresh">تحديث</button>
            <button class="btn ghost" type="button" id="logout">خروج</button>
        </div>
    </header>

    <main class="wrap">
        <section class="stats" aria-label="ملخص">
            <div class="stat"><span>المراكز</span><b id="stat-total">–</b></div>
            <div class="stat"><span>نشطة</span><b id="stat-active">–</b></div>
            <div class="stat"><span>موقوفة أو منتهية</span><b id="stat-locked">–</b></div>
            <div class="stat"><span>الطلاب</span><b id="stat-students">–</b></div>
        </section>

        <section class="tools">
            <div class="search"><label for="q" class="sr">بحث</label><input id="q" type="search" placeholder="ابحث باسم المركز أو كودها أو نطاقها"></div>
            <div class="filters" role="group" aria-label="التصفية" id="filters">
                <button type="button" class="pill is-on" data-filter="all">الكل</button>
                <button type="button" class="pill" data-filter="active">نشطة</button>
                <button type="button" class="pill" data-filter="locked">موقوفة</button>
                <button type="button" class="pill" data-filter="trial-ended">انتهت تجربتها</button>
            </div>
            <button class="btn primary" type="button" id="new-school">+ مركز جديدة</button>
        </section>

        <p class="note error" id="list-error" role="alert" hidden></p>
        <div class="table" id="table" role="table" aria-label="المراكز"></div>
        <p class="empty" id="empty" hidden>لا توجد مراكز مطابقة.</p>
    </main>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

{{-- Open a school --}}
<dialog class="dlg" id="dlg-create">
    <form method="dialog" class="dlg-card" id="form-create" novalidate>
        <header><h2>مركز جديدة</h2><button type="button" class="x" data-close aria-label="إغلاق">&times;</button></header>
        <div class="grid2">
            <div class="field"><label for="c-slug">كود المركز (العنوان)</label>
                <div class="affix" dir="ltr"><input id="c-slug" name="slug" maxlength="40" required autocapitalize="none" spellcheck="false" placeholder="alnour"><span id="c-base"></span></div></div>
            <div class="field"><label for="c-name">اسم المركز</label><input id="c-name" name="name" maxlength="120" required></div>
            <div class="field"><label for="c-plan">الباقة</label><select id="c-plan" name="plan"></select></div>
            <div class="field"><label for="c-max">حد الطلاب (اختياري)</label><input id="c-max" name="max_students" type="number" min="1" inputmode="numeric" placeholder="حسب الباقة"></div>
        </div>
        <fieldset class="field-set"><legend>مدير المركز</legend>
            <div class="grid2">
                <div class="field"><label for="c-aname">الاسم</label><input id="c-aname" name="admin_name" maxlength="120" required></div>
                <div class="field"><label for="c-aemail">البريد الإلكتروني</label><input id="c-aemail" name="admin_email" type="email" dir="ltr" required></div>
            </div>
            <div class="field"><label for="c-apass">كلمة المرور</label>
                <div class="affix" dir="ltr"><input id="c-apass" name="admin_password" minlength="8" maxlength="100" required autocomplete="new-password"><button type="button" class="mini" id="gen-pass">توليد</button></div></div>
        </fieldset>
        <label class="check"><input type="checkbox" name="demo"> إضافة بيانات تجريبية للمركز</label>
        <p class="note error" data-error role="alert" hidden></p>
        <footer><button type="button" class="btn ghost" data-close>إلغاء</button><button class="btn primary" type="submit">فتح المركز</button></footer>
    </form>
</dialog>

{{-- Result of opening a school: shown once --}}
<dialog class="dlg" id="dlg-created">
    <div class="dlg-card">
        <header><h2>تم فتح المركز</h2><button type="button" class="x" data-close aria-label="إغلاق">&times;</button></header>
        <p>سلّم هذه البيانات لمدير المركز. كلمة المرور لا تُعرض مرة أخرى.</p>
        <dl class="creds" id="created-creds"></dl>
        <footer><button type="button" class="btn ghost" id="copy-creds">نسخ البيانات</button><button type="button" class="btn primary" data-close>تم</button></footer>
    </div>
</dialog>

{{-- Edit --}}
<dialog class="dlg" id="dlg-edit">
    <form method="dialog" class="dlg-card" id="form-edit" novalidate>
        <header><h2 id="edit-title">تعديل المركز</h2><button type="button" class="x" data-close aria-label="إغلاق">&times;</button></header>
        <div class="grid2">
            <div class="field"><label for="e-name">اسم المركز</label><input id="e-name" name="name" maxlength="120" required></div>
            <div class="field"><label for="e-plan">الباقة</label><select id="e-plan" name="plan"></select></div>
            <div class="field"><label for="e-max">حد الطلاب</label><input id="e-max" name="max_students" type="number" min="1" inputmode="numeric" placeholder="حسب الباقة"></div>
            <div class="field"><label for="e-trial">نهاية الفترة التجريبية</label><input id="e-trial" name="trial_ends_at" type="date"></div>
        </div>
        <p class="note error" data-error role="alert" hidden></p>
        <footer><button type="button" class="btn ghost" data-close>إلغاء</button><button class="btn primary" type="submit">حفظ</button></footer>
    </form>
</dialog>

{{-- Domain --}}
<dialog class="dlg" id="dlg-domain">
    <form method="dialog" class="dlg-card" id="form-domain" novalidate>
        <header><h2 id="domain-title">إضافة نطاق</h2><button type="button" class="x" data-close aria-label="إغلاق">&times;</button></header>
        <p>عنوان إضافي للمركز، مثل موقعها الخاص. يجب أن يشير إلى الخادم أولاً.</p>
        <div class="field"><label for="d-domain">النطاق</label><input id="d-domain" name="domain" dir="ltr" required placeholder="school.example.com" autocapitalize="none" spellcheck="false"></div>
        <p class="note error" data-error role="alert" hidden></p>
        <footer><button type="button" class="btn ghost" data-close>إلغاء</button><button class="btn primary" type="submit">إضافة</button></footer>
    </form>
</dialog>

{{-- Confirm a reversible action (suspending a school) --}}
<dialog class="dlg" id="dlg-confirm">
    <div class="dlg-card">
        <header><h2 id="confirm-title">تأكيد</h2><button type="button" class="x" data-close aria-label="إغلاق">&times;</button></header>
        <p id="confirm-text"></p>
        <footer><button type="button" class="btn ghost" data-close>إلغاء</button><button type="button" class="btn primary" id="confirm-go">تأكيد</button></footer>
    </div>
</dialog>

{{-- Delete: the school's code must be typed --}}
<dialog class="dlg" id="dlg-delete">
    <form method="dialog" class="dlg-card" id="form-delete" novalidate>
        <header><h2>حذف المركز نهائياً</h2><button type="button" class="x" data-close aria-label="إغلاق">&times;</button></header>
        <p class="danger-text">سيُحذف حساب المركز وقاعدة بياناتها بكل طلابها وسجلاتها، ولا يمكن التراجع.</p>
        <div class="field"><label for="x-confirm">اكتب كود المركز <b dir="ltr" id="x-slug"></b> للتأكيد</label><input id="x-confirm" dir="ltr" autocomplete="off" autocapitalize="none" spellcheck="false" required></div>
        <p class="note error" data-error role="alert" hidden></p>
        <footer><button type="button" class="btn ghost" data-close>إلغاء</button><button class="btn danger" type="submit" id="x-go" disabled>حذف نهائياً</button></footer>
    </form>
</dialog>

<script src="{{ asset('operator/operator.js') }}" defer></script>
</body>
</html>
