<?php

namespace Tests\Feature;

use App\Http\Middleware\AddSecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    public function test_https_uses_short_host_only_hsts_and_http_does_not(): void
    {
        $this->get('https://localhost/')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=300');
        $this->get('http://localhost/')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_html_policies_preserve_existing_endpoint_csp_and_webotp(): void
    {
        $response = $this->get('/')->assertOk();
        $response->assertHeader('Content-Security-Policy', "object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
        $report = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("frame-src 'self' https://neshan.org", $report);
        $this->assertStringNotContainsString('unsafe-eval', $report);
        $permissions = $response->headers->get('Permissions-Policy');
        $this->assertStringContainsString('payment=()', $permissions);
        $this->assertStringNotContainsString('otp-credentials', $permissions);
        $middleware = new AddSecurityHeaders;
        $protected = $middleware->handle(Request::create('/private'), fn () => response('private', 200, [
            'Content-Type' => 'text/html',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Strict-Transport-Security' => 'max-age=86400',
            'Permissions-Policy' => 'camera=()',
        ]));
        $this->assertSame("default-src 'none'; sandbox", $protected->headers->get('Content-Security-Policy'));
        $this->assertSame('max-age=86400', $protected->headers->get('Strict-Transport-Security'));
        $this->assertSame('camera=()', $protected->headers->get('Permissions-Policy'));
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
