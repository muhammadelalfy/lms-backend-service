<?php

namespace Modules\Exams\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Thin wrapper over the Google Forms REST API (forms.googleapis.com/v1). */
class GoogleFormsClient
{
    private const BASE = 'https://forms.googleapis.com/v1/forms';

    public function create(string $token, string $title): array
    {
        return $this->send($token, 'post', self::BASE, ['info' => ['title' => $title, 'documentTitle' => $title]]);
    }

    public function batchUpdate(string $token, string $formId, array $requests): array
    {
        return $this->send($token, 'post', self::BASE."/{$formId}:batchUpdate", ['requests' => $requests, 'includeFormInResponse' => true]);
    }

    /** Every response, following the pages. */
    public function responses(string $token, string $formId): array
    {
        $all = [];
        $page = null;
        do {
            $res = $this->send($token, 'get', self::BASE."/{$formId}/responses", array_filter(['pageToken' => $page]));
            array_push($all, ...($res['responses'] ?? []));
            $page = $res['nextPageToken'] ?? null;
        } while ($page);

        return $all;
    }

    private function send(string $token, string $method, string $url, array $payload): array
    {
        try {
            return Http::withToken($token)->timeout(20)->{$method}($url, $payload)->throw()->json() ?? [];
        } catch (RequestException $e) {
            $status = $e->response->status();
            throw new HttpException(in_array($status, [401, 403], true) ? 422 : 502, in_array($status, [401, 403], true)
                ? 'صلاحيات جوجل غير كافية. افصل حساب جوجل ثم اربطه من جديد ووافق على نماذج جوجل.'
                : 'تعذر الاتصال بنماذج جوجل. حاول بعد قليل.');
        }
    }
}
