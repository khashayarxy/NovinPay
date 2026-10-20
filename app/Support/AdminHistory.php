<?php

namespace App\Support;

use App\Enums\AdminAction;
use App\Models\AdminActivity;

final class AdminHistory
{
    public static function record(AdminAction $action, string $targetType, ?int $targetId, ?int $actorId = null): void
    {
        // No request arrays, names, contact data, credentials, tokens or IP payloads.
        $activity = new AdminActivity;
        $activity->actor_id = $actorId ?? auth()->id();
        $activity->action = $action;
        $activity->target_type = $targetType;
        $activity->target_id = $targetId;
        $activity->created_at = now();
        $activity->save();
    }
}
