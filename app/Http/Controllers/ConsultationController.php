<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConsultation;
use App\Models\Consultation;
use App\Support\RepairIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConsultationController extends Controller
{
    public function create(Request $request)
    {
        if (! $request->session()->has('consultation_token')) {
            $request->session()->put('consultation_token', bin2hex(random_bytes(32)));
        }

        return view('pages.consultation', ['token' => $request->session()->get('consultation_token')]);
    }

    public function store(StoreConsultation $request)
    {
        $data = $request->validated();
        $token = $request->session()->get('consultation_token');
        if (! is_string($token) || ! hash_equals($token, $data['submission_token'])) {
            return $this->duplicate($request);
        }
        try {
            $consultation = new Consultation;
            $consultation->public_id = (string) Str::uuid();
            $consultation->submission_digest = RepairIdentity::digest('consultation-submit', $token);
            $consultation->phone_digest = RepairIdentity::digest('phone', $data['contact_phone']);
            foreach (['customer_name', 'contact_phone', 'topic', 'device_info', 'question'] as $field) {
                $consultation->$field = $data[$field] ?? null;
            }
            $consultation->save();
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate($request);
        }
        $request->session()->forget('consultation_token');

        return redirect()->route('consultation.confirmation')->with('consultation_received', true);
    }

    public function confirmation(Request $request)
    {
        return $request->session()->get('consultation_received') === true
            ? view('pages.consultation-confirmation')
            : redirect()->route('consultation');
    }

    private function duplicate(Request $request)
    {
        $request->session()->forget('consultation_token');

        return redirect()->route('consultation')->withErrors(['submission_token' => 'فرم قبلاً پردازش شده یا منقضی است. پیش از ثبت دوباره، درخواست قبلی را بررسی کنید.']);
    }
}
