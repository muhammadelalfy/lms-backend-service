<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Modules\Ai\Exceptions\AiException;
use Modules\Ai\Exceptions\GenerationRefused;
use Modules\Ai\Services\AiClient;
use Modules\Ai\Services\GenerationPayload;
use Modules\Ai\Services\UnitBudget;
use Modules\Ai\Support\JobError;
use Modules\Auth\Models\User;

/**
 * What a blueprint would cost, next to what is left of the month: the AI service does the token math
 * (no LLM call), this app adds the cap. Nothing is reserved or charged.
 */
final class EstimateGeneration
{
    public function __construct(private readonly AiClient $ai, private readonly UnitBudget $budget, private readonly GenerationPayload $payload) {}

    /**
     * @return array<string, mixed>
     *
     * @throws GenerationRefused no ready material
     * @throws AiException the AI service is down or refused
     */
    public function handle(User $actor, array $snapshot): array
    {
        $snapshot['ai_sources'] = $this->payload->resolveSources((array) $snapshot['sources'], $actor);
        $estimate = $this->ai->estimate($this->payload->request($snapshot));
        $budget = $this->budget->snapshot();
        $fits = $budget['remaining'] === null || ($budget['remaining'] > 0 && $estimate['units'] <= $budget['remaining']);

        return $estimate + [
            'requested_questions' => array_sum(array_column($snapshot['types'], 'count')),
            'cap_units' => $budget['cap'],
            'used_units' => $budget['used'],
            'reserved_units' => $budget['reserved'],
            'remaining_units' => $budget['remaining'],
            'can_run' => $fits,
            'reason' => $fits ? null : JobError::CAP_REACHED,
            'message' => $fits ? null : 'التقدير أكبر من الوحدات المتبقية لشهرك. قلّل عدد الأسئلة أو رقِّ الباقة.',
        ];
    }
}
