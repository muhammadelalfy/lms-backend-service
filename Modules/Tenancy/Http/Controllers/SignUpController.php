<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Http\Requests\SignUpRequest;
use Modules\Tenancy\Services\SchoolProvisioner;

/**
 * The public "start your free trial": opens a trial academy with its first admin and says where to sign in.
 * Public, on the central addresses only, and rate limited so it cannot be used to fill the server.
 */
class SignUpController
{
    public function __invoke(SignUpRequest $request, SchoolProvisioner $provisioner): JsonResponse
    {
        $data = $request->validated();
        $school = $provisioner->open([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'plan' => 'trial',
            'admin' => $data['admin'],
        ]);
        $school->forceFill(['kind' => $data['kind']])->save();

        $port = $request->getPort();
        $suffix = in_array($port, [80, 443], true) ? '' : ':'.$port;
        $domain = $school->domains()->orderBy('id')->value('domain');

        return response()->json([
            'name' => $school->name,
            'trial_ends_at' => $school->trial_ends_at?->toIso8601String(),
            'url' => $request->getScheme().'://'.$domain.$suffix.'/login',
        ], 201);
    }
}
