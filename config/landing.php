<?php

/*
|--------------------------------------------------------------------------
| Public landing page
|--------------------------------------------------------------------------
|
| Store links and contact details shown on the central domain's home page.
| A link left empty renders its button as "soon" instead of a dead link.
|
*/

return [
    'name' => env('LANDING_NAME', 'مُدرّسى'),
    'android_url' => env('LANDING_ANDROID_URL', ''),
    'ios_url' => env('LANDING_IOS_URL', ''),
    'web_url' => env('LANDING_WEB_URL', ''),
    'contact_email' => env('LANDING_CONTACT_EMAIL', ''),
    // Where the Android APKs and latest.json live (persistent storage volume; served by /download/android).
    'downloads_dir' => env('LANDING_DOWNLOADS_DIR', storage_path('app/public/downloads')),
];
