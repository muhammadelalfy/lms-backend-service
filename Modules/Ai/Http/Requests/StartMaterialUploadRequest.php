<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Ai\Enums\ExcelMode;
use Modules\Ai\Models\Material;

class StartMaterialUploadRequest extends AiRequest
{
    public function authorize(): bool
    {
        return $this->allowed($this->user()->can('create', Material::class));
    }

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.((int) config('ai.materials.max_file_mb') * 1048576)],
            'title' => ['nullable', 'string', 'max:255'],
            'excel_mode' => ['nullable', Rule::enum(ExcelMode::class)],
            'sha256' => ['nullable', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'filename.required' => 'اسم الملف مطلوب.',
            'size.required' => 'حجم الملف مطلوب.',
            'size.min' => 'الملف فارغ.',
            'size.max' => 'حجم الملف أكبر من الحد المسموح ('.config('ai.materials.max_file_mb').' ميجابايت).',
            'sha256.regex' => 'بصمة الملف غير صالحة.',
        ];
    }
}
