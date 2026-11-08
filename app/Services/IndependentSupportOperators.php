<?php

namespace App\Services;

use App\Support\Chat\ChatBridge;
use RuntimeException;

final class IndependentSupportOperators
{
    /** Explicit, interactive one-time enrollment; never imports administrator passwords. */
    public function enroll(?string $username, string $password): string
    {
        if (! app()->runningInConsole()) {
            throw new RuntimeException('Console enrollment required.');
        }
        $bridge = app(ChatBridge::class);
        $bridge->begin();
        try {
            require_once resource_path('support-chat/support/bootstrap.php');
            require_once resource_path('support-chat/support/operators.php');
            $candidate = \support_ops_account($username ?? 'support_owner', $username ?? 'support_owner', $password, 'administrator');

            return \support_ops_store(function (array &$state) use ($candidate, $username): string {
                if (($state['authentication'] ?? null) === 'independent-v1') {
                    throw new RuntimeException('Already enrolled; use support account management.');
                }
                if ($username === null && $state['accounts'] !== []) {
                    $administrators = array_filter($state['accounts'], fn (array $account) => $account['active'] && $account['role'] === 'administrator');
                    if (count($administrators) !== 1) {
                        throw new RuntimeException('Specify the existing support administrator username.');
                    }
                    $candidate['username'] = reset($administrators)['username'];
                }
                $selected = null;
                foreach ($state['accounts'] as $id => $account) {
                    if ($account['username'] === $candidate['username'] && $account['role'] === 'administrator' && $account['active']) {
                        $selected = $id;
                    }
                }
                if ($state['accounts'] !== [] && $selected === null) {
                    throw new RuntimeException('An existing active support administrator username is required.');
                }
                // Preserve the exact pre-transition authority privately under its
                // content identity while holding the native authority lock.
                $authority = config('support_chat.directory').'/runtime/operators/authority.json';
                $before = file_get_contents($authority);
                $snapshot = dirname($authority).'/authority-before-independent-'.hash('sha256', $before).'.json';
                if (is_link($snapshot) || (file_exists($snapshot) && (! is_file($snapshot) || ! hash_equals(hash('sha256', $before), hash_file('sha256', $snapshot))))) {
                    throw new RuntimeException('Independent enrollment evidence mismatch; preserve and inspect.');
                }
                if (! file_exists($snapshot)) {
                    \support_atomic_replace($snapshot, $before);
                }
                foreach ($state['accounts'] as &$account) {
                    // Revoke every old session, preserving IDs, profiles, history and passwords.
                    $account['epoch'] = bin2hex(random_bytes(16));
                    if (preg_match('/\Anp_([0-9]+)\z/D', $account['username'], $match)
                        && hash_equals($account['id'], substr(hash_hmac('sha256', 'chat-operator:'.$match[1], config('app.key')), 0, 32))) {
                        $account['active'] = false;
                    }
                }
                unset($account);
                if ($selected === null) {
                    $selected = $candidate['id'];
                    $state['accounts'][$selected] = $candidate;
                } else {
                    $state['accounts'][$selected]['password'] = $candidate['password'];
                    $state['accounts'][$selected]['active'] = true;
                }
                $state['subscriptions'] = [];
                $state['authentication'] = 'independent-v1';
                \support_ops_audit($state, $selected, 'independent_authentication_enrolled');

                return $state['accounts'][$selected]['username'];
            }, true);
        } finally {
            $bridge->finish();
        }
    }
}
