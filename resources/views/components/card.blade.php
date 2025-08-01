@props(['class' => '', 'contentAttr' => ''])
<div class="w-full rounded-lg px-4 py-4 bg-white dark:bg-gray-800 shadow {{$class}}" {{$attributes}}>
    <div class="flex justify-between">
        <div class="text-lg font-bold">
            {{$title}}
        </div>
        <div class="flex gap-2 items-center">
            {{$action ?? ''}}
        </div>
    </div>
    <div class="py-4">
        <div class="border-t border-gray-200 dark:border-gray-700"></div>
    </div>
    <div class="flex flex-col gap-4 px-4" {{$contentAttr}}>
        {{$content ?? ''}}
    </div>
</div>
