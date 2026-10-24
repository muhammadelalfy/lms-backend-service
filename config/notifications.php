<?php

/*
 * How notices reach people beyond the in-app inbox and push.
 *
 * `chains` lists, per category, the paid channels to fall back to and how many
 * minutes after the notice each is tried. A step is skipped when the person
 * already read the notice in the app, has not switched that channel on, or the
 * channel's cap is spent. `critical` categories ignore quiet hours and cannot
 * have push turned off.
 */
return [
    'categories' => [
        'absence' => ['label' => 'الغياب', 'critical' => true],
        'payment' => ['label' => 'المدفوعات', 'critical' => true],
        'session' => ['label' => 'مواعيد الحصص', 'critical' => false],
        'duty' => ['label' => 'الواجبات', 'critical' => false],
        'message' => ['label' => 'رسائل المركز', 'critical' => false],
        'exam_result' => ['label' => 'نتائج الاختبارات', 'critical' => false],
        'worksheet' => ['label' => 'أوراق العمل', 'critical' => false],
        'chat' => ['label' => 'المحادثات', 'critical' => false],
        'call' => ['label' => 'المكالمات', 'critical' => false],
    ],

    'chains' => [
        'absence' => [['whatsapp', 10], ['sms', 30]],
        'payment' => [['whatsapp', 60], ['sms', 240]],
    ],

    // Most paid sends per channel per day, school-wide. null = no cap.
    'daily_caps' => [
        'whatsapp' => env('NOTIFY_WHATSAPP_DAILY_CAP') ? (int) env('NOTIFY_WHATSAPP_DAILY_CAP') : null,
        'sms' => env('NOTIFY_SMS_DAILY_CAP') ? (int) env('NOTIFY_SMS_DAILY_CAP') : null,
    ],

    // Sends per second a channel's workers may make (the provider's limit).
    'rate_per_second' => [
        'whatsapp' => (int) env('NOTIFY_WHATSAPP_RATE', 40),
        'sms' => (int) env('NOTIFY_SMS_RATE', 20),
    ],

    'verification' => ['ttl_minutes' => 10, 'max_attempts' => 5, 'sends_per_hour' => 3],
];
