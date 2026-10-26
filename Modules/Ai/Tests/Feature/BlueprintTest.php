<?php

declare(strict_types=1);

namespace Modules\Ai\Tests\Feature;

use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Modules\Ai\Models\ExamBlueprint;
use Modules\Ai\Tests\Concerns\BuildsBlueprints;
use Modules\Ai\Tests\Concerns\BuildsMaterials;
use Modules\Auth\Models\User;
use Tests\TestCase;

class BlueprintTest extends TestCase
{
    use BuildsBlueprints, BuildsMaterials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
    }

    public function test_a_teacher_saves_a_valid_blueprint_with_outcomes_parsed_from_a_pasted_block(): void
    {
        $teacher = $this->teacher();
        $material = $this->material($teacher, ['status' => 'ready']);

        $response = $this->postJson('/api/ai/blueprints', $this->blueprintPayload([
            'outcomes' => null,
            'outcomes_text' => "1. يتعرف التمثيل الضوئي\n\n- يشرح دور الكلوروفيل\n• يقارن بين التنفس والبناء",
            'sources' => [['material_id' => $material->id]],
        ]))->assertCreated();

        $response->assertJsonPath('data.title', 'امتحان العلوم')
            ->assertJsonPath('data.outcomes', [
                ['id' => 'O1', 'text' => 'يتعرف التمثيل الضوئي'],
                ['id' => 'O2', 'text' => 'يشرح دور الكلوروفيل'],
                ['id' => 'O3', 'text' => 'يقارن بين التنفس والبناء'],
            ])
            ->assertJsonPath('data.types', [['type' => 'mcq', 'count' => 10, 'marks' => 1], ['type' => 'essay', 'count' => 2, 'marks' => 5]])
            ->assertJsonPath('data.total_questions', 12)
            ->assertJsonPath('data.total_marks', 20)
            ->assertJsonPath('data.sources', [['material_id' => $material->id, 'section_ids' => null]])
            ->assertJsonPath('data.is_template', false)
            ->assertJsonPath('data.can_edit', true);
        $this->assertSame($teacher->id, ExamBlueprint::firstOrFail()->owner_user_id);
    }

    public function test_sums_that_do_not_add_up_are_refused_naming_the_field(): void
    {
        $this->teacher();

        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['difficulty_mix' => ['easy' => 30, 'medium' => 30, 'hard' => 30]]))
            ->assertStatus(422)->assertJsonValidationErrors(['difficulty_mix'])
            ->assertJsonPath('errors.difficulty_mix.0', 'مجموع نسب الصعوبة يجب أن يساوي 100٪.');
        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['bloom_mix' => ['remember' => 50, 'apply' => 20]]))
            ->assertStatus(422)->assertJsonValidationErrors(['bloom_mix']);
        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['total_marks' => 25]))
            ->assertStatus(422)->assertJsonValidationErrors(['total_marks'])
            ->assertJsonPath('errors.total_marks.0', 'مجموع درجات الأسئلة (20) لا يساوي الدرجة الكلية (25).');
        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['difficulty_mix' => ['trivial' => 100]]))
            ->assertStatus(422)->assertJsonValidationErrors(['difficulty_mix']);
        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['types' => [['type' => 'geometry', 'count' => 1, 'marks' => 20]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['types.0.type']);
        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['types' => [['type' => 'mcq', 'count' => 10, 'marks' => 1], ['type' => 'mcq', 'count' => 10, 'marks' => 1]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['types.0.type']);
        $this->assertSame(0, ExamBlueprint::count());
    }

    public function test_sources_must_be_the_teachers_own_materials_and_sections(): void
    {
        $teacher = $this->teacher();
        $mine = $this->material($teacher, ['status' => 'ready']);
        $other = $this->material(User::factory()->create(['role' => 'teacher']), ['status' => 'ready']);
        $foreignSection = $other->sections()->create(['kind' => 'page', 'label' => 'صفحة 1', 'ordinal' => 1]);

        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['sources' => [['material_id' => $other->id]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['sources.0.material_id' => 'المادة غير موجودة.']);
        $this->postJson('/api/ai/blueprints', $this->blueprintPayload(['sources' => [['material_id' => $mine->id, 'section_ids' => [$foreignSection->id]]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['sources.0.section_ids']);
    }

    public function test_shared_templates_are_listed_for_other_teachers_but_private_ones_and_plain_blueprints_are_not(): void
    {
        $author = $this->teacher();
        $shared = $this->blueprint($author, ['is_template' => true, 'title' => 'قالب مشترك']);
        $private = $this->blueprint($author, ['is_template' => true, 'is_private' => true, 'title' => 'قالب خاص']);
        $plain = $this->blueprint($author, ['title' => 'معايير عادية']);

        $this->actAs('teacher');
        $this->getJson('/api/ai/blueprints?templates=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared->id)->assertJsonPath('data.0.can_edit', false);
        $this->getJson('/api/ai/blueprints')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/ai/blueprints/{$shared->id}")->assertOk();
        $this->getJson("/api/ai/blueprints/{$private->id}")->assertNotFound();
        $this->getJson("/api/ai/blueprints/{$plain->id}")->assertNotFound();
        $this->putJson("/api/ai/blueprints/{$shared->id}", ['title' => 'سرقة'])->assertForbidden();
        $this->deleteJson("/api/ai/blueprints/{$shared->id}")->assertForbidden();

        Sanctum::actingAs($author);
        $this->getJson('/api/ai/blueprints?templates=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/ai/blueprints')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plain->id);

        $this->actAs('admin');
        $this->getJson('/api/ai/blueprints?templates=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/ai/blueprints/{$private->id}")->assertOk()->assertJsonPath('data.can_edit', true);

        foreach (['student', 'parent'] as $role) {
            $this->actAs($role);
            $this->getJson('/api/ai/blueprints')->assertForbidden();
            $this->postJson('/api/ai/blueprints', $this->blueprintPayload())->assertForbidden();
        }
    }

    public function test_rename_edit_duplicate_and_delete(): void
    {
        $author = $this->teacher();
        $material = $this->material($author, ['status' => 'ready']);
        $template = $this->blueprint($author, ['is_template' => true, 'source_selection' => [['material_id' => $material->id, 'section_ids' => null]]]);

        // Rename only: the other fields stay and still add up.
        $this->putJson("/api/ai/blueprints/{$template->id}", ['title' => 'اسم جديد'])->assertOk()
            ->assertJsonPath('data.title', 'اسم جديد')->assertJsonPath('data.total_marks', 20);
        // Changing the types alone is checked against the stored total.
        $this->putJson("/api/ai/blueprints/{$template->id}", ['types' => [['type' => 'mcq', 'count' => 5, 'marks' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['total_marks']);
        $this->putJson("/api/ai/blueprints/{$template->id}", ['types' => [['type' => 'mcq', 'count' => 5, 'marks' => 2]], 'total_marks' => 10])
            ->assertOk()->assertJsonPath('data.total_marks', 10);

        // The author's copy keeps the sources; another teacher's copy (load template) is theirs, without them.
        $this->postJson("/api/ai/blueprints/{$template->id}/duplicate", ['title' => 'نسخة'])->assertCreated()
            ->assertJsonPath('data.title', 'نسخة')->assertJsonPath('data.is_template', true)
            ->assertJsonPath('data.sources.0.material_id', $material->id);
        $other = $this->actAs('teacher');
        $copy = $this->postJson("/api/ai/blueprints/{$template->id}/duplicate", ['is_template' => false])->assertCreated()
            ->assertJsonPath('data.owner_user_id', $other->id)->assertJsonPath('data.is_template', false)
            ->assertJsonPath('data.sources', [])->assertJsonPath('data.types', [['type' => 'mcq', 'count' => 5, 'marks' => 2]]);
        $this->deleteJson("/api/ai/blueprints/{$copy->json('data.id')}")->assertNoContent();

        Sanctum::actingAs($author);
        $this->deleteJson("/api/ai/blueprints/{$template->id}")->assertNoContent();
        $this->assertSame(1, ExamBlueprint::count());
    }

    public function test_the_plan_flag_closes_blueprints(): void
    {
        config(['schools.plans.enterprise.features.ai_exams' => 'soon']);
        $this->teacher();

        $this->postJson('/api/ai/blueprints', $this->blueprintPayload())->assertForbidden()->assertJsonPath('code', 'plan_feature');
        $this->getJson('/api/ai/blueprints')->assertForbidden()->assertJsonPath('soon', true);
    }

    public function test_another_center_never_sees_a_template(): void
    {
        $author = $this->teacher();
        $template = $this->blueprint($author, ['is_template' => true]);

        $this->openSchool('beta');
        config(['schools.plans.enterprise.features.ai_exams' => true]);
        URL::forceRootUrl('http://beta.mudarisy.test');
        $this->app['auth']->forgetGuards();
        $this->actAs('admin');

        $this->getJson('/api/ai/blueprints?templates=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/ai/blueprints/{$template->id}")->assertNotFound();
        $this->postJson("/api/ai/blueprints/{$template->id}/duplicate")->assertNotFound();
    }

    public function test_the_list_is_cursor_paginated_without_queries_in_a_loop(): void
    {
        $teacher = $this->teacher();
        for ($i = 0; $i < 27; $i++) {
            $this->blueprint($teacher, ['title' => "م{$i}"]);
        }

        $first = $this->getJson('/api/ai/blueprints')->assertOk()->assertJsonCount(25, 'data');
        $this->getJson('/api/ai/blueprints?cursor='.$first->json('meta.next_cursor'))->assertOk()->assertJsonCount(2, 'data');
    }
}
