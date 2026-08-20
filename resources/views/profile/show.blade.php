@extends('layouts.app')
@section('title', $user->name . ' - Profile')
@section('content')
@php
    $statusLabels = \App\Support\WorkflowTransitions::labels();
    $isOwnProfile = auth()->id() === $user->id;

    // url()->previous() ikut Referer header - kalau halaman ini di-reload
    // (misal abis generate AI brief lalu redirect balik ke sini), Referer-nya
    // jadi halaman ini sendiri, bikin tombol back looping ke diri sendiri.
    // Fallback ke Beranda kalau ketauan previous = halaman sekarang.
    $backUrl = url()->previous();
    $backPath = parse_url($backUrl, PHP_URL_PATH) ?? '';
    if (trim($backPath, '/') === trim(request()->path(), '/')) {
        $backUrl = route('profile.me');
    }
@endphp

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    {{-- Header --}}
    <div class="flex items-center gap-4 mb-8 flex-wrap">
        <a href="{{ $backUrl }}" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        @if ($user->avatar_url)
            <img src="{{ $user->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-16 h-16 rounded-full object-cover">
        @else
            <div class="w-16 h-16 rounded-full bg-[var(--brand-solid)] text-white text-xl font-semibold flex items-center justify-center">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
        @endif
        <div>
            <div class="flex items-center gap-2">
                <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $user->name }}</h1>
                @if ($isOwnProfile)
                    <span class="badge badge-success">Anda</span>
                @endif
            </div>
            <p class="text-sm text-[var(--text-secondary)]">{{ $user->roleNamesLabel() }}</p>
            <p class="text-xs text-[var(--text-muted)] mt-1">Bergabung sejak {{ $user->created_at->format('d M Y') }}</p>
        </div>
    </div>

    @include('partials.user-work-summary')
</div>
@endsection
