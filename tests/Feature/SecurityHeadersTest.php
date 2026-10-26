<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_responses_carry_baseline_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_admin_login_response_carries_baseline_security_headers(): void
    {
        $response = $this->get(route('admin.login'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_not_found_response_carries_baseline_security_headers(): void
    {
        // The exception path must still run the global middleware so error
        // pages (and any other HttpException response, including 419) get
        // the same headers as successful ones.
        $response = $this->get('/definitely-missing-page-'.uniqid());

        $response->assertNotFound();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertSee('خطای ۴۰۴');
    }

    public function test_persian_error_pages_render_rtl_layout(): void
    {
        foreach (['errors.403', 'errors.419', 'errors.500', 'errors.503'] as $view) {
            $html = view($view)->render();

            $this->assertStringContainsString('lang="fa"', $html, "View [{$view}] must be Persian RTL.");
            $this->assertStringContainsString('dir="rtl"', $html, "View [{$view}] must be Persian RTL.");
        }
    }

    public function test_persian_error_pages_have_meaningful_headings(): void
    {
        $this->assertStringContainsString('دسترسی به این بخش مجاز نیست', view('errors.403')->render());
        $this->assertStringContainsString('نشست شما منقضی شده است', view('errors.419')->render());
        $this->assertStringContainsString('خطایی در سرور رخ داد', view('errors.500')->render());
        $this->assertStringContainsString('در حال تعمیرات و به‌روزرسانی سایت نوین‌پی هستیم', view('errors.503')->render());
    }
}
