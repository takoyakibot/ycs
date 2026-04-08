<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            APIトークン
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Chrome拡張や外部ツールからYCSのAPIにアクセスするためのトークンです。
        </p>
    </header>

    {{-- 新しいトークンが発行された場合の表示 --}}
    @if (session('new_api_token'))
        <div class="mt-4 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 rounded-md">
            <p class="text-sm font-medium text-green-800 dark:text-green-200">
                トークンが発行されました。この値はもう一度表示できないのでコピーしてください。
            </p>
            <div class="mt-2 flex items-center gap-2">
                <code id="api-token-value" class="block flex-1 p-2 bg-white dark:bg-gray-900 border border-green-300 dark:border-green-600 rounded text-sm font-mono text-gray-900 dark:text-gray-100 break-all">{{ session('new_api_token') }}</code>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('api-token-value').textContent).then(() => this.textContent = 'Copied!')" class="px-3 py-2 text-sm bg-green-600 text-white rounded hover:bg-green-700">
                    Copy
                </button>
            </div>
        </div>
    @endif

    @if (session('status') === 'api-token-deleted')
        <div class="mt-4 p-3 bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-700 rounded-md">
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                APIトークンを失効しました。
            </p>
        </div>
    @endif

    @php
        $currentToken = auth()->user()->tokens()->latest()->first();
    @endphp

    @if ($currentToken)
        {{-- 既存トークンの情報表示 --}}
        <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-md">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $currentToken->name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        発行日: {{ $currentToken->created_at->format('Y-m-d H:i') }}
                        @if ($currentToken->last_used_at)
                            / 最終使用: {{ $currentToken->last_used_at->format('Y-m-d H:i') }}
                        @endif
                    </p>
                </div>
                <form method="post" action="{{ route('profile.api-token.destroy') }}" onsubmit="return confirm('このトークンを失効しますか？')">
                    @csrf
                    @method('delete')
                    <x-danger-button type="submit">
                        失効
                    </x-danger-button>
                </form>
            </div>
        </div>
    @endif

    {{-- トークン発行フォーム --}}
    <form method="post" action="{{ route('profile.api-token.create') }}" class="mt-4">
        @csrf
        <div class="flex items-end gap-4">
            <div class="flex-1">
                <x-input-label for="token_name" value="トークン名" />
                <x-text-input id="token_name" name="token_name" type="text" class="mt-1 block w-full" placeholder="例: Chrome拡張" required />
                <x-input-error :messages="$errors->get('token_name')" class="mt-2" />
            </div>
            <x-primary-button>
                {{ $currentToken ? '再発行' : '発行' }}
            </x-primary-button>
        </div>
        @if ($currentToken)
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                再発行すると現在のトークンは失効します。
            </p>
        @endif
    </form>
</section>
