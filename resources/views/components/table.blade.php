<div class="overflow-x-auto rounded-lg shadow border border-gray-200 dark:border-gray-700">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm text-gray-700 dark:text-gray-200" {{ $attributes }}>
        <thead class="bg-gray-100 dark:bg-gray-800">
            {{$head ?? ''}}
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 font-medium">
           {{$body}}
        </tbody>
    </table>
</div>