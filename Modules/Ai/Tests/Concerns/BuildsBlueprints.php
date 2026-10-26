<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Concerns;

use Modules\Ai\Models\ExamBlueprint;
use Modules\Auth\Models\User;

trait BuildsBlueprints
{
    /** A valid blueprint request: 10 MCQ x 1 + 2 essays x 5 = 20 marks. */
    private function blueprintPayload(array $overrides = []): array
    {
        return array_filter($overrides + [
            'title' => 'امتحان العلوم',
            'grade_level' => 'الصف الثاني الإعدادي',
            'subject' => 'علوم',
            'language' => 'ar',
            'outcomes' => [['text' => 'يتعرف التمثيل الضوئي']],
            'difficulty_mix' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'bloom_mix' => ['remember' => 30, 'understand' => 40, 'apply' => 30],
            'types' => [['type' => 'mcq', 'count' => 10, 'marks' => 1], ['type' => 'essay', 'count' => 2, 'marks' => 5]],
            'total_marks' => 20,
            'duration_minutes' => 45,
            'instructions' => 'أسئلة واضحة',
            'avoid_topics' => '',
        ], fn ($v) => $v !== null);
    }

    private function blueprint(User $owner, array $attributes = []): ExamBlueprint
    {
        return ExamBlueprint::create($attributes + [
            'owner_user_id' => $owner->id,
            'title' => 'امتحان العلوم',
            'grade_level' => 'الصف الثاني الإعدادي',
            'subject' => 'علوم',
            'language' => 'ar',
            'outcomes' => [['id' => 'O1', 'text' => 'يتعرف التمثيل الضوئي']],
            'difficulty_mix' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'bloom_mix' => null,
            'type_counts' => ['mcq' => 10, 'essay' => 2],
            'marks_per_type' => ['mcq' => 1, 'essay' => 5],
            'total_marks' => 20,
            'duration_minutes' => 45,
            'source_selection' => [],
            'is_template' => false,
            'is_private' => false,
        ]);
    }
}
