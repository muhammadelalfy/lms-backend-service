{{-- The custom-plan request box ("اطلب باقة مخصصة"). public/landing/pricing.js opens it from the custom card, the
     calculator or the footer line, and posts JSON to /api/plan-requests. The server validates and answers in Arabic; the
     checks here only save a round trip. Without JS the buttons that open it are mailto links instead. --}}
@php
    $crAudiences = app(\Modules\Tenancy\Services\PlanCatalog::class)->cached()['audiences'];
@endphp
<dialog class="school-dialog" id="custom-dialog" aria-labelledby="custom-title">
    <form class="school-card signup-card" id="custom-form" novalidate>
        <button type="button" class="school-x" data-close-custom aria-label="إغلاق">&times;</button>
        <h2 id="custom-title" tabindex="-1">اطلب باقة مخصصة</h2>
        <p class="school-help">احكيلنا عن مركزك ونجهّز لك عرض يناسبك. الحقول المعلّمة بـ<span aria-hidden="true">*</span><span class="sr-only">نجمة</span> مطلوبة.</p>

        <label for="cr-name">الاسم <span aria-hidden="true">*</span></label>
        <input id="cr-name" name="name" class="su-input" minlength="2" maxlength="80" required autocomplete="name" aria-describedby="cr-name-err">
        <p class="su-err" id="cr-name-err"></p>

        <label for="cr-phone">رقم الموبايل <span aria-hidden="true">*</span></label>
        <input id="cr-phone" name="phone" type="tel" dir="ltr" class="su-input" maxlength="20" required autocomplete="tel" inputmode="tel" placeholder="01012345678" aria-describedby="cr-phone-err">
        <p class="su-err" id="cr-phone-err"></p>

        <label for="cr-email">البريد الإلكتروني (اختياري)</label>
        <input id="cr-email" name="email" type="email" dir="ltr" class="su-input" maxlength="190" autocomplete="email" aria-describedby="cr-email-err">
        <p class="su-err" id="cr-email-err"></p>

        <label for="cr-org">اسم المركز (اختياري)</label>
        <input id="cr-org" name="org_name" class="su-input" maxlength="120" autocomplete="organization" aria-describedby="cr-org-err">
        <p class="su-err" id="cr-org-err"></p>

        <label for="cr-audience">نوع النشاط <span aria-hidden="true">*</span></label>
        <select id="cr-audience" name="audience" class="su-input" required aria-describedby="cr-audience-err">
            @foreach ($crAudiences as $a)<option value="{{ $a['key'] }}">{{ $a['label'] }}</option>@endforeach
        </select>
        <p class="su-err" id="cr-audience-err"></p>

        <label for="cr-students">عدد الطلاب التقريبي (اختياري)</label>
        <input id="cr-students" name="students_estimate" type="number" dir="ltr" class="su-input" min="1" max="1000000" step="1" inputmode="numeric" aria-describedby="cr-students-err">
        <p class="su-err" id="cr-students-err"></p>

        <label for="cr-message">وصف الاحتياج <span aria-hidden="true">*</span></label>
        <textarea id="cr-message" name="message" class="su-input cr-message" rows="4" minlength="10" maxlength="800" required aria-describedby="cr-message-count cr-message-err"></textarea>
        <p class="cr-count"><span id="cr-message-count" dir="ltr">0 / 800</span></p>
        <p class="su-err" id="cr-message-err"></p>

        <input type="hidden" name="plan_slug" value="">
        <input type="text" name="website" class="su-trap" tabindex="-1" autocomplete="off" aria-hidden="true">
        <p class="school-status" id="custom-status" role="status" aria-live="polite"></p>
        <button class="btn btn-gold school-go" type="submit">ابعت طلبك</button>
    </form>
    <div class="school-card signup-done" id="custom-done" hidden>
        <button type="button" class="school-x" data-close-custom aria-label="إغلاق">&times;</button>
        <h2 id="custom-done-title" tabindex="-1">تم إرسال طلبك</h2>
        <p class="school-help" id="custom-done-text">وصلنا طلبك. هنكلمك على الموبايل في أقرب وقت.</p>
        <button type="button" class="btn btn-gold school-go" data-close-custom>تمام</button>
    </div>
</dialog>
