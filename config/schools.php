<?php

/*
 * The plan catalog: the ONE place a plan's limits, price, features and audience tabs are written.
 * Landing, sign-up, limits and the web app all derive from it through PlanCatalog (shown and
 * yearly prices are never stored, so an edit here cannot drift).
 *
 * A school's own `max_students` column or `data.limits` (set by the operator) overrides its plan's;
 * a limit of null means unlimited. Prices are EGP per month, whole pounds.
 */

// Features every public plan has. A tiered feature is true | false | 'soon' ('soon' renders "قريباً",
// never a tick: do not sell what is not shipped).
$everyPlan = [
    'branding' => true, 'access_codes' => true, 'exams' => true, 'live_lessons' => true,
    'parent_accounts' => true, 'notifications_push' => true, 'chat' => true, 'mobile_apps' => true,
    'youtube_video' => true, 'files_attachments' => true, 'attendance_reports' => true,
    'grade_reports' => true, 'payments_online' => true,
];
$small = [
    'assistants_role' => 'soon', 'sms_whatsapp_messages' => false, 'qr_face_attendance' => false,
    'installments' => false, 'revenue_reports_per_teacher' => false, 'custom_domain' => false,
    'priority_support' => false, 'dedicated_manager' => false,
    'ai_exams' => false,
];
$mid = ['ai_exams' => 'soon', 'sms_whatsapp_messages' => true, 'qr_face_attendance' => true, 'installments' => 'soon', 'revenue_reports_per_teacher' => 'soon'] + $small;
$big = ['custom_domain' => 'soon'] + $mid;
$top = ['priority_support' => true] + $big;
$flagship = ['dedicated_manager' => true] + $top;

// AI exams caps per plan: [units per month, material storage in MB, materials count]. The feature is
// off (0) below t3. `ai_exams` stays 'soon' until the generation sprints ship; flipping it to true
// is a config change only (a school can also be switched on alone, see School::feature()).
$ai = [
    0 => [0, 0, 0],
    3 => [300, 500, 50], 4 => [600, 1024, 100], 5 => [1500, 3072, 250],
    6 => [3000, 5120, 500], 7 => [6000, 10240, 1000], 8 => [6000, 10240, 1000],
];

// The flip of `ai_exams` from 'soon' to true, per tier (AI_EXAMS_LIVE_TIERS=t3,t4). Set it only after the
// AI service's budgets are shared across replicas (S63) and the evaluation gate (S73) passed. A tier
// whose flag is false (t1, t2) never turns on this way.
$aiLive = array_values(array_filter(array_map('trim', explode(',', (string) env('AI_EXAMS_LIVE_TIERS', '')))));
$aiFlag = fn (int $n, array $flags) => ($flags['ai_exams'] ?? false) === 'soon' && in_array("t{$n}", $aiLive, true)
    ? ['ai_exams' => true] + $flags
    : $flags;

$tier = fn (int $n, string $label, array $limits, int $list, array $flags) => [
    'public' => true, 'tier' => $n, 'label' => $label, 'list_monthly' => $list,
    'limits' => array_combine(['students', 'teachers', 'subjects', 'classes_per_subject', 'assistants', 'storage_gb'], $limits)
        + array_combine(['ai_units_monthly', 'ai_material_storage_mb', 'ai_materials_max'], $ai[$n] ?? $ai[0]),
    'features' => $everyPlan + $aiFlag($n, $flags),
];

