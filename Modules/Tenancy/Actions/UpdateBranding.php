<?php

declare(strict_types=1);

namespace Modules\Tenancy\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Tenancy\Models\School;
use Modules\Tenancy\Services\Branding;

/** Changes how a center looks: its name and colors, its uploaded logo, or removes the logo. */
final class UpdateBranding
{
    public function __construct(private readonly Branding $branding) {}

    /** @param  array{name?: string, brand_color?: string|null, primary_color?: string|null, secondary_color?: string|null, logo_url?: string|null}  $data */
    public function details(School $school, array $data): School
    {
        // `primary_color` is the spec's name for `brand_color`; when both come, primary wins.
        if (array_key_exists('primary_color', $data)) {
            $data['brand_color'] = $data['primary_color'];
        }
        unset($data['primary_color']);
        foreach (['brand_color', 'secondary_color'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = strtoupper($data[$key]);
            }
        }
        // A logo address typed in by hand replaces any uploaded file.
        if (array_key_exists('logo_url', $data)) {
            $this->deleteStoredLogo($school);
        }

        $school->update($data);
        $this->branding->forget();

        return $school;
    }

    /** Stores the logo as tenants/{id}/branding/logo.{ext}; the URL carries ?v= so browsers fetch a new one. */
    public function logo(School $school, UploadedFile $file): School
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $path = Branding::directory($school).'/logo.'.$extension;
        $disk = Storage::disk(Branding::DISK);

        $old = $school->logo_path;
        $disk->putFileAs(Branding::directory($school), $file, 'logo.'.$extension, ['visibility' => 'public']);
        if ($old && $old !== $path) {
            $disk->delete($old);
        }

        $version = substr(hash_file('sha256', $file->getRealPath()), 0, 8);
        $school->forceFill([
            'logo_path' => $path,
            'logo_url' => url('storage/'.$path).'?v='.$version,
        ])->save();
        $this->branding->forget();

        return $school;
    }

    public function removeLogo(School $school): School
    {
        $this->deleteStoredLogo($school);
        $school->forceFill(['logo_url' => null])->save();
        $this->branding->forget();

        return $school;
    }

    private function deleteStoredLogo(School $school): void
    {
        if ($school->logo_path) {
            Storage::disk(Branding::DISK)->delete($school->logo_path);
            $school->forceFill(['logo_path' => null]);
        }
    }
}
