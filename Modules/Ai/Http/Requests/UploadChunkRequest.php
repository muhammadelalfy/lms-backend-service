<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Requests;

use Modules\Ai\Models\Material;

/** One piece of a chunked upload: the raw bytes are the body, the checksum is in X-Chunk-Sha256. */
class UploadChunkRequest extends AiRequest
{
    public function authorize(): bool
    {
        return $this->allowed($this->user()->can('create', Material::class));
    }

    public function rules(): array
    {
        return [];
    }

    public function checksum(): string
    {
        $sum = (string) $this->header('X-Chunk-Sha256');
        if (! preg_match('/^[A-Fa-f0-9]{64}$/', $sum)) {
            abort(422, 'بصمة الجزء مطلوبة (X-Chunk-Sha256).');
        }

        return $sum;
    }
}
