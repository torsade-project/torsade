{{-- torsade/resources/views/partials/theme.blade.php --}}
{{-- The dark theme is the default one and is set by the server on the page
     itself. Only the "system" choice needs the browser: this inline script
     applies it before the first paint. --}}
<script @if (! empty($nonce)) nonce="{{ $nonce }}" @endif>
    (function() {
        const appearance = '{{ $appearance }}';

        if (appearance === 'system') {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (prefersDark) {
                document.documentElement.classList.add('dark');
            }
        }
    })();
</script>
