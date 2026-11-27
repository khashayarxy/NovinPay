<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\SalesOrder;
use App\Services\ActivationDocumentsService;
use App\Services\CustomerLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

final class OrderActivationDocumentsController extends Controller
{
    public function storeForAccount(Request $request, string $record, ActivationDocumentsService $service): RedirectResponse
    {
        /** @var CustomerAccount $account */
        $account = Auth::guard('customer')->user();
        $query = SalesOrder::query()
            ->where(function ($q) use ($account): void {
                $q->where('customer_account_id', $account->id);
                if ($account->customer_id) {
                    $q->orWhere('customer_id', $account->customer_id);
                }
            });

        if (preg_match('/\A[A-Za-z0-9]{8}\z/', $record) === 1) {
            $matches = $query->where('public_id', 'like', strtolower($record).'-%')->limit(2)->get();
            abort_unless($matches->count() === 1, 404);
            $order = $matches->first();
        } else {
            $order = $query->where('public_id', $record)->firstOrFail();
        }

        return $this->processUpload(
            request: $request,
            order: $order,
            service: $service,
            redirectUrl: route('account.orders.show', $order->public_id),
        );
    }

    public function storeForShortOrder(Request $request, string $code, CustomerLogin $login, ActivationDocumentsService $service): RedirectResponse
    {
        $matches = SalesOrder::query()
            ->where('public_id', 'like', strtolower($code).'%')
            ->limit(2)
            ->get();
        abort_unless($matches->count() === 1, 404);
        $order = $matches->first();

        $account = $login->authenticatedAccount($request);
        $ownsViaAccount = $account && (
            (int) $order->customer_account_id === (int) $account->id
            || ($account->customer_id && (int) $order->customer_id === (int) $account->customer_id)
        );
        $sessionExpiry = (int) $request->session()->get('verified_short_order_docs.'.$order->public_id, 0);
        $ownsViaShortTrack = $sessionExpiry >= now()->timestamp;

        abort_unless($ownsViaAccount || $ownsViaShortTrack, 403, 'برای ارسال مدارک ابتدا شماره موبایل سفارش را تأیید کنید.');

        return $this->processUpload(
            request: $request,
            order: $order,
            service: $service,
            redirectUrl: $ownsViaAccount
                ? route('account.orders.show', $order->public_id)
                : route('account.orders.short', ['code' => $code]),
        );
    }

    private function processUpload(
        Request $request,
        SalesOrder $order,
        ActivationDocumentsService $service,
        string $redirectUrl,
    ): RedirectResponse {
        if (! $service->canCustomerUpload($order)) {
            return redirect()->to($redirectUrl)->withErrors([
                'documents' => 'در حال حاضر امکان ارسال مدارک برای این سفارش فعال نیست.',
            ]);
        }

        $slots = $service->configuredSlots();
        $maxKb = max(512, (int) config('novinpay.docs_max_file_kb', 8192));
        $maxExtra = max(0, (int) config('novinpay.docs_max_extra_files', 4));

        $rules = [
            'slots' => ['required', 'array'],
            'extra_files' => ['nullable', 'array', 'max:'.$maxExtra],
            'extra_files.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:'.$maxKb,
            ],
        ];
        $attributes = [
            'extra_files' => 'فایل‌های تکمیلی',
            'extra_files.*' => 'فایل تکمیلی',
        ];

        foreach ($slots as $slotKey => $meta) {
            $rules['slots.'.$slotKey] = [
                $meta['required'] ? 'required' : 'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:'.$maxKb,
            ];
            $attributes['slots.'.$slotKey] = $meta['label'];
        }

        $messages = [
            'required' => 'بارگذاری «:attribute» الزامی است.',
            'file' => '«:attribute» باید یک فایل معتبر باشد.',
            'mimes' => 'فرمت «:attribute» باید JPG، PNG، WebP یا PDF باشد.',
            'mimetypes' => 'محتوای «:attribute» باید تصویر معتبر یا فایل PDF باشد.',
            'max.file' => 'حجم «:attribute» بیش از حد مجاز است.',
            'extra_files.max' => 'تعداد فایل‌های تکمیلی بیش از سقف مجاز است.',
        ];

        $request->validate($rules, $messages, $attributes);

        $slotFiles = [];
        foreach (array_keys($slots) as $slotKey) {
            $file = $request->file('slots.'.$slotKey);
            if ($file instanceof UploadedFile) {
                $slotFiles[$slotKey] = $file;
            }
        }

        $extraFiles = [];
        foreach ((array) $request->file('extra_files', []) as $extra) {
            if ($extra instanceof UploadedFile) {
                $extraFiles[] = $extra;
            }
        }

        $service->submitCustomerDocuments($order, $slotFiles, $extraFiles);

        return redirect()->to($redirectUrl)->with(
            'success',
            'مدارک شما به‌صورت رمزنگاری‌شده ثبت شد و در صف بررسی کارشناسان نوین‌پی قرار گرفت.',
        );
    }
}
