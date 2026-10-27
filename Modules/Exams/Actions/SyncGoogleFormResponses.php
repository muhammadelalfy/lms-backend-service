<?php

declare(strict_types=1);

namespace Modules\Exams\Actions;

use Modules\Auth\Models\User;
use Modules\Calls\Models\ProviderAccount;
use Modules\Calls\Services\Meetings\OAuthProvider;
use Modules\Exams\Models\GoogleForm;
use Modules\Exams\Models\GoogleFormResponse;
use Modules\Exams\Services\GoogleFormsClient;
use Modules\Students\Models\Student;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Pulls the submissions, stores score and per-question right/wrong, and matches each to a student. */
final class SyncGoogleFormResponses
{
    public function __construct(private readonly OAuthProvider $oauth, private readonly GoogleFormsClient $forms)
    {
    }

    /** @return int how many responses are new */
    public function handle(User $actor, GoogleForm $form): int
    {
        $account = ProviderAccount::query()->where(['user_id' => $form->created_by, 'provider' => 'google'])->first();
        if ($account === null) {
            throw new HttpException(422, 'اربط حساب جوجل أولاً.');
        }
        $token = $this->oauth->freshToken($account);
        $map = $form->question_map;
        $max = (float) $form->template->questions()->sum('points');
        $known = $form->responses()->pluck('id', 'response_id');
        $new = 0;

        foreach ($this->forms->responses($token, $form->form_id) as $response) {
            $answers = $response['answers'] ?? [];
            $name = $this->text($answers[$map['_name'] ?? ''] ?? null);
            $email = strtolower((string) $this->text($answers[$map['_email'] ?? ''] ?? null));
            $detail = [];
            foreach ($answers as $googleId => $answer) {
                if (! isset($map[$googleId]) || str_starts_with((string) $googleId, '_')) {
                    continue;
                }
                $grade = $answer['grade'] ?? [];
                $detail[] = [
                    'question_id' => $map[$googleId],
                    'answer' => $this->text($answer),
                    'correct' => $grade['correct'] ?? null,
                    'points' => (float) ($grade['score'] ?? 0),
                ];
            }

            $row = GoogleFormResponse::query()->updateOrCreate(
                ['google_form_id' => $form->id, 'response_id' => $response['responseId']],
                [
                    'respondent_name' => $name, 'respondent_email' => $email ?: null,
                    'student_id' => $this->student($email, $name),
                    'score' => (float) ($response['totalScore'] ?? 0), 'max_score' => $max,
                    'answers' => $detail, 'submitted_at' => $response['lastSubmittedTime'] ?? $response['createTime'] ?? now(),
                ],
            );
            $new += $known->has($row->response_id) ? 0 : 1;
        }
        $form->update(['synced_at' => now()]);

        return $new;
    }

    private function text(?array $answer): ?string
    {
        $value = $answer['textAnswers']['answers'][0]['value'] ?? null;

        return $value === null ? null : trim((string) $value);
    }

    /** By the account's e-mail first, then an exact name match when it is unambiguous. */
    private function student(string $email, ?string $name): ?int
    {
        if ($email !== '') {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->with('studentAccount')->first();
            if ($user?->studentAccount !== null) {
                return $user->studentAccount->student_id;
            }
        }
        if ($name) {
            $matches = Student::query()->where('name', $name)->limit(2)->pluck('id');

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return null;
    }
}
