<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)"
                required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !$user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800 dark:text-gray-200">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification"
                            class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600 dark:text-green-400">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <div class="flex items-center gap-2">
                <x-input-label for="api_key" value="YouTube API Key" />
                @if ($user->api_key)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">
                        ✓ 登録済み
                    </span>
                @else
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                        未登録
                    </span>
                @endif
            </div>
            <div class="flex gap-2">
                <x-text-input id="api_key" name="api_key" type="text" class="mt-1 block w-full" :value="old('api_key')" autocomplete="api_key" placeholder="AIzaSy..." />
                @if ($user->api_key)
                    <button type="button" onclick="confirmDeleteApiKey()" class="mt-1 px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                        削除
                    </button>
                @endif
            </div>
            <x-input-error class="mt-2" :messages="$errors->get('api_key')" />
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                YouTube Data APIアクセス用のAPIキーを入力してください。<br>
                登録済みの場合は空欄のままで保存すると現在のキーを維持します。変更する場合は新しいキーを入力してください。
            </p>
        </div>

        <!-- APIキー削除用のフォーム -->
        <form id="delete-api-key-form" method="post" action="{{ route('profile.api-key.destroy') }}" class="hidden">
            @csrf
            @method('delete')
        </form>

        <script>
        function confirmDeleteApiKey() {
            if (confirm('APIキーを削除してもよろしいですか？削除後はチャンネル管理機能が使用できなくなります。')) {
                document.getElementById('delete-api-key-form').submit();
            }
        }
        </script>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-gray-400">{{ __('Saved.') }}</p>
            @endif

            @if (session('status') === 'api-key-deleted')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-gray-400">APIキーを削除しました。</p>
            @endif
        </div>
    </form>
</section>