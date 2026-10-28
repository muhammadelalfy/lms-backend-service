<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Actions\DeleteMaterial;
use Modules\Ai\Actions\RetryMaterial;
use Modules\Ai\Actions\UploadMaterial;
use Modules\Ai\DTOs\UploadMaterialData;
use Modules\Ai\Http\Requests\DeleteMaterialRequest;
use Modules\Ai\Http\Requests\ListMaterialsRequest;
use Modules\Ai\Http\Requests\MaterialRequest;
use Modules\Ai\Http\Requests\UploadMaterialRequest;
use Modules\Ai\Http\Resources\MaterialResource;
use Modules\Ai\Models\Material;
use Modules\Ai\Services\AiEntitlements;

/** A teacher's teaching materials for AI exams: upload, status, delete. Private to the center. */
class MaterialController extends Controller
{
    public function index(ListMaterialsRequest $request, AiEntitlements $entitlements)
    {
        $user = $request->user();

        return MaterialResource::collection(
            Material::query()
                ->with('owner:id,name')
                ->when(! $user->isAnyRole('admin'), fn ($q) => $q->where('owner_user_id', $user->id))
                ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('id')
                ->cursorPaginate(25)
        )->additional(['limits' => $entitlements->limits()]);
    }

    public function store(UploadMaterialRequest $request, UploadMaterial $upload)
    {
        $material = $upload->handle($request->user(), UploadMaterialData::fromValidated($request->validated()));

        return (new MaterialResource($material->refresh()))->response()->setStatusCode(201);
    }

    public function show(MaterialRequest $request, Material $material)
    {
        return new MaterialResource($material->load(['owner:id,name', 'sections']));
    }

    public function destroy(DeleteMaterialRequest $request, Material $material, DeleteMaterial $delete)
    {
        $delete->handle($material);

        return response()->noContent();
    }

    public function retry(MaterialRequest $request, Material $material, RetryMaterial $retry)
    {
        if (! $retry->handle($material)) {
            return response()->json(['message' => 'لا يمكن إعادة المحاولة إلا للمواد التي فشلت معالجتها.', 'code' => 'not_retryable'], 409);
        }

        return (new MaterialResource($material->refresh()))->response()->setStatusCode(202);
    }

    public function download(MaterialRequest $request, Material $material)
    {
        $disk = Storage::disk((string) config('ai.disk'));
        abort_if($material->storage_path === null || ! $disk->exists($material->storage_path), 404, $material->original_removed ? 'الملف الأصلي لا يُحفظ بعد المعالجة؛ المحفوظ هو النص المستخرج فقط.' : 'الملف غير موجود.');

        return $disk->download($material->storage_path, $material->original_name);
    }
}
