@props(['title', 'message'])

<div class="card flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
    <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
        aria-hidden="true" focusable="false">
        <path stroke-linecap="round" stroke-linejoin="round"
            d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
    </svg>
    <p class="text-base font-semibold text-slate-900">{{ $title }}</p>
    <p class="max-w-md text-sm leading-7 text-slate-600">{{ $message }}</p>
    <div class="mt-2">
        {{ $slot }}
    </div>
</div>
