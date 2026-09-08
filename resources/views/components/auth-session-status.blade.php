@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'text-sm font-medium text-tudi-lime-700']) }}>
        {{ $status }}
    </div>
@endif
