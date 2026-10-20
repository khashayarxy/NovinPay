<?php

use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

Route::get('/services', [PageController::class, 'services'])->name('services');
Route::get('/repair', [PageController::class, 'repair'])->name('repair');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::get('/terms', [PageController::class, 'terms'])->name('terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [LoginController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [LoginController::class, 'store'])->name('admin.login.store');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    // Keep logout available even if administrator authorization is revoked.
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware('can:access-admin')->group(function (): void {
        Route::get('/', fn () => redirect()->route('admin.products.index'))->name('dashboard');

        Route::resource('products', AdminProductController::class)->except('show');
    });
});
