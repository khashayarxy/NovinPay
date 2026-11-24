<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Legacy selections can be claimed by the original browser session, never by account inference. */
class LegacyCartHold
{
    public function rememberOriginalSession(Request $request): void
    {
        $session = $request->session();
        if ($session->has('store_cart') && ! $session->has('store_cart_original_session')) {
            $session->put('store_cart_original_session', hash('sha256', $session->getId()));
        }
    }

    public function status(Request $request): ?string
    {
        $legacy = $request->session()->get('store_cart');
        if (! is_array($legacy) || $legacy === []) {
            return null;
        }

        $this->rememberOriginalSession($request);
        $reference = $request->session()->get('store_cart_hold_reference');
        if (! $reference) {
            $reference = (string) Str::uuid();
            DB::table('legacy_cart_holds')->insert([
                'reference' => $reference,
                'payload' => encrypt($legacy),
                'session_digest' => $request->session()->get('store_cart_original_session'),
                'captured_at' => now(),
            ]);
            $request->session()->put('store_cart_hold_reference', $reference);
        }
        $hold = DB::table('legacy_cart_holds')->where('reference', $reference)->first();

        if ($hold?->migrated_at) {
            return null;
        }

        return $hold && $this->matchesSession($request, $hold->session_digest) ? 'claimable' : 'held';
    }

    /** The caller must be inside the transaction that writes the device cart. */
    public function claim(Request $request, string $deviceScope, callable $import): void
    {
        $reference = $request->session()->get('store_cart_hold_reference');
        abort_unless($reference && DB::transactionLevel() > 0, 409);
        $hold = DB::table('legacy_cart_holds')->where('reference', $reference)->lockForUpdate()->first();
        abort_unless($hold && $this->matchesSession($request, $hold->session_digest), 409,
            'سبد قبلی برای انتقال در این نشست قابل تأیید نیست.');
        if ($hold->migrated_at) {
            abort_unless(hash_equals($hold->device_scope, $deviceScope), 409);

            return;
        }

        $legacy = decrypt($hold->payload);
        abort_unless(is_array($legacy) && $legacy === $request->session()->get('store_cart') && count($legacy) <= 30, 409);
        foreach ($legacy as $slug => $quantity) {
            abort_unless(preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', (string) $slug) && is_int($quantity) && $quantity >= 1 && $quantity <= ShoppingCart::MAX_ITEM_QUANTITY, 409);
        }
        $import($legacy);
        DB::table('legacy_cart_holds')->where('reference', $reference)
            ->update(['device_scope' => $deviceScope, 'migrated_at' => now()]);
    }

    private function matchesSession(Request $request, string $digest): bool
    {
        $session = $request->session();
        $cookie = $request->cookie($session->getName());

        return is_string($cookie) && hash_equals($session->getId(), $cookie)
            && hash_equals($digest, hash('sha256', $cookie));
    }
}
