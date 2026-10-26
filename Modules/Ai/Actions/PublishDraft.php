<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Ai\Enums\DraftStatus;
use Modules\Ai\Enums\JobStatus;
use Modules\Ai\Enums\QuestionState;
use Modules\Ai\Events\DraftPublished;
use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\DraftQuestion;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Models\QuestionRubric;
use Modules\Ai\Services\DraftAudit;
use Modules\Ai\Support\DraftReadiness;
use Modules\Auth\Models\AuditLog;
use Modules\Auth\Models\User;
use Modules\Exams\Models\ExamQuestion;
use Modules\Exams\Models\ExamTemplate;

/**
 * Publishes an approved draft into the existing Exams module: one ExamTemplate and its ExamQuestion
 * rows, exactly the shape the manual builder makes, so the exam runner and the mobile app need no
 * change. Types map mcq -> mcq, true_false -> true_false, short_answer / fill_blank / essay -> essay;
 * the rubric and model answer go to `exam_question_rubrics`, explanations and provenance stay in the
 * AI tables. One transaction with the draft row locked; publishing twice returns the same template.
 * The template is a `draft` unless the teacher picks `published`, so the existing flow still decides
 * when students see it. The draft is frozen afterwards.
 */
final class PublishDraft
{
    public function __construct(private readonly DraftAudit $audit) {}

    /**
     * @param  array{status?: string, department_id?: int|null, override_total?: bool}  $options
     * @return array{template: ExamTemplate, created: bool}
     *
     * @throws GenerationRefused with the first blocker as code and all of them in `blockers`
     */
    public function handle(User $actor, DraftExam $draft, array $options): array
    {
        $result = DB::transaction(function () use ($actor, $draft, $options) {
            $locked = DraftExam::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($locked->template_id !== null) {
                return ['template' => ExamTemplate::query()->findOrFail($locked->template_id), 'created' => false];
            }

            $live = DraftQuestion::query()->where('draft_id', $locked->id)->whereIn('state', QuestionState::liveValues())
                ->orderBy('ordinal')->orderBy('id')->get();
            $generating = GenerationJob::query()->where('draft_id', $locked->id)->whereIn('status', JobStatus::activeValues())->exists();
            $blockers = DraftReadiness::blockers($locked, $live, $generating, (bool) ($options['override_total'] ?? false));
            if ($blockers !== []) {
                throw new GenerationRefused($blockers[0], DraftReadiness::MESSAGES[$blockers[0]], $blockers[0] === 'generating' ? 409 : 422, [
                    'blockers' => array_map(fn ($c) => ['code' => $c, 'message' => DraftReadiness::MESSAGES[$c]], $blockers),
                ]);
            }
            $this->ensureAnswerable($live->all());

            $template = ExamTemplate::create([
                'department_id' => $options['department_id'] ?? null,
                'created_by' => $actor->id,
                'title' => $locked->title,
                'grade' => $locked->grade_level,
                'duration_minutes' => max(1, $locked->duration_minutes),
                'status' => $options['status'] ?? 'draft',
            ]);
            $this->insertQuestions($template, $live->all());

            $locked->forceFill([
                'status' => DraftStatus::Published->value, 'template_id' => $template->id,
                'approved_by' => $actor->id, 'approved_at' => now(), 'published_at' => now(),
            ])->save();
            $details = ['template_id' => $template->id, 'questions' => $live->count(), 'status' => $template->status, 'total_marks' => (int) $live->sum('marks')];
            $this->audit->record($locked->id, $actor, 'published', null, $details);
            AuditLog::create([
                'actor_id' => $actor->id, 'action' => 'ai_exams.draft_published', 'subject_type' => 'ai_draft', 'subject_id' => $locked->id,
                'subject_label' => mb_substr($locked->title, 0, 190), 'details' => $details, 'created_at' => now(),
            ]);

            return ['template' => $template, 'created' => true];
        });

        if ($result['created']) {
            event(new DraftPublished($draft->id, $result['template']->id, $actor->id));
        }

        return $result;
    }

    /** @param  list<DraftQuestion>  $questions */
    private function ensureAnswerable(array $questions): void
    {
        foreach ($questions as $q) {
            if (! $q->type->isWritten() && ! in_array($q->correct_answer, (array) $q->options, true)) {
                throw new GenerationRefused('invalid_question', 'الإجابة الصحيحة لسؤال رقم '.$q->ordinal.' ليست من خياراته. عدّل السؤال ثم انشر.', 422, ['question_id' => $q->id]);
            }
        }
    }

    /**
     * One insert for the questions and one for their rubrics (no query per question).
     *
     * @param  list<DraftQuestion>  $questions
     */
    private function insertQuestions(ExamTemplate $template, array $questions): void
    {
        $now = now();
        $rows = [];
        foreach (array_values($questions) as $i => $q) {
            $written = $q->type->isWritten();
            $rows[] = [
                'template_id' => $template->id,
                'type' => $q->type->examType(),
                'prompt_html' => '<p>'.nl2br(e($q->prompt), false).'</p>',
                'options' => $written ? null : json_encode(array_values((array) $q->options), JSON_UNESCAPED_UNICODE),
                // Written answers are marked by the teacher (with the rubric), never compared to a key.
                'correct_answer' => $written ? null : $q->correct_answer,
                'points' => $q->marks,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        ExamQuestion::query()->insert($rows);

        $ids = ExamQuestion::query()->where('template_id', $template->id)->orderBy('sort_order')->pluck('id')->all();
        $rubrics = [];
        foreach (array_values($questions) as $i => $q) {
            if ($q->type->isWritten() && ($q->model_answer !== null || ! empty($q->rubric))) {
                $rubrics[] = [
                    'question_id' => $ids[$i], 'model_answer' => $q->model_answer,
                    'rubric' => empty($q->rubric) ? null : json_encode($q->rubric, JSON_UNESCAPED_UNICODE),
                    'source' => 'ai_draft', 'ai_draft_question_id' => $q->id, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        if ($rubrics !== []) {
            QuestionRubric::query()->insert($rubrics);
        }
    }
}
