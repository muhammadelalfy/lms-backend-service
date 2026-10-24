<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Actions\StartTrial;
use Modules\Tenancy\DTOs\SignUpData;
use Modules\Tenancy\Http\Requests\SignUpRequest;

/**
 * The public "start your free trial": opens a trial center with its first admin and says where to sign in.
 * Public, on the central addresses only, and rate limited so it cannot be used to fill the server.
 */
class SignUpController
{
    public function __invoke(SignUpRequest $request, StartTrial $startTrial): JsonResponse
    {
        $school = $startTrial->handle(SignUpData::fromValidated($request->validated()));

        $port = $request->getPort();
        $suffix = in_array($port, [80, 443], true) ? '' : ':'.$port;
        $domain = $school->domains()->orderBy('id')->value('domain');

        return response()->json([
            'name' => $school->name,
            'plan' => $school->plan,
            'desired_plan' => $school->desired_plan,
            'limits' => $school->limits(),
            'trial_ends_at' => $school->trial_ends_at?->toIso8601String(),
            'url' => $request->getScheme().'://'.$domain.$suffix.'/login',
        ], 201);
    }
}
