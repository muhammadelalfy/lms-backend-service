<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Actions\CompleteMaterialUpload;
use Modules\Ai\Actions\ReceiveUploadChunk;
use Modules\Ai\Actions\StartMaterialUpload;
use Modules\Ai\Enums\ExcelMode;
use Modules\Ai\Http\Requests\StartMaterialUploadRequest;
use Modules\Ai\Http\Requests\UploadChunkRequest;
use Modules\Ai\Http\Resources\MaterialResource;
use Modules\Ai\Models\Material;
use Modules\Ai\Models\MaterialUpload;

/**
 * Resumable upload of a material: start -> PUT each piece (about 5 MB) -> complete. The pieces are
 * appended to a temp file on disk, never kept in memory; extraction then runs in the `ai` queue.
 */
class MaterialUploadController extends Controller
{
    public function start(StartMaterialUploadRequest $request, StartMaterialUpload $start): JsonResponse
    {
        $data = $request->validated();
        $upload = $start->handle(
            $request->user(),
            $data['filename'],
            (int) $data['size'],
            isset($data['title']) ? trim($data['title']) : null,
            ExcelMode::tryFrom((string) ($data['excel_mode'] ?? '')) ?? ExcelMode::Auto,
            $data['sha256'] ?? null,
        );

        return response()->json(['data' => $this->payload($upload)], 201);
    }

    public function show(Request $request, MaterialUpload $upload): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->owned($request, $upload))]);
    }

    public function chunk(UploadChunkRequest $request, MaterialUpload $upload, int $index, ReceiveUploadChunk $receive): JsonResponse
    {
        $this->owned($request, $upload);
        $stream = $request->getContent(true);
        $upload = $receive->handle($upload, $index, $stream, $request->checksum());

        return response()->json(['data' => $this->payload($upload)]);
    }

    public function complete(Request $request, MaterialUpload $upload, CompleteMaterialUpload $complete): JsonResponse
    {
        $this->owned($request, $upload);
        $material = $complete->handle($request->user(), $upload);

        return (new MaterialResource($material->refresh()))->response()->setStatusCode(201);
    }

    public function abort(Request $request, MaterialUpload $upload)
    {
        $this->owned($request, $upload);
        Storage::disk((string) config('ai.disk'))->delete($upload->temp_path);
        $upload->delete();

        return response()->noContent();
    }

    /** An upload belongs to whoever started it; anyone else gets a plain 404. */
    private function owned(Request $request, MaterialUpload $upload): MaterialUpload
    {
        abort_unless($request->user()->can('create', Material::class) && $upload->owner_user_id === $request->user()->id, 404);

        return $upload;
    }

    /** @return array<string, mixed> */
    private function payload(MaterialUpload $upload): array
    {
        return [
            'id' => $upload->id,
            'filename' => $upload->original_name,
            'size_bytes' => $upload->size_bytes,
            'chunk_bytes' => $upload->chunk_bytes,
            'total_chunks' => $upload->total_chunks,
            'next_index' => $upload->next_index,
            'received_bytes' => $upload->received_bytes,
            'expires_at' => $upload->expires_at->toIso8601String(),
        ];
    }
}
