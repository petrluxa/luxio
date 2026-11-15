@if (session('status'))
    <div class="banner mb-3" role="status">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="banner banner-danger mb-3" role="alert">{{ session('error') }}</div>
@endif
