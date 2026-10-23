<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BusinessReleaseContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_and_contact_pages_show_the_exact_split_office_hours(): void
    {
        foreach (['/', '/contact'] as $uri) {
            $this->get($uri)->assertOk()
                ->assertSee('شنبه تا چهارشنبه ۰۹:۰۰ تا ۱۸:۳۰')
                ->assertSee('پنجشنبه ۰۹:۰۰ تا ۱۶:۰۰')
                ->assertSee('جمعه: تعطیل برای مراجعهٔ حضوری')
                ->assertDontSee('شنبه تا پنجشنبه ۹ تا ۱۸');
        }
    }

    public function test_both_contact_numbers_have_persian_text_and_canonical_click_to_call_links(): void
    {
        foreach (['/', '/contact', '/repair', '/products'] as $uri) {
            $document = new DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$this->get($uri)->assertOk()->getContent());
            $xpath = new DOMXPath($document);
            foreach (['09362896373' => '۰۹۳۶۲۸۹۶۳۷۳', '09384144021' => '۰۹۳۸۴۱۴۴۰۲۱'] as $number => $label) {
                $footer = $xpath->query('//footer//a[@href="tel:'.$number.'"]/bdi[@dir="ltr"]');
                $this->assertSame(1, $footer->length, $uri);
                $this->assertSame($label, $footer->item(0)->textContent);
                $this->assertSame('ltr', $footer->item(0)->parentNode->getAttribute('dir'));
                if ($uri === '/contact') {
                    $this->assertSame(1, $xpath->query('//main//a[@href="tel:'.$number.'"]')->length);
                }
            }
        }
    }

    public function test_shared_contacts_use_the_same_configuration_and_escape_contact_values(): void
    {
        config(['business.secondary_phone' => '<script>alert(1)</script>', 'business.secondary_phone_uri' => 'tel:TEST-SECONDARY']);
        $html = Blade::render('<x-business-contact />');
        $this->assertStringContainsString('href="tel:TEST-SECONDARY"', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        foreach (['open', 'thursday', 'closed'] as $day) {
            $this->assertStringContainsString(e(config('business.hours.'.$day)), $html);
        }
    }
}
