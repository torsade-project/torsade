{{-- torsade/resources/views/partials/logo-mark.blade.php --}}
{{-- The symbol of the application, for the pages drawn without the
     interface: the same two strands as the logo component. --}}
<svg class="{{ $class ?? '' }}" viewBox="0 0 32 32" fill="none" stroke-width="3" stroke-linecap="round" aria-hidden="true">
    <path d="M3 16 C 8 5, 12 5, 16 16 S 24 27, 29 16" class="stroke-current" />
    <path d="M3 16 C 8 27, 12 27, 16 16 S 24 5, 29 16" class="stroke-pair-orange" />
</svg>
