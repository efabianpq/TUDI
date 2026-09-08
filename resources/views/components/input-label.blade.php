@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[13px] font-semibold text-tudi-ink']) }}>
    {{ $value ?? $slot }}
</label>
