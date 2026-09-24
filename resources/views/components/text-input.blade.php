@props(['disabled' => false])

<input @disabled($disabled)
    {{ $attributes->merge(['class' => 'border-gray-300 focus:border-[#ff9933] focus:ring-[#ff9933] rounded-md shadow-sm']) }}>
