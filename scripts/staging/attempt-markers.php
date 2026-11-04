<?php

/** A known earlier failure never authorizes replay after a later installation marker. */
function np_staging_require_fresh_attempt(string $kit): void
{
    foreach (['started', 'pass'] as $kind) {
        foreach (glob($kit.'/repair-chat-'.$kind.'-v*.json') ?: [] as $path) {
            if (preg_match('/\Arepair-chat-(started|pass)-v[1-9][0-9]*\.json\z/', basename($path))) {
                throw new RuntimeException('previous_attempt_preserve_'.basename($path));
            }
        }
    }
}
