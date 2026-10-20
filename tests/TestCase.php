<?php

namespace Tests;

use App\Models\AdminAccount;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(UserContract $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        // actingAs bypasses the real login; attach the same database session version.
        $account = AdminAccount::query()->where('user_id', $user->getAuthIdentifier())->first();
        if ($account) {
            $this->withSession(['admin_session_version' => $account->session_version]);
        }

        return $this;
    }
}
