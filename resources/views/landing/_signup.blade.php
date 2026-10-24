{{-- The free-trial sign-up box. public/landing/pricing.js opens it from a plan card with kind, plan and billing preselected,
     checks the address against /api/school-lookup, and posts to /api/signup. The server stays the authority on every rule. --}}
@php
    $suPlans = app(\Modules\Tenancy\Services\PlanCatalog::class)->cached()['plans'];
    $suAudiences = app(\Modules\Tenancy\Services\PlanCatalog::class)->cached()['audiences'];
@endphp
<dialog class="school-dialog" id="signup-dialog" aria-labelledby="signup-title">
    <form class="school-card signup-card" id="signup-form" novalidate>
        <button type="button" class="school-x" data-close-signup aria-label="إغلاق">&times;</button>
        <h2 id="signup-title" tabindex="-1">ابدأ تجربتك المجانية</h2>
        <p class="school-help">تجهّز مركزك في دقيقة. تقدر تغيّر كل شيء بعدها.</p>

        <label for="su-kind">نوع النشاط</label>
        <select id="su-kind" name="kind" class="su-input">
            @foreach ($suAudiences as $a)<option value="{{ $a['kind'] }}">{{ $a['label'] }}</option>@endforeach
        </select>

        <label for="su-plan">الباقة</label>
        <select id="su-plan" name="plan" class="su-input" aria-describedby="su-plan-hint">
            @foreach ($suPlans as $p)<option value="{{ $p['slug'] }}">{{ $p['name'] }} — حتى {{ number_format($p['limits']['students']) }} طالب</option>@endforeach
        </select>

        <label for="su-billing">مدة الدفع بعد التجربة</label>
        <select id="su-billing" name="billing" class="su-input" aria-describedby="su-plan-hint">
            <option value="monthly">شهري</option>
            <option value="yearly">سنوي</option>
        </select>
        <p class="su-hint" id="su-plan-hint" aria-live="polite"></p>

        <label for="su-name">اسم المركز</label>
        <input id="su-name" name="name" class="su-input" maxlength="120" required autocomplete="organization" aria-describedby="su-name-err">
        <p class="su-err" id="su-name-err"></p>

        <label for="su-slug">عنوان المركز</label>
        <div class="school-field">
            <input id="su-slug" name="slug" dir="ltr" autocapitalize="none" spellcheck="false" maxlength="40" required placeholder="alnour" aria-describedby="su-slug-err">
            <span dir="ltr" aria-hidden="true">.{{ $base }}</span>
        </div>
        <p class="su-err" id="su-slug-err" aria-live="polite"></p>

        <label for="su-admin">اسمك</label>
        <input id="su-admin" name="admin_name" class="su-input" maxlength="120" required autocomplete="name" aria-describedby="su-admin-err">
        <p class="su-err" id="su-admin-err"></p>

        <label for="su-email">البريد الإلكتروني</label>
        <input id="su-email" name="email" type="email" dir="ltr" class="su-input" maxlength="190" required autocomplete="email" aria-describedby="su-email-err">
        <p class="su-err" id="su-email-err"></p>

        <label for="su-pass">كلمة المرور (8 أحرف على الأقل)</label>
        <input id="su-pass" name="password" type="password" dir="ltr" class="su-input" minlength="8" maxlength="100" required autocomplete="new-password" aria-describedby="su-pass-err">
        <p class="su-err" id="su-pass-err"></p>

        <input type="text" name="website" class="su-trap" tabindex="-1" autocomplete="off" aria-hidden="true">
        <p class="school-status" id="signup-status" role="status" aria-live="polite"></p>
        <button class="btn btn-gold school-go" type="submit">افتح مركزي</button>
    </form>
    <div class="school-card signup-done" id="signup-done" hidden>
        <button type="button" class="school-x" data-close-signup aria-label="إغلاق">&times;</button>
        <h2 id="signup-done-title" tabindex="-1">تم فتح مركزك</h2>
        <p class="school-help" id="signup-done-text"></p>
        <a class="btn btn-gold school-go" id="signup-done-link" href="#">ادخل إلى مركزك</a>
    </div>
</dialog>
