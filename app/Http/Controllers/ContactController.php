<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Mail\ContactFormMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ContactController extends Controller
{
    /**
     * お問い合わせフォームを表示
     */
    public function show(): View
    {
        return view('contact.index');
    }

    /**
     * お問い合わせを送信
     */
    public function store(ContactRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // IPアドレスとユーザーエージェントを追加
        $validated['ip_address'] = $request->ip();
        $validated['user_agent'] = $request->userAgent();
        $validated['submitted_at'] = now()->format('Y-m-d H:i:s');

        // 管理者にメール送信
        $adminEmail = config('mail.admin_address');
        if ($adminEmail) {
            Mail::to($adminEmail)->send(new ContactFormMail($validated));
        }

        return redirect()->route('contact.show')->with('status', 'contact-sent');
    }
}
