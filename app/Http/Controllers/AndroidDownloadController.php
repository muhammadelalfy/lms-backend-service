<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AndroidRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The public download of the Android app (the landing's «أندرويد» button). */
class AndroidDownloadController extends Controller
{
    public function download(Request $request): Response
    {
        $release = AndroidRelease::fromConfig();
        $abi = $request->query('abi') === 'armv7' ? 'armv7' : 'arm64';
        $path = $release->file($abi);
        if ($path === null) {
            return $this->missing($request);
        }

        $summary = $release->summary();
        $version = $summary['version_name'] ?? null;
        $name = 'mudarisy'.($version ? '-'.$version : '').($abi === 'armv7' ? '-armv7' : '').'.apk';

        $response = response()->download($path, $name, [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($sha = $summary['files'][$abi]['sha256'] ?? null) {
            $response->setEtag($sha);
        }
        $response->isNotModified($request);

        return $response;
    }

    public function manifest(Request $request): JsonResponse
    {
        $summary = AndroidRelease::fromConfig()->summary();
        if ($summary === null) {
            return response()->json(['message' => 'تطبيق أندرويد غير متاح للتحميل بعد.'], 404);
        }
        foreach (array_keys($summary['files']) as $abi) {
            $summary['files'][$abi]['url'] = url('/download/android'.($abi === 'armv7' ? '?abi=armv7' : ''));
        }

        return response()->json($summary + ['url' => url('/download/android')])
            ->header('Cache-Control', 'public, max-age=60');
    }

    private function missing(Request $request): Response
    {
        $message = 'تطبيق أندرويد غير متاح للتحميل بعد. جرّب لاحقاً أو ادخل من المتصفح.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 404)
            : response('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>غير متاح</title><body style="font-family:sans-serif;text-align:center;padding:48px">'.e($message).' <a href="/">الرئيسية</a></body></html>', 404, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
