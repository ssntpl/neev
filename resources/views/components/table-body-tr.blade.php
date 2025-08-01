@props(['class' => ''])
<tr class="hover:bg-gray-100 py-4 dark:hover:bg-gray-700 transition {{$class}}" {{ $attributes }}>
    {{$slot}}
</tr>