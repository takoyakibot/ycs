<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Google アカウント連携') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('YouTube APIへのアクセスに使用されているGoogleアカウント情報') }}
        </p>
    </header>

    @if ($user->google_token)
        <div class="mt-6 space-y-6">
            <!-- 連携アカウント情報 -->
            <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                @if ($user->avatar)
                    <img src="{{ $user->avatar }}" alt="{{ $user->name }}" class="w-16 h-16 rounded-full">
                @else
                    <div class="w-16 h-16 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center">
                        <span class="text-2xl text-gray-600 dark:text-gray-300">{{ substr($user->name, 0, 1) }}</span>
                    </div>
                @endif

                <div class="flex-1">
                    <div class="font-medium text-gray-900 dark:text-gray-100">
                        {{ $user->name }}
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $user->email }}
                    </div>
                    @if ($user->google_id)
                        <div class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                            Google ID: {{ $user->google_id }}
                        </div>
                    @endif
                </div>

                <div class="flex items-center text-green-600 dark:text-green-400">
                    <svg class="w-5 h-5 mr-1" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                            clip-rule="evenodd" />
                    </svg>
                    <span class="text-sm font-medium">連携済み</span>
                </div>
            </div>

            <!-- 連携情報 -->
            <div class="text-sm text-gray-600 dark:text-gray-400">
                <div class="flex items-start space-x-2">
                    <svg class="w-5 h-5 mt-0.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <p>このGoogleアカウントを使用してYouTube Data APIにアクセスしています。</p>
                        <p class="mt-1">アクセストークンは自動的に更新されます。</p>
                    </div>
                </div>
            </div>

            <!-- 権限情報 -->
            <div class="text-sm">
                <div class="font-medium text-gray-700 dark:text-gray-300 mb-2">付与されている権限:</div>
                <ul class="list-disc list-inside text-gray-600 dark:text-gray-400 space-y-1">
                    <li>YouTube Data API v3 - 読み取り専用</li>
                </ul>
            </div>
        </div>
    @else
        <div class="mt-6">
            <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                <div class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-500 mt-0.5" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="flex-1">
                        <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                            Googleアカウントが連携されていません
                        </h3>
                        <p class="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                            YouTube APIを使用するには、Googleアカウントでログインしてください。
                        </p>
                        <div class="mt-4">
                            <a href="{{ route('google.redirect') }}"
                                class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150">
                                <svg class="w-4 h-4 mr-2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="#4285F4"
                                        d="M23.745 12.27c0-.79-.07-1.54-.19-2.27h-11.3v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82Z" />
                                    <path fill="#34A853"
                                        d="M12.255 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96h-3.98v3.09C3.515 21.3 7.565 24 12.255 24Z" />
                                    <path fill="#FBBC05"
                                        d="M5.525 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62h-3.98a11.86 11.86 0 0 0 0 10.76l3.98-3.09Z" />
                                    <path fill="#EA4335"
                                        d="M12.255 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C18.205 1.19 15.495 0 12.255 0c-4.69 0-8.74 2.7-10.71 6.62l3.98 3.09c.95-2.85 3.6-4.96 6.73-4.96Z" />
                                </svg>
                                Googleアカウントで連携
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>
