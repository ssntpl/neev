@php
    $status ??= session('status');
@endphp
@if ($status)
    <div {{ $attributes }}>
        <div class="font-medium text-green-600 dark:text-red-400">{{ __('Successfully Done!.') }}</div>

        <div class="mt-3 list-disc list-inside text-sm text-green-600 dark:text-red-400">
            {{ $status }}
        </div>
    </div>
@endif
