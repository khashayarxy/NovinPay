<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

class PageController extends Controller
{
    public function contact(): View
    {
        return view('pages.contact', ['canonical' => rtrim(config('app.url'), '/').'/contact']);
    }

    public function discovery(): Response
    {
        $origin = rtrim(config('app.url'), '/');
        $text = '# '.config('business.name')."\n\n> فروش و تعمیر دستگاه‌های کارتخوان در شهریار.\n\n## صفحات عمومی\n\n";
        foreach (['/' => 'معرفی و خدمات تعمیر کارتخوان', '/products' => 'فروشگاه و محصولات منتشرشده', '/contact' => 'اطلاعات تماس، نشانی و ساعات مراجعه', '/articles' => 'مقاله‌ها و راهنماهای منتشرشده', '/sitemap.xml' => 'نقشهٔ صفحات عمومی'] as $path => $label) {
            $text .= "- [{$label}]({$origin}{$path})\n";
        }

        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function terms(): View
    {
        return view('pages.terms');
    }

    public function privacy(): View
    {
        return view('pages.privacy');
    }
}
