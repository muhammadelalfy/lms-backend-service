<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Ai\Enums\ExcelMode;
use Modules\Ai\Models\Material;

class UploadMaterialRequest extends AiRequest
{
    public function authorize(): bool
    {
        return $this->allowed($this->user()->can('create', Material::class));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.((int) config('ai.materials.max_file_mb') * 1024)],
            'title' => ['nullable', 'string', 'max:255'],
            'excel_mode' => ['nullable', Rule::enum(ExcelMode::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'اختر ملفاً للرفع.',
            'file.file' => 'تعذر رفع الملف. أعد المحاولة.',
            'file.uploaded' => 'تعذر رفع الملف؛ ربما يتجاوز الحجم المسموح.',
            'file.max' => 'حجم الملف أكبر من الحد المسموح ('.config('ai.materials.max_file_mb').' ميجابايت).',
            'title.max' => 'العنوان طويل جداً.',
            'excel_mode.Illuminate\Validation\Rules\Enum' => 'وضع Excel غير صالح.',
        ];
    }
}
