<?php

return [
    'enabled' => (bool) env('SUPPORT_CHAT_ENABLED', false),
    'directory' => storage_path('app/private/support-chat'),
    'source' => resource_path('support-chat'),
];
