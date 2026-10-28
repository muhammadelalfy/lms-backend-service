<?php

declare(strict_types=1);

namespace Modules\Ai\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Ai\Exceptions\UploadProblem;
use Modules\Ai\Models\MaterialUpload;

/**
 * Appends one piece to the upload's temp file, streaming it from the request to the disk in 1 MB steps
 * (the piece is never held in memory). Safe to repeat: a piece that was already stored is acknowledged
 * and not written twice; a piece that arrives out of order is refused with the index to send next.
 */
final class ReceiveUploadChunk
{
    /** @param resource $stream the request body */
    public function handle(MaterialUpload $upload, int $index, $stream, string $sha256): MaterialUpload
    {
        return DB::transaction(function () use ($upload, $index, $stream, $sha256): MaterialUpload {
            $upload = MaterialUpload::query()->lockForUpdate()->findOrFail($upload->id);
            if ($upload->expires_at->isPast()) {
                throw new UploadProblem('انتهت صلاحية هذا الرفع. ابدأ من جديد.', 'upload_expired', 410);
            }
            if ($index < $upload->next_index) {
                return $upload; // a retry of a piece that already arrived
            }
            if ($index > $upload->next_index || $index >= $upload->total_chunks) {
                throw new UploadProblem('ترتيب الأجزاء غير صحيح.', 'out_of_order', 409, ['next_index' => $upload->next_index]);
            }

            $expected = min($upload->chunk_bytes, $upload->size_bytes - $index * $upload->chunk_bytes);
            $path = Storage::disk((string) config('ai.disk'))->path($upload->temp_path);
            $file = fopen($path, 'cb');
            try {
                // Anything past the acknowledged size is the leftover of a crashed attempt.
                ftruncate($file, $upload->received_bytes);
                fseek($file, 0, SEEK_END);
                $hash = hash_init('sha256');
                $written = 0;
                while (! feof($stream) && ($buffer = fread($stream, 1048576)) !== false && $buffer !== '') {
                    $written += strlen($buffer);
                    if ($written > $expected) {
                        break;
                    }
                    hash_update($hash, $buffer);
                    fwrite($file, $buffer);
                }
                if ($written !== $expected) {
                    ftruncate($file, $upload->received_bytes);
                    throw new UploadProblem('حجم الجزء غير صحيح. أعد إرساله.', 'bad_chunk_size', 422, ['expected_bytes' => $expected]);
                }
                if (! hash_equals(hash_final($hash), strtolower($sha256))) {
                    ftruncate($file, $upload->received_bytes);
                    throw new UploadProblem('الجزء وصل تالفاً. أعد إرساله.', 'checksum_mismatch', 422);
                }
            } finally {
                fclose($file);
            }

            $upload->forceFill([
                'next_index' => $index + 1,
                'received_bytes' => $upload->received_bytes + $expected,
                'expires_at' => now()->addHours((int) config('ai.materials.upload_ttl_hours')),
            ])->save();

            return $upload;
        });
    }
}
