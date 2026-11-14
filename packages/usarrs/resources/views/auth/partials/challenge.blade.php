{{-- The app's human check, when it binds one (Job #366). $form is a
     Marque\Usarrs\Contracts\Challenge form; $livewire says how the answer
     travels: bound to the component's $challenge, or posted as `challenge`. --}}
@if (\Marque\Usarrs\Auth\ChallengeGate::active($form))
    <div class="space-y-1">
        <div wire:ignore>{!! app(\Marque\Usarrs\Contracts\Challenge::class)->widget($form) !!}</div>

        @if ($livewire ?? true)
            <input type="hidden" wire:model="challenge" data-usarrs-challenge>
        @else
            <input type="hidden" name="challenge" value="" data-usarrs-challenge>
        @endif

        @error('challenge')
            <x-deck::text class="text-sm text-red-600">{{ $message }}</x-deck::text>
        @enderror
    </div>
@endif
