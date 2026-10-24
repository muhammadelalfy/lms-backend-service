<?php

declare(strict_types=1);

namespace Modules\Courses\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Only content that was never published may be deleted; anything else is hidden or archived instead. */
final class DeleteContent
{
    public function handle(Model $content): void
    {
        DB::transaction(function () use ($content): void {
            $content->newQuery()->whereKey($content->getKey())->lockForUpdate()->value($content->getKeyName());
            $content->refresh();
            $content->assertNeverPublished();
            $content->delete();
        });
    }
}
