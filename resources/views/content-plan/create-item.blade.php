@extends('layouts.app')
@section('title', 'Tambah Konten')
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-2xl mx-auto">

    <div class="flex items-center gap-3 mb-7" x-data="{
            tooltip: { show: false, text: '', top: 0, left: 0 },
            showTooltip(event, text) {
                const rect = event.currentTarget.getBoundingClientRect();
                this.tooltip = { show: true, text, top: rect.top - 8, left: rect.left + rect.width / 2 };
            },
            hideTooltip() { this.tooltip.show = false; },
        }">
        <a href="{{ route('content-plan.show', $contentPlan) }}"
           @mouseenter="showTooltip($event, 'Kembali ke Content Plan')" @mouseleave="hideTooltip()"
           aria-label="Kembali ke Content Plan"
           class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        <template x-teleport="body">
            <div x-show="tooltip.show" x-cloak x-transition.opacity.duration.100ms
                class="pointer-events-none fixed z-[100] whitespace-nowrap"
                :style="`top: ${tooltip.top}px; left: ${tooltip.left}px; transform: translate(-50%, -100%);`">
                <div class="relative bg-[var(--brand-solid)] text-white text-xs font-medium px-2.5 py-1.5 rounded-md shadow-lg">
                    <span x-text="tooltip.text"></span>
                    <span class="absolute top-full left-1/2 -translate-x-1/2 w-0 h-0 border-x-[5px] border-x-transparent border-t-[6px] border-t-[var(--brand)]"></span>
                </div>
            </div>
        </template>
        <div>
            <p class="text-xs text-[var(--text-muted)]">{{ $contentPlan->client->name }} / {{ \Carbon\Carbon::create()->month($contentPlan->month)->translatedFormat('F') }} {{ $contentPlan->year }}</p>
            <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">Tambah Konten</h1>
        </div>
    </div>

    @php
        // Dipakai buat inisialisasi field estimasi kondisional (Alpine) pas
        // form di-redisplay ulang setelah validasi gagal - biar field yang
        // relevan tetap kebuka, bukan ketutup gara-gara jenis konten yang
        // udah dipilih user hilang dari state Alpine.
        $oldContentTypeName = old('content_type_id')
            ? optional($types->firstWhere('id', (int) old('content_type_id')))->name ?? ''
            : '';
    @endphp
    <form action="{{ route('content-plan.items.store', $contentPlan) }}" method="POST" class="space-y-5"
          x-data="{ contentTypeName: {{ Illuminate\Support\Js::from($oldContentTypeName) }} }">
        @csrf

        <div class="card p-6 space-y-4">
        <p class="text-sm font-semibold text-[var(--text-primary)] mb-1 flex items-center gap-2">
            <span class="material-symbols-outlined text-[var(--brand)] text-[19px]">draft</span>
            Detail Konten
        </p>

        <div>
            <label for="title" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Judul Konten <span class="text-[var(--danger-text)]">*</span></label>
            <input id="title" type="text" name="title" required value="{{ old('title') }}" placeholder="Tips Memilih Warna Cat Rumah"
                   class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('title') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
            @error('title') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="brief" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Brief Konten</label>
            <textarea id="brief" name="brief" rows="4" placeholder="Tuliskan brief detail, hook, dan poin utama konten..."
                      class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('brief') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">{{ old('brief') }}</textarea>
            @error('brief') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label for="content_pillar_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Pilar Konten</label>
                <select id="content_pillar_id" name="content_pillar_id" class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('content_pillar_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                    <option value="">Pilih Pilar...</option>
                    @foreach ($pillars as $p) <option value="{{ $p->id }}" {{ (string) old('content_pillar_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option> @endforeach
                </select>
                @error('content_pillar_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="content_type_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Jenis Konten</label>
                <select id="content_type_id" name="content_type_id"
                        x-on:change="contentTypeName = $event.target.selectedOptions[0]?.dataset.name || ''"
                        class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('content_type_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                    <option value="">Pilih Jenis...</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" data-name="{{ $t->name }}" {{ (string) old('content_type_id') === (string) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                    @endforeach
                </select>
                @error('content_type_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="platform_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Platform</label>
                <select id="platform_id" name="platform_id" class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('platform_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                    <option value="">Pilih Platform...</option>
                    @foreach ($platforms as $p) <option value="{{ $p->id }}" {{ (string) old('platform_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option> @endforeach
                </select>
                @error('platform_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label for="deadline_at" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Deadline Produksi <span class="text-[var(--danger-text)]">*</span></label>
                <input id="deadline_at" type="text" name="deadline_at" required value="{{ old('deadline_at') }}" data-flatpickr="datetime" autocomplete="off"
                       class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('deadline_at') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                @error('deadline_at') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="pic_user_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Penanggung Jawab <span class="text-[var(--danger-text)]">*</span></label>
                <select id="pic_user_id" name="pic_user_id" required class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('pic_user_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                    <option value="">Pilih Penanggung Jawab...</option>
                    @foreach ($picOptions as $picUser)
                        <option value="{{ $picUser->id }}" {{ (string) old('pic_user_id') === (string) $picUser->id ? 'selected' : '' }}>{{ $picUser->name }}{{ $picUser->login_enabled ? '' : ' (belum memiliki akses dashboard)' }}</option>
                    @endforeach
                </select>
                @error('pic_user_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                @if ($picOptions->isEmpty())
                    <p class="text-[11px] text-[var(--danger-text)] mt-1">Belum ada anggota tim tercatat untuk client ini - hubungi CEO/Manager untuk mendaftarkan tim yang menangani client ini.</p>
                @endif
            </div>
        </div>

        <div x-show="contentTypeName === 'Video'" x-cloak>
            <label for="estimated_duration_seconds" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Estimasi Durasi Video</label>
            <select id="estimated_duration_seconds" name="estimated_duration_seconds" class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('estimated_duration_seconds') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                <option value="">Pilih Estimasi...</option>
                <option value="25" {{ (string) old('estimated_duration_seconds') === '25' ? 'selected' : '' }}>Pendek (&lt;30 detik)</option>
                <option value="35" {{ (string) old('estimated_duration_seconds') === '35' ? 'selected' : '' }}>Sedang (31-40 detik)</option>
                <option value="60" {{ (string) old('estimated_duration_seconds') === '60' ? 'selected' : '' }}>Panjang (41 detik ke atas)</option>
            </select>
            @error('estimated_duration_seconds') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
            <p class="text-[11px] text-[var(--text-muted)] mt-1">Dipakai buat hitung skor Delay Risk.</p>
        </div>
        <div x-show="contentTypeName === 'Desain'" x-cloak>
            <label for="estimated_slide_count" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Estimasi Jumlah Slide</label>
            <select id="estimated_slide_count" name="estimated_slide_count" class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('estimated_slide_count') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                <option value="">Pilih Estimasi...</option>
                <option value="1" {{ (string) old('estimated_slide_count') === '1' ? 'selected' : '' }}>Single (1 slide)</option>
                <option value="3" {{ (string) old('estimated_slide_count') === '3' ? 'selected' : '' }}>Beberapa (2-4 slide)</option>
                <option value="6" {{ (string) old('estimated_slide_count') === '6' ? 'selected' : '' }}>Banyak (5+ slide)</option>
            </select>
            @error('estimated_slide_count') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
            <p class="text-[11px] text-[var(--text-muted)] mt-1">Dipakai buat hitung skor Delay Risk.</p>
        </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined text-[17px]">save</span> Simpan Item
            </button>
            <a href="{{ route('content-plan.show', $contentPlan) }}" class="btn-secondary">Batal</a>
        </div>
    </form>
</div>
@endsection