<?php

namespace Tests\Feature;

use App\Enums\RepairRequestStatus;
use App\Models\RepairRequest;
use App\Support\RepairIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepairRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function payload(array $overrides = []): array
    {
        $this->get('/repair')->assertOk();

        return array_replace([
            'customer_name' => 'مشتری آزمایشی',
            'contact_phone' => '09123456789',
            'device_brand' => 'PAX',
            'device_model' => 'مدل روی برچسب دستگاه',
            'issue' => 'دستگاه هنگام روشن شدن خاموش می‌شود.',
            'submission_token' => session('repair_submission_token'),
        ], $overrides);
    }

    private function submit(): RepairRequest
    {
        $this->post('/repair', $this->payload())->assertRedirect(route('repair.confirmation'));

        return RepairRequest::query()->sole();
    }

    public function test_submission_encrypts_personal_fields_and_issues_random_reference_without_intake(): void
    {
        $data = $this->payload(['contact_phone' => '+98 ۹۱۲-۳۴۵۶۷۸۹', 'status' => 'received', 'id' => 999, 'tracking_reference' => 'forged']);
        $this->post('/repair', $data)->assertRedirect(route('repair.confirmation'));
        $repair = RepairRequest::query()->sole();
        $this->assertMatchesRegularExpression('/\ANP-[A-F0-9]{32}\z/', $repair->tracking_reference);
        $this->assertSame(RepairRequestStatus::OnlineSubmitted, $repair->status);
        $this->assertSame('09123456789', $repair->contact_phone);
        $this->assertSame($data['customer_name'], $repair->customer_name);
        $this->assertNotSame(999, $repair->id);
        $raw = (array) DB::table('repair_requests')->first();
        foreach (['customer_name', 'contact_phone', 'device_model', 'issue'] as $field) {
            $this->assertStringNotContainsString($repair->$field, $raw[$field]);
            $this->assertNotSame($repair->$field, $raw[$field]);
        }
        $this->assertSame(RepairIdentity::digest('phone', '09123456789'), $raw['phone_digest']);
        $this->assertNotSame(hash('sha256', '09123456789'), $raw['phone_digest']);
        $this->assertArrayNotHasKey('customer_name', $repair->toArray());
        $this->assertNull(session('repair_submission_token'));
        $this->get('/repair/confirmation')->assertOk()->assertSee($repair->tracking_reference)
            ->assertSee('دریافت فیزیکی تأیید نشده')->assertDontSee($data['customer_name'])
            ->assertDontSee('09123456789')->assertDontSee($data['issue']);
    }

    public static function invalidInputs(): array
    {
        return [
            'missing name' => ['customer_name', ''],
            'name array' => ['customer_name', ['unsafe']],
            'long name' => ['customer_name', str_repeat('a', 101)],
            'invalid phone' => ['contact_phone', '12345678901'],
            'phone array' => ['contact_phone', ['09123456789']],
            'long phone' => ['contact_phone', str_repeat('0', 150)],
            'unapproved brand' => ['device_brand', 'Ingenico'],
            'brand array' => ['device_brand', ['PAX']],
            'missing model' => ['device_model', ''],
            'long model' => ['device_model', str_repeat('a', 101)],
            'short issue' => ['issue', 'خراب'],
            'long issue' => ['issue', str_repeat('a', 2001)],
            'issue array' => ['issue', ['unsafe']],
            'missing token' => ['submission_token', ''],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_requests_are_rejected_without_storing_or_flashing_customer_input(string $field, mixed $value): void
    {
        $data = $this->payload([$field => $value]);
        $this->post('/repair', $data)->assertRedirect(route('repair'))->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDatabaseCount('repair_requests', 0);
    }

    public function test_a_form_must_have_a_session_issued_token(): void
    {
        $data = $this->payload(['submission_token' => str_repeat('a', 64)]);
        $this->post('/repair', $data)->assertRedirect(route('repair'))->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('repair_requests', 0);
    }

    public function test_repeat_and_concurrent_form_submissions_do_not_create_duplicate_records(): void
    {
        $data = $this->payload();
        $this->post('/repair', $data)->assertRedirect(route('repair.confirmation'));
        $original = RepairRequest::query()->sole();
        $this->post('/repair', $data)->assertSessionHasErrors('submission_token');
        // Simulate a second worker seeing the pre-submit session token.
        $this->withSession(['repair_submission_token' => $data['submission_token']])
            ->post('/repair', array_replace($data, ['customer_name' => 'نباید جایگزین شود']))
            ->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('repair_requests', 1);
        $this->assertSame($original->customer_name, $original->fresh()->customer_name);
        $this->assertNull(session('repair_submission_token'));
        $freshForm = $this->payload(['contact_phone' => '09999999999']);
        $this->assertNotSame($data['submission_token'], $freshForm['submission_token']);
        $this->post('/repair', $freshForm)->assertRedirect(route('repair.confirmation'));
        $this->assertDatabaseCount('repair_requests', 2);
    }

    public function test_new_forms_get_distinct_public_references(): void
    {
        $first = $this->submit()->tracking_reference;
        $this->post('/repair', $this->payload())->assertRedirect(route('repair.confirmation'));
        $references = RepairRequest::query()->pluck('tracking_reference')->all();
        $this->assertCount(2, array_unique($references));
        $this->assertContains($first, $references);
    }

    public function test_submission_is_limited_by_normalized_phone_even_across_ips(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->post('/repair', $this->payload())->assertRedirect(route('repair.confirmation'));
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
            ->post('/repair', $this->payload(['contact_phone' => '+98۹۱۲۳۴۵۶۷۸۹']))
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('repair_requests', 3);
    }

    public function test_malformed_submissions_are_also_limited_by_ip(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/repair', [])->assertSessionHasErrors();
        }
        $this->post('/repair', [])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('repair_requests', 0);
    }

    public function test_tracking_requires_both_credentials_and_returns_only_initial_status(): void
    {
        $repair = $this->submit();
        $response = $this->post('/repair/track', [
            'tracking_reference' => strtolower($repair->tracking_reference),
            'contact_phone' => '۰۹۱۲۳۴۵۶۷۸۹',
        ])->assertOk()->assertSee('دریافت فیزیکی تأیید نشده است');
        foreach ([$repair->customer_name, $repair->contact_phone, $repair->device_model, $repair->issue, $repair->tracking_reference, $repair->phone_digest] as $sensitive) {
            $response->assertDontSee($sensitive);
        }
        $response->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNull(session('_old_input'));
    }

    public function test_unknown_reference_and_incorrect_phone_have_identical_generic_responses(): void
    {
        $repair = $this->submit();
        $wrongPhone = $this->post('/repair/track', ['tracking_reference' => $repair->tracking_reference, 'contact_phone' => '09999999999'])->assertOk();
        $unknownCode = $this->post('/repair/track', ['tracking_reference' => 'NP-'.str_repeat('F', 32), 'contact_phone' => '09123456789'])->assertOk();
        $this->assertSame($wrongPhone->getContent(), $unknownCode->getContent());
        $unknownCode->assertSee('اطلاعات واردشده قابل تأیید نیست.')->assertDontSee($repair->customer_name);
        $this->post('/repair/track', ['tracking_reference' => $repair->tracking_reference])->assertSessionHasErrors('contact_phone');
        $this->get('/repair/track?tracking_reference='.$repair->tracking_reference.'&contact_phone=09123456789')
            ->assertOk()->assertDontSee($repair->tracking_reference)->assertDontSee('09123456789');
        $this->get('/repair/'.$repair->id)->assertNotFound();
    }

    public function test_tracking_validation_does_not_reflect_or_flash_unsafe_input(): void
    {
        $this->post('/repair/track', ['tracking_reference' => '<script>alert(1)</script>', 'contact_phone' => ['invalid']])
            ->assertRedirect(route('repair.track'))->assertSessionHasErrors(['tracking_reference', 'contact_phone'])
            ->assertSessionMissing('_old_input');
        $this->get('/repair/track')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_tracking_rate_limit_counts_invalid_attempts_and_expires(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/repair/track', [])->assertSessionHasErrors();
        }
        $this->post('/repair/track', [])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->post('/repair/track', [])->assertRedirect(route('repair.track'));
    }

    public function test_confirmation_cannot_be_opened_without_a_session_receipt(): void
    {
        $this->get('/repair/confirmation')->assertRedirect(route('repair'));
        $this->get('/repair')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_hourly_tracking_limit_cannot_be_bypassed_by_waiting_between_minute_windows(): void
    {
        for ($window = 0; $window < 6; $window++) {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $this->post('/repair/track', [])->assertRedirect(route('repair.track'));
            }
            $this->travel(61)->seconds();
        }
        $this->post('/repair/track', [])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_repair_posts_enforce_native_csrf_protection(): void
    {
        $data = $this->payload();
        $this->app->detectEnvironment(fn () => 'local');
        $this->post('/repair', $data)->assertStatus(419);
        $this->post('/repair/track', [])->assertStatus(419);
        $this->assertDatabaseCount('repair_requests', 0);
    }
}
