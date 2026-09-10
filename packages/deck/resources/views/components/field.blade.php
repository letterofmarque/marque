@props([
    'label' => null,
    'name' => null,
])

{{-- Groups a label, control, and validation error.
     Pass :name to render the label + error automatically:
         <x-deck::field :label="__('Name')" name="name">
             <x-deck::input wire:model="name" />
         </x-deck::field>
     Or compose manually with <x-deck::label> / <x-deck::error> in the slot. --}}
<div {{ $attributes->class('flex flex-col gap-1.5') }}>
    @if ($label)
        <x-deck::label :for="$name">{{ $label }}</x-deck::label>
    @endif

    {{ $slot }}

    @if ($name)
        <x-deck::error :name="$name" />
    @endif
</div>
