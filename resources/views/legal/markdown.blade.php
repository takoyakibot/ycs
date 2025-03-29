<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('利用規約・プライバシーポリシー') }}
        </h2>
    </x-slot>

    <div class="terms-container flex flex-col p-6 items-center">
        <div class="p-2">
            <div class="gap-4 w-[100%] max-w-5xl border shadow p-4 rounded-lg mb-6 bg-white">
                {!! $terms !!}
            </div>
            <div class="gap-4 w-[100%] max-w-5xl border shadow p-4 rounded-lg mb-6 bg-white">
                {!! $privacyPolicy !!}
            </div>
        </div>
    </div>
</x-app-layout>
