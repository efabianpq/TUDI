<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Mi cuenta') }}</h1>
    </x-slot>

    <div class="mx-auto max-w-2xl space-y-4">
        <div class="tudi-card p-5 sm:p-6">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="tudi-card p-5 sm:p-6">
            @include('profile.partials.update-password-form')
        </div>

        <div class="tudi-card p-5 sm:p-6">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
