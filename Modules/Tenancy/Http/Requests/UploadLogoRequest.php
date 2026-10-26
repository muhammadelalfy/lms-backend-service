<?php

declare(strict_types=1);

namespace Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The center's admin uploads its logo: PNG, JPG or WebP only (no SVG: owner decision, nothing to
 * sanitise), at most 1 MB, at least 128x128 pixels.
 */
class UploadLogoRequest extends FormRequest
{
    public const MAX_KB = 1024;

    public function authorize(): bool
    {
        return $this->user()->isAnyRole('admin');
    }

    public function rules(): array
    {
        return [
            'logo' => [
                'required', 'file',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'max:'.self::MAX_KB,
                'dimensions:min_width=128,min_height=128,max_width=4096,max_height=4096',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.required' => 'اختار صورة الشعار.',
            'logo.file' => 'اختار صورة الشعار.',
            'logo.mimes' => 'الشعار لازم يكون صورة PNG أو JPG أو WebP.',
            'logo.mimetypes' => 'الشعار لازم يكون صورة PNG أو JPG أو WebP.',
            'logo.max' => 'حجم الشعار أكبر من 1 ميجا.',
            'logo.dimensions' => 'مقاس الشعار لازم يكون 128×128 بكسل على الأقل (و4096 بكسل على الأكثر).',
        ];
    }
}
