<?php

namespace Modules\Ai\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Ai\Actions\PrepareSessionGrading;
use Modules\Ai\Contracts\MaterialScanner;
use Modules\Ai\Models\DraftExam;
use Modules\Ai\Models\ExamBlueprint;
use Modules\Ai\Models\GenerationJob;
use Modules\Ai\Models\Material;
use Modules\Ai\Policies\BlueprintPolicy;
use Modules\Ai\Policies\DraftPolicy;
use Modules\Ai\Policies\GenerationJobPolicy;
use Modules\Ai\Policies\MaterialPolicy;
use Modules\Exams\Events\ExamSessionSubmitted;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MaterialScanner::class, fn ($app) => $app->make((string) config('ai.scanner')));
    }

    public function boot(): void
    {
        Route::middleware(['api', 'school'])->prefix('api')->group(__DIR__.'/../routes/api.php');
        RateLimiter::for('ai-upload-start', fn (Request $r) => Limit::perMinute(30)->by('ai-up:'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('ai-upload-chunks', fn (Request $r) => Limit::perMinute(300)->by('ai-chunk:'.($r->user()?->id ?? $r->ip())));
        Gate::policy(Material::class, MaterialPolicy::class);
        Gate::policy(ExamBlueprint::class, BlueprintPolicy::class);
        Gate::policy(DraftExam::class, DraftPolicy::class);
        Gate::policy(GenerationJob::class, GenerationJobPolicy::class);

        // Grading assistance: a submitted session of an exam with rubrics enters the teacher's queue.
        // Runs in the submit request (cheap inserts) so the response already says pending_review.
        Event::listen(ExamSessionSubmitted::class, fn (ExamSessionSubmitted $e) => app(PrepareSessionGrading::class)->handle($e->sessionId));
    }
}
