<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Services\CustomerLogin;
use App\Support\AdminAccess;
use App\Support\PaymentTestProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductMediaController extends Controller
{
    public function __invoke(Request $request, string $filename, CustomerLogin $login): BinaryFileResponse
    {
        $relativePath = 'products/'.$filename;
        $path = Storage::disk('public')->path($relativePath);
        abort_unless(is_file($path) && ! is_link($path), 404);

        $isPrivateAsset = false;
        $testSlug = PaymentTestProduct::slug();
        if ($testSlug !== '') {
            $testProduct = Product::query()->where('slug', $testSlug)->first(['id', 'slug', 'is_published', 'image_path', 'media_details']);
            if ($testProduct && $this->productOwnsMedia($testProduct, $relativePath)) {
                $account = $login->authenticatedAccount($request);
                $adminUser = Auth::guard('web')->user();
                $isAdmin = $adminUser instanceof User && AdminAccess::account($adminUser) !== null;
                abort_unless($isAdmin || ($testProduct->is_published && PaymentTestProduct::canAccess($account)), 404);
                $isPrivateAsset = true;
            }
        }

        $mime = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };

        $response = response()->file($path, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($isPrivateAsset) {
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'no-store, private');
        } else {
            $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        }

        return $response;
    }

    private function productOwnsMedia(Product $product, string $relativePath): bool
    {
        if ($product->image_path === $relativePath) {
            return true;
        }
        foreach ($product->galleryImages() as $entry) {
            if (($entry['path'] ?? null) === $relativePath) {
                return true;
            }
        }

        return false;
    }
}
