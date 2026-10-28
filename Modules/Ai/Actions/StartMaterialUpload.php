<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ai\Enums\ExcelMode;
use Modules\Ai\Exceptions\UploadProblem;
use Modules\Ai\Models\MaterialUpload;
use Modules\Ai\Services\AiEntitlements;
use Modules\Ai\Services\MaterialInspector;
use Modules\Auth\Models\User;

/** Opens a resumable upload: checks the name, size and plan up front so a doomed upload never starts. */
final class StartMaterialUpload
{
    private const MAX_OPEN_PER_USER = 5;

    public function __construct(private readonly MaterialInspector $inspector, private readonly AiEntitlements $entitlements) {}

    public function handle(User $actor, string $filename, int $size, ?string $title, ExcelMode $excelMode, ?string $sha256): MaterialUpload
    {
        $kind = $this->inspector->kindFromName($filename); // refuses macros, old formats, unknown types
        $this->inspector->ensureSizeFits($kind, $size);
        $this->entitlements->ensureCanStore($this->entitlements->expectedStoredBytes($kind->value, $size));

        $open = MaterialUpload::query()->where('owner_user_id', $actor->id)->where('expires_at', '>', now())->count();
        if ($open >= self::MAX_OPEN_PER_USER) {
            throw new UploadProblem('لديك رفعات كثيرة غير مكتملة. أكمل إحداها أو ألغها أولاً.', 'too_many_uploads', 429);
        }

        $chunk = max(1, (int) config('ai.materials.chunk_mb')) * 1048576;
        $id = (string) Str::uuid();
        $path = 'centers/'.tenant()->getTenantKey().'/ai-uploads/'.$id.'.part';
        Storage::disk((string) config('ai.disk'))->put($path, '');

        return MaterialUpload::create([
            'id' => $id,
            'owner_user_id' => $actor->id,
            'original_name' => Str::limit($filename, 250, ''),
            'title' => $title,
            'excel_mode' => $excelMode->value,
            'kind' => $kind->value,
            'size_bytes' => $size,
            'sha256' => $sha256 !== null ? strtolower($sha256) : null,
            'chunk_bytes' => $chunk,
            'total_chunks' => (int) ceil($size / $chunk),
            'temp_path' => $path,
            'expires_at' => now()->addHours((int) config('ai.materials.upload_ttl_hours')),
        ]);
    }
}
