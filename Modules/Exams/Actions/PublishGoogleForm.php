<?php

declare(strict_types=1);

namespace Modules\Exams\Actions;

use Modules\Auth\Models\User;
use Modules\Calls\Models\ProviderAccount;
use Modules\Calls\Services\Meetings\OAuthProvider;
use Modules\Exams\Models\ExamTemplate;
use Modules\Exams\Models\GoogleForm;
use Modules\Exams\Services\GoogleFormsClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Builds a graded Google Form (quiz) from an exam template: two required identity questions
 * (name, e-mail) then the template's questions with their answer keys and points.
 * Essay/math/geometry questions are added ungraded (paragraph) for the teacher to mark.
 */
final class PublishGoogleForm
{
    public function __construct(private readonly OAuthProvider $oauth, private readonly GoogleFormsClient $forms)
    {
    }

    public function handle(User $actor, ExamTemplate $template): GoogleForm
    {
        if (GoogleForm::query()->where('template_id', $template->id)->exists()) {
            throw new HttpException(409, 'تم إنشاء نموذج جوجل لهذا الامتحان من قبل.');
        }
        $account = ProviderAccount::query()->where(['user_id' => $actor->id, 'provider' => 'google'])->first();
        if ($account === null) {
            throw new HttpException(422, 'اربط حساب جوجل أولاً.');
        }
        $questions = $template->questions()->get();
        if ($questions->isEmpty()) {
            throw new HttpException(422, 'الامتحان بلا أسئلة.');
        }

        $token = $this->oauth->freshToken($account);
        $form = $this->forms->create($token, $template->title);
        $formId = $form['formId'];

        $requests = [['updateSettings' => ['settings' => ['quizSettings' => ['isQuiz' => true]], 'updateMask' => 'quizSettings.isQuiz']]];
        $index = 0;
        foreach ([['الاسم الكامل', false], ['البريد الإلكتروني', false]] as [$title]) {
            $requests[] = $this->item($index++, $title, ['textQuestion' => ['paragraph' => false]], null);
        }
        foreach ($questions as $question) {
            $requests[] = $this->item($index++, trim(strip_tags($question->prompt_html)), $this->questionBody($question), $question);
        }

        $updated = $this->forms->batchUpdate($token, $formId, $requests);

        // Items come back in order; skip the two identity questions.
        $items = array_slice($updated['form']['items'] ?? [], 2);
        $map = [];
        foreach ($questions->values() as $i => $question) {
            $googleId = $items[$i]['questionItem']['question']['questionId'] ?? null;
            if ($googleId !== null) {
                $map[$googleId] = $question->id;
            }
        }
        $identity = array_slice($updated['form']['items'] ?? [], 0, 2);
        $map['_name'] = $identity[0]['questionItem']['question']['questionId'] ?? null;
        $map['_email'] = $identity[1]['questionItem']['question']['questionId'] ?? null;

        return GoogleForm::create([
            'template_id' => $template->id,
            'created_by' => $actor->id,
            'form_id' => $formId,
            'responder_uri' => $form['responderUri'] ?? "https://docs.google.com/forms/d/{$formId}/viewform",
            'question_map' => $map,
        ]);
    }

    private function item(int $index, string $title, array $question, $source): array
    {
        $body = $question;
        if ($source === null) {
            $body['required'] = true;
        } elseif ($grading = $this->grading($source)) {
            $body['grading'] = $grading;
        }

        return ['createItem' => ['item' => ['title' => $title, 'questionItem' => ['question' => $body]], 'location' => ['index' => $index]]];
    }

    private function questionBody($question): array
    {
        $options = (array) $question->options;
        if ($question->type === 'true_false') {
            $options = ['صح', 'خطأ'];
        }
        if (in_array($question->type, ['mcq', 'true_false'], true) && $options !== []) {
            return ['required' => true, 'choiceQuestion' => ['type' => 'RADIO', 'options' => array_map(fn ($o) => ['value' => (string) $o], array_values($options))]];
        }

        return ['required' => true, 'textQuestion' => ['paragraph' => true]];
    }

    private function grading($question): ?array
    {
        if (! in_array($question->type, ['mcq', 'true_false'], true) || $question->correct_answer === null) {
            return null;
        }
        $options = $question->type === 'true_false' ? ['صح', 'خطأ'] : array_values((array) $question->options);
        $answer = (string) $question->correct_answer;
        if (ctype_digit($answer) && isset($options[(int) $answer]) && ! in_array($answer, $options, true)) {
            $answer = (string) $options[(int) $answer];
        }
        if ($question->type === 'true_false') {
            $answer = in_array(strtolower($answer), ['true', '1', 'صح'], true) ? 'صح' : 'خطأ';
        }

        return ['pointValue' => (int) $question->points, 'correctAnswers' => ['answers' => [['value' => $answer]]]];
    }
}
