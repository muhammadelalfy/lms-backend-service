<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Reads handwriting in photos (homework). Server-side only; the key never
    // reaches the app. The model can be swapped without code changes.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'ocr_model' => env('ANTHROPIC_OCR_MODEL', 'claude-opus-5-5'),
    ],

    // Fawry (Pay at Fawry reference numbers and e-wallets such as Vodafone
    // Cash). Values come from the Fawry merchant account. Use the staging host
    // https://atfawry.fawrystaging.com while testing and Fawry's production
    // host when live; `webhook_url` must be reachable by Fawry.
    'fawry' => [
        'merchant_code' => env('FAWRY_MERCHANT_CODE'),
        'secure_key' => env('FAWRY_SECURE_KEY'),
        'base_url' => env('FAWRY_BASE_URL', 'https://atfawry.fawrystaging.com'),
        'webhook_url' => env('FAWRY_WEBHOOK_URL'),
        'reference_method' => env('FAWRY_REFERENCE_METHOD', 'PAYATFAWRY'),
        'expiry_hours' => (int) env('FAWRY_EXPIRY_HOURS', 48),
        // Fawry requires a customer e-mail; used when the payer has none.
        'fallback_email' => env('FAWRY_FALLBACK_EMAIL', 'payments@example.com'),
    ],

    // Firebase Cloud Messaging push (free). Path to a service-account JSON key
    // from Firebase console → Project settings → Service accounts.
    'fcm' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    // SMS through a local aggregator's HTTP API. The gateway receives a JSON
    // POST {"to": "+20…", "from": sender, "message": text} with the token as
    // a bearer header, answers with {"id": …}, and reports delivery to
    // POST /api/webhooks/sms with the header `X-Webhook-Secret`. Adjust
    // Modules\Notifications\Services\SmsGatewayChannel for a vendor whose API
    // differs.
    'sms' => [
        'url' => env('SMS_GATEWAY_URL'),
        'token' => env('SMS_GATEWAY_TOKEN'),
        'sender' => env('SMS_SENDER', 'Mudarisy'),
        'webhook_secret' => env('SMS_WEBHOOK_SECRET'),
    ],

    // WhatsApp Business Cloud API (Meta). Messages outside a 24-hour customer
    // window must use an approved template; `templates` maps a notice category
    // to its approved template name (body parameters: title, text).
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com/v20.0'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'ar'),
        'templates' => [
            'default' => env('WHATSAPP_TEMPLATE_DEFAULT', 'school_update'),
        ],
    ],

    // The AI service (lms-ai-service): exam materials, generation and grading help. Laravel calls it
    // with this bearer token and the school id in X-Center-Id; empty url = the AI features are off.
    'ai' => [
        'url' => env('AI_SERVICE_URL'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 30),
        'extract_timeout' => (int) env('AI_SERVICE_EXTRACT_TIMEOUT', 300),
    ],

    // Built-in voice and video: a self-hosted LiveKit server (open source, no
    // per-minute fee). `url` is the wss:// address the apps connect to; the
    // key and secret sign join tokens and verify its webhooks.
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'key' => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),
        'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 600),
    ],

    // Lets a teacher create Google Meet / Zoom meetings from the app. Register
    // an OAuth client with each provider and set its redirect to
    // https://<server>/api/integrations/<google|zoom>/callback. Without these
    // the teacher pastes a meeting link instead.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'zoom' => [
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'redirect' => env('ZOOM_REDIRECT_URI'),
    ],

];
