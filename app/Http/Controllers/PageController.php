<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    public function services(): View
    {
        return view('pages.services');
    }

    public function repair(): View
    {
        return view('pages.repair');
    }

    public function contact(): View
    {
        return view('pages.contact');
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
