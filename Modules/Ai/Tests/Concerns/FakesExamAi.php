<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Ai\Models\ExamBlueprint;
use Modules\Ai\Models\Material;
use Modules\Auth\Models\User;

/**
 * A fake lms-ai-service speaking the exact /v1/exams contract (estimate, generate, verify). Knobs:
 * `$aiMaxPerCall` caps the questions one generate call writes (under-fill), `$aiWeakRefs` lists the
 * positions (0-based, per verify call) answered `unsupported` with low confidence, `$aiDuplicate`
 * makes the third question of a verify call a duplicate of the first.
 */
trait FakesExamAi
{
    private int $aiEstimateUnits = 10;

    private int $aiGenerateUnits = 4;

    private int $aiVerifyUnits = 1;

    private ?int $aiMaxPerCall = null;

    /** @var list<int> */
    private array $aiWeakPositions = [];

    private bool $aiDuplicate = false;

    private int $aiGenerated = 0;

    /** @var array<string, mixed> url pattern => response or closure, tried before the defaults */
    private array $aiOverrides = [];

    private bool $aiFaked = false;

    /**
     * Registers the fake once (Http::fake runs every registered stub and the first answer wins), and
     * on later calls only swaps the overrides, so a test can take the service down and bring it back.
     */
    private function fakeExamAi(array $overrides = []): void
    {
        $this->aiOverrides = $overrides;
        if ($this->aiFaked) {
            return;
        }
        $this->aiFaked = true;
        Http::fake(['ai.test/v1/exams/*' => fn (Request $r) => $this->aiRespond($r)]);
    }

    private function aiRespond(Request $request): mixed
    {
        foreach ($this->aiOverrides as $pattern => $response) {
            if (Str::is('*'.$pattern, $request->url())) {
                return $response instanceof \Closure ? $response($request) : $response;
            }
        }

        return match (Str::afterLast($request->url(), '/')) {
            'estimate' => Http::response([
                'units' => $this->aiEstimateUnits, 'input_tokens' => 16000, 'output_tokens' => 13000,
                'breakdown' => ['generate' => $this->aiEstimateUnits - 2, 'verify' => 2],
                'supported_questions' => 18, 'warnings' => [],
            ]),
            'generate' => Http::response($this->generated($request)),
            'verify' => Http::response($this->verified($request)),
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    private function generated(Request $request): array
    {
        $questions = [];
        $shortfall = [];
        $units = $this->aiGenerateUnits;
        $total = array_sum(array_column($request['blueprint']['types'], 'count'));
        $budget = $this->aiMaxPerCall ?? PHP_INT_MAX;
        $reason = 'insufficient_content';
        // Like the real service: `limit_units` cuts the request before anything is spent.
        if (isset($request['limit_units']) && $request['limit_units'] < $units) {
            $budget = min($budget, intdiv($total * $request['limit_units'], $units));
            $units = (int) $request['limit_units'];
            $reason = 'unit_limit';
        }
        foreach ($request['blueprint']['types'] as $type) {
            $made = min($type['count'], $budget);
            $budget -= $made;
            for ($i = 0; $i < $made; $i++) {
                $n = ++$this->aiGenerated;
                $questions[] = $this->question($type['type'], (int) $type['marks'], $n, $request['material_ids'][0], $request['replace_ref'] ?? null);
            }
            if ($made < $type['count']) {
                $shortfall[] = ['type' => $type['type'], 'requested' => $type['count'], 'produced' => $made, 'reason' => $reason];
            }
        }

        return [
            'prompt_version' => 'generate.v1', 'model' => 'claude-strong', 'units_used' => $units,
            'supported' => $shortfall === [], 'partial' => $shortfall !== [], 'questions' => $questions, 'shortfall' => $shortfall,
        ];
    }

    private function question(string $type, int $marks, int $n, string $materialId, ?string $ref): array
    {
        $base = [
            'ref' => $ref ?? "q{$n}", 'type' => $type, 'prompt' => "سؤال رقم {$n} عن التمثيل الضوئي", 'explanation' => "شرح {$n}",
            'marks' => (float) $marks, 'difficulty' => 'medium', 'bloom' => 'understand', 'outcome_ids' => ['O1'],
            'citation' => ['material_id' => $materialId, 'chunk_ref' => "c_{$n}", 'label' => 'صفحة 3', 'quote' => 'تصنع النباتات غذاءها', 'ocr' => false, 'ocr_confidence' => null],
        ];

        return match ($type) {
            'mcq' => $base + ['options' => ["أ{$n}", "ب{$n}", "ج{$n}", "د{$n}"], 'answer_index' => 1, 'correct_answer' => "ب{$n}", 'model_answer' => null, 'rubric' => null],
            'true_false' => $base + ['options' => ['صواب', 'خطأ'], 'answer_index' => 0, 'correct_answer' => 'صواب', 'model_answer' => null, 'rubric' => null],
            default => $base + ['options' => null, 'answer_index' => null, 'correct_answer' => "إجابة نموذجية {$n}", 'model_answer' => "إجابة نموذجية {$n}",
                'rubric' => ['criteria' => [['id' => 'c1', 'text' => 'يذكر الفكرة', 'marks' => $marks - 1], ['id' => 'c2', 'text' => 'يشرح', 'marks' => 1]], 'accept_alternatives' => ['بديل']]],
        };
    }

    private function verified(Request $request): array
    {
        $results = [];
        foreach ($request['questions'] as $i => $q) {
            $weak = in_array($i, $this->aiWeakPositions, true);
            $results[] = [
                'ref' => $q['ref'], 'verdict' => $weak ? 'unsupported' : 'supported', 'confidence' => $weak ? 0.4 : 0.93,
                'issues' => $weak ? ['الإجابة لا يدعمها المصدر'] : [], 'suggested_fix' => $weak ? 'راجع الخيار الصحيح' : null,
                'flagged' => $weak, 'duplicate_of' => $this->aiDuplicate && $i === 2 ? $request['questions'][0]['ref'] : null, 'bank_match' => null,
            ];
        }

        return ['prompt_version' => 'verify.v1', 'model' => 'claude-cheap', 'units_used' => $this->aiVerifyUnits, 'results' => $results];
    }

    /** A teacher with a ready material and a saved blueprint on it (10 MCQ x 1 + 2 essays x 5 = 20 marks by default). */
    private function readyBlueprint(?User $teacher = null, array $attributes = []): ExamBlueprint
    {
        $teacher ??= $this->teacher();
        $material = $this->material($teacher, ['status' => 'ready']);
        $material->sections()->create(['kind' => 'page', 'label' => 'صفحة 1', 'ordinal' => 1]);

        return $this->blueprint($teacher, $attributes + ['source_selection' => [['material_id' => $material->id, 'section_ids' => null]]]);
    }

    private function aiCalls(string $path): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), $path))->count();
    }

    private function readyMaterialOf(User $teacher): Material
    {
        return $this->material($teacher, ['status' => 'ready']);
    }
}
