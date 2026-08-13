@props([
    'label' => null,
    'name' => null,
])

{{-- Groups a label, control, and validation error.
     Pass :name to render the label + error automatically:
         <x-id::field :label="__('Name')" name="name">
             <x-id::input wire:model="name" />
         </x-id::field>
     Or compose manually with <x-id::label> / <x-id::error> in the slot. --}}
<div {{ $attributes->class('flex flex-col gap-1.5') }}>
    @if ($label)
        <x-id::label :for="$name">{{ $label }}</x-id::label>
    @endif

    {{ $slot }}

    @if ($name)
        <x-id::error :name="$name" />
    @endif
</div>
