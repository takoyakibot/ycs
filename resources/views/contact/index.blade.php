<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            お問い合わせ
        </h2>
    </x-slot>

    <x-slot name="alpine_script">
        @if(config('services.recaptcha.site_key'))
        <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
        @endif
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    @if (session('status') === 'contact-sent')
                        <div class="mb-6 p-4 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded-lg">
                            <p class="font-medium">お問い合わせを送信しました。</p>
                            <p class="text-sm mt-1">ご連絡いただきありがとうございます。内容を確認の上、必要に応じてご返信いたします。</p>
                        </div>
                    @endif

                    <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                        サービスに関するご質問、不具合報告、機能リクエストなど、お気軽にお問い合わせください。
                    </p>

                    <form method="POST" action="{{ route('contact.store') }}" id="contactForm">
                        @csrf

                        <!-- お名前 -->
                        <div class="mb-4">
                            <x-input-label for="name" value="お名前（任意）" />
                            <x-text-input
                                id="name"
                                name="name"
                                type="text"
                                class="mt-1 block w-full"
                                :value="old('name')"
                                maxlength="100"
                                placeholder="お名前"
                            />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <!-- メールアドレス -->
                        <div class="mb-4">
                            <x-input-label for="email" value="メールアドレス" />
                            <x-text-input
                                id="email"
                                name="email"
                                type="email"
                                class="mt-1 block w-full"
                                :value="old('email')"
                                required
                                maxlength="255"
                                placeholder="example@example.com"
                            />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <!-- お問い合わせ種別 -->
                        <div class="mb-4">
                            <x-input-label for="category" value="お問い合わせ種別" />
                            <select
                                id="category"
                                name="category"
                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                                required
                            >
                                <option value="">選択してください</option>
                                <option value="general" {{ old('category') === 'general' ? 'selected' : '' }}>一般的なお問い合わせ</option>
                                <option value="bug" {{ old('category') === 'bug' ? 'selected' : '' }}>不具合報告</option>
                                <option value="feature" {{ old('category') === 'feature' ? 'selected' : '' }}>機能リクエスト</option>
                                <option value="other" {{ old('category') === 'other' ? 'selected' : '' }}>その他</option>
                            </select>
                            <x-input-error :messages="$errors->get('category')" class="mt-2" />
                        </div>

                        <!-- お問い合わせ内容 -->
                        <div class="mb-4">
                            <x-input-label for="message" value="お問い合わせ内容" />
                            <textarea
                                id="message"
                                name="message"
                                rows="6"
                                class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                                required
                                minlength="10"
                                maxlength="5000"
                                placeholder="お問い合わせ内容をご記入ください（10文字以上）"
                            >{{ old('message') }}</textarea>
                            <x-input-error :messages="$errors->get('message')" class="mt-2" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                <span id="messageCount">0</span> / 5000 文字
                            </p>
                        </div>

                        <!-- reCAPTCHA token -->
                        <input type="hidden" name="recaptcha_token" id="recaptcha_token">
                        <x-input-error :messages="$errors->get('recaptcha_token')" class="mt-2 mb-4" />

                        <div class="flex items-center justify-between">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                このフォームは reCAPTCHA で保護されています。
                            </p>
                            <x-primary-button type="submit" id="submitBtn">
                                送信
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- GitHubリンク -->
            <div class="mt-6 text-center text-sm text-gray-600 dark:text-gray-400">
                <p>
                    不具合報告や機能リクエストは
                    <a href="https://github.com/takoyakibot/ycs/issues" target="_blank" rel="noopener noreferrer" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                        GitHub Issues
                    </a>
                    からも受け付けています。
                </p>
            </div>
        </div>
    </div>

    <script>
        // 文字数カウント
        const messageTextarea = document.getElementById('message');
        const messageCount = document.getElementById('messageCount');

        messageTextarea.addEventListener('input', function() {
            messageCount.textContent = this.value.length;
        });

        // 初期値の文字数を表示
        messageCount.textContent = messageTextarea.value.length;

        // reCAPTCHA v3
        @if(config('services.recaptcha.site_key'))
        document.getElementById('contactForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const submitBtn = document.getElementById('submitBtn');

            submitBtn.disabled = true;
            submitBtn.textContent = '送信中...';

            grecaptcha.ready(function() {
                grecaptcha.execute('{{ config('services.recaptcha.site_key') }}', {action: 'contact'}).then(function(token) {
                    document.getElementById('recaptcha_token').value = token;
                    form.submit();
                }).catch(function(error) {
                    console.error('reCAPTCHA error:', error);
                    submitBtn.disabled = false;
                    submitBtn.textContent = '送信';
                    alert('reCAPTCHA認証でエラーが発生しました。ページを再読み込みしてください。');
                });
            });
        });
        @endif
    </script>
</x-app-layout>
