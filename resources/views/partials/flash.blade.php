{{-- Hlášení po akci. $sticky: drží se nahoře pod lištou administrace a potvrzení samo zmizí. --}}
@if (session('status'))
    <div class="banner mb-3 @if ($sticky ?? false) flash-sticky @endif" role="status"
         @if ($sticky ?? false) x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 12000)" x-on:click="show = false" @endif>{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="banner banner-danger mb-3 @if ($sticky ?? false) flash-sticky @endif" role="alert"
         @if ($sticky ?? false) x-data="{ show: true }" x-show="show" x-on:click="show = false" @endif>{{ session('error') }}</div>
@endif
