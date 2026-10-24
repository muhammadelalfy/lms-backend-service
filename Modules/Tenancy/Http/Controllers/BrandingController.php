<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Tenancy\Http\Requests\UpdateBrandingRequest;
use Modules\Tenancy\Models\School;

/** An academy's own name, logo and color: public to read (the sign-in page wears them), the admin's to change. */
class BrandingController extends Controller
{
    public function show()
    {
        return $this->present(tenant());
    }

    public function update(UpdateBrandingRequest $request)
    {
        $school = tenant();
        $school->update($request->validated());

        return $this->present($school);
    }

    private function present(School $school): array
    {
        return ['name' => $school->name, 'brand_color' => $school->brand_color, 'logo_url' => $school->logo_url];
    }
}
