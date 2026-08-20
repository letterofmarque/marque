@props([
    'label' => null,
    'name' => null,
])

{{-- Groups a label, control, and validation error.
     Pass :name to render the label + error automatically:
         <x-ise::field :label="__('Name')" name="name">
             <x-ise::input wire:model="name" />
         </x-ise::field>
     Or compose manually with <x-ise::label> / <x-ise::error> in the slot. --}}
<div {{ $attributes->class('flex flex-col gap-1.5') }}>
    @if ($label)
        <x-ise::label :for="$name">{{ $label }}</x-ise::label>
    @endif

    {{ $slot }}

    @if ($name)
        <x-ise::error :name="$name" />
    @endif
</div>