return [
    'trial_days' => 7,           // one value for every trial (owner decision D1)
    'trial_max_plan' => 't3',    // a trial never gets more than this plan's limits
    'default_plan' => 't1',      // used by the CLI/operator when none is given

    'pricing' => [
        'currency' => 'EGP',
        'vat_included' => true,  // owner decision: listed prices include VAT
        'promo_percent' => 40,   // 0 = no promo; shown = round(list x (1 - percent/100))
        'promo_ends_at' => null, // null = always shown; a past date switches the promo off
        'promo_label' => 'خصم الإطلاق',
        'yearly_months_billed' => 10,
        'addons' => ['extra_student' => 5, 'extra_storage_gb' => 0.5],
        'audiences' => [
            'center' => ['label' => 'سنتر دروس', 'intro' => 'كل مدرسينك ومساعديك وحضورك وتحصيلك في مكان واحد.', 'kind' => 'center',
                'plans' => ['t3', 't4', 't5', 't6', 't7', 't8'], 'trial_plan' => 't3', 'recommended' => 't4'],
            'group' => ['label' => 'مجموعة مدرسين', 'intro' => 'فريق مدرسين تحت اسم واحد، وكل مدرس بطلابه ومجموعاته.', 'kind' => 'group',
                'plans' => ['t3', 't4', 't5', 't6', 't7', 't8'], 'trial_plan' => 't3', 'recommended' => 't4'],
            'solo' => ['label' => 'مدرس فردي', 'intro' => 'منصة باسمك لطلابك: دورات وحصص واختبارات وأولياء أمور.', 'kind' => 'solo',
                'plans' => ['t1', 't2', 't3', 't4', 't5'], 'trial_plan' => 't1', 'recommended' => 't2'],
        ],
        // The comparison table: type limit reads plan.limits[key]; type feature reads plan.features[key].
        'feature_rows' => [
            ['group' => 'السعة', 'rows' => [
                ['key' => 'students', 'label' => 'عدد الطلاب', 'type' => 'limit'],
                ['key' => 'teachers', 'label' => 'عدد المدرسين', 'type' => 'limit'],
                ['key' => 'subjects', 'label' => 'عدد المواد', 'type' => 'limit'],
                ['key' => 'classes_per_subject', 'label' => 'الفصول/المجموعات لكل مادة', 'type' => 'limit'],
                ['key' => 'assistants', 'label' => 'المساعدون', 'type' => 'limit'],
                ['key' => 'storage_gb', 'label' => 'مساحة التخزين (GB)', 'type' => 'limit'],
            ]],
            ['group' => 'الطلاب وأولياء الأمور', 'rows' => [
                ['key' => 'access_codes', 'label' => 'أكواد دخول للطلاب', 'type' => 'feature'],
                ['key' => 'parent_accounts', 'label' => 'حسابات أولياء الأمور', 'type' => 'feature'],
                ['key' => 'notifications_push', 'label' => 'تنبيهات الغياب (إشعار)', 'type' => 'feature'],
                ['key' => 'sms_whatsapp_messages', 'label' => 'رسائل SMS وواتساب', 'type' => 'feature'],
                ['key' => 'chat', 'label' => 'محادثات داخل المنصة', 'type' => 'feature'],
            ]],
            ['group' => 'الحصص والمحتوى', 'rows' => [
                ['key' => 'live_lessons', 'label' => 'حصص أونلاين برابط اجتماع', 'type' => 'feature'],
                ['key' => 'youtube_video', 'label' => 'دروس بفيديو يوتيوب/فيميو', 'type' => 'feature'],
                ['key' => 'files_attachments', 'label' => 'ملفات ومرفقات', 'type' => 'feature'],
            ]],
            ['group' => 'الاختبارات والمتابعة', 'rows' => [
                ['key' => 'exams', 'label' => 'اختبارات وتصحيح', 'type' => 'feature'],
                ['key' => 'ai_exams', 'label' => 'امتحانات بالذكاء الاصطناعي من موادك', 'type' => 'feature'],
                ['key' => 'attendance_reports', 'label' => 'تقارير الحضور', 'type' => 'feature'],
                ['key' => 'grade_reports', 'label' => 'تقارير الدرجات والتقدم', 'type' => 'feature'],
            ]],
            ['group' => 'الحضور في السنتر', 'rows' => [
                ['key' => 'qr_face_attendance', 'label' => 'حضور QR وبالوجه', 'type' => 'feature'],
            ]],
            ['group' => 'الدفع والإيراد', 'rows' => [
                ['key' => 'payments_online', 'label' => 'دفع إلكتروني (فوري)', 'type' => 'feature'],
                ['key' => 'installments', 'label' => 'أقساط', 'type' => 'feature'],
                ['key' => 'revenue_reports_per_teacher', 'label' => 'إيراد كل مدرس ونسبة المدرس', 'type' => 'feature'],
            ]],
            ['group' => 'الهوية والدعم', 'rows' => [
                ['key' => 'branding', 'label' => 'اسم وشعار وألوان المركز', 'type' => 'feature'],
                ['key' => 'custom_domain', 'label' => 'نطاق خاص', 'type' => 'feature'],
                ['key' => 'priority_support', 'label' => 'دعم بأولوية', 'type' => 'feature'],
                ['key' => 'dedicated_manager', 'label' => 'مدير حساب', 'type' => 'feature'],
            ]],
        ],
    ],

    'plans' => [
        //                 tier label       students teachers subjects classes assistants GB  list/month
        't1' => $tier(1, 'انطلاقة', [50, 1, 2, 3, 0, 5], 500, $small),
        't2' => $tier(2, 'نمو', [100, 1, 3, 5, 1, 10], 800, $small),
        't3' => $tier(3, 'تقدّم', [250, 3, 4, 8, 2, 25], 1500, $mid),
        't4' => $tier(4, 'تميّز', [500, 5, 6, 12, 4, 50], 2600, $mid),
        't5' => $tier(5, 'احتراف', [1000, 10, 8, 20, 8, 100], 4500, $big),
        't6' => $tier(6, 'ريادة', [2500, 20, 12, 40, 15, 250], 9000, $top),
        't7' => $tier(7, 'قمة', [5000, 40, 20, 80, 25, 500], 16000, $top),
        't8' => $tier(8, 'امتداد', [10000, 80, 30, 150, 40, 1000], 28000, $flagship),

        // Legacy plans: not public, not listed by /api/plans, kept so schools that already have
        // them keep working. Only `students` is capped; the other limits are unlimited (null).
        'trial' => ['public' => false, 'tier' => 0, 'label' => 'تجريبية', 'limits' => ['students' => 30]],
        'basic' => ['public' => false, 'tier' => 0, 'label' => 'أساسية', 'limits' => ['students' => 200]],
        'pro' => ['public' => false, 'tier' => 0, 'label' => 'احترافية', 'limits' => ['students' => 1000]],
        'enterprise' => ['public' => false, 'tier' => 0, 'label' => 'مؤسسات', 'limits' => ['students' => null]],
    ],

    // Names a school cannot take as its subdomain.
    'reserved_slugs' => ['www', 'api', 'app', 'admin', 'central', 'mail', 'ftp', 'static', 'cdn', 'ws', 'media', 'turn', 'status', 'support', 'billing'],

    // A school's own settings (stored with the school) that replace the platform's.
    'settings' => [
        'fawry_merchant_code' => 'services.fawry.merchant_code',
        'fawry_secure_key' => 'services.fawry.secure_key',
        'sms_gateway_url' => 'services.sms.url',
        'sms_gateway_token' => 'services.sms.token',
        'sms_sender' => 'services.sms.sender',
        'sms_webhook_secret' => 'services.sms.webhook_secret',
        'whatsapp_token' => 'services.whatsapp.token',
        'whatsapp_phone_number_id' => 'services.whatsapp.phone_number_id',
        'whatsapp_app_secret' => 'services.whatsapp.app_secret',
        'whatsapp_verify_token' => 'services.whatsapp.verify_token',
    ],
];
