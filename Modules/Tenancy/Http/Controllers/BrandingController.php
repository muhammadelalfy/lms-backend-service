<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Tenancy\Actions\UpdateBranding;
use Modules\Tenancy\Http\Requests\UpdateBrandingRequest;
use Modules\Tenancy\Http\Requests\UploadLogoRequest;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Services\Branding;

/** A center's own name, logo and colors: public to read (the sign-in page wears them), the admin's to change. */
class BrandingController extends Controller
{
    public function __construct(private readonly Branding $branding) {}

    public function show()
    {
        return $this->branding->cached(tenant());
    }

    public function update(UpdateBrandingRequest $request, UpdateBranding $update)
    {
        return $this->written($update->details(tenant(), $request->validated()));
    }

    public function uploadLogo(UploadLogoRequest $request, UpdateBranding $update)
    {
        return $this->written($update->logo(tenant(), $request->file('logo')));
    }

    public function deleteLogo(Request $request, UpdateBranding $update)
    {
        abort_unless($request->user()->isAnyRole('admin'), 403, 'تغيير هوية المركز للمشرف فقط.');

        return $this->written($update->removeLogo(tenant()));
    }

    /** After a change: the new look, plus warnings (e.g. low contrast) that never block the save. */
    private function written(School $school): array
    {
        return $this->branding->present($school) + ['warnings' => $this->branding->warnings($school)];
    }
}
