{{-- Tombol + modal "Jobdesk Tambahan" - permintaan mendadak dari client
     (dokumentasi event, liputan kelas, dsb) yang butuh langsung masuk
     produksi tanpa lewat alur perencanaan bulanan biasa. Self-contained
     (x-data sendiri) supaya bisa ditaruh di halaman manapun tanpa perlu
     nyambung ke Alpine scope milik halaman itu.

     Variabel yang dibutuhkan dari controller: $clientOptions,
     $contentTypeOptions, $platformOptions, $picOptions.
     Opsional: $urgentPreselectClientId (kunci pilihan client, dipakai di
     halaman Content Plan per-client).
     Opsional: $urgentTriggerStyle = 'sidebar' buat gaya tombol nav sidebar
     (dipakai components/sidebar.blade.php) - default-nya tombol CTA merah
     solid seperti semula. --}}
@if (auth()->user()->hasPermissionTo('content_plan', 'create'))
    <div x-data="{ urgentOpen: {{ $errors->any() ? 'true' : 'false' }} }">
        @if (($urgentTriggerStyle ?? null) === 'sidebar')
            <button type="button" @click="urgentOpen = true"
                    aria-label="Jobdesk Tambahan"
                    x-data="{ label: 'Jobdesk Tambahan' }"
                    @mouseenter="{{ $tooltipEnter ?? '' }}"
                    @mouseleave="tooltip.show = false"
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-[13.5px] font-medium text-[var(--danger-text)] hover:bg-[var(--danger-tint)] transition-colors duration-100"
                    :class="effectiveCollapsed && 'justify-center px-0'">
                <span class="material-symbols-outlined text-[19px] shrink-0">bolt</span>
                <span class="whitespace-nowrap" x-show="!effectiveCollapsed" x-cloak x-transition.opacity>Jobdesk Tambahan</span>
            </button>
        @else
            <button type="button" @click="urgentOpen = true"
                    class="flex items-center gap-2 bg-[var(--danger-solid)] text-white text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[var(--danger-dark)] transition-colors whitespace-nowrap">
                <span class="material-symbols-outlined text-[17px]">bolt</span> Jobdesk Tambahan
            </button>
        @endif

        {{-- x-teleport ke body - kalau tombolnya ada di sidebar, aside punya
             class transform (translate-x-*) buat animasi buka/tutup di
             mobile, dan itu bikin position:fixed di dalamnya jadi ke-anchor
             ke aside (bukan viewport). Teleport ke body ngindarin itu,
             sama kayak pola tooltip sidebar. --}}
        <template x-teleport="body">
        <div x-show="urgentOpen" x-cloak x-on:keydown.escape.window="urgentOpen = false" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="urgentOpen = false"></div>
            <div x-show="urgentOpen" x-transition role="dialog" aria-modal="true" aria-labelledby="urgent-content-modal-title" x-trap="urgentOpen" class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <form action="{{ route('content-items.quick-urgent') }}" method="POST">
                    @csrf
                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                        <div>
                            <h3 id="urgent-content-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Jobdesk Tambahan</h3>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">Permintaan mendadak dari klien yang langsung dikerjakan tanpa lewat rencana bulanan.</p>
                        </div>
                        <button type="button" @click="urgentOpen = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                            <span class="material-symbols-outlined text-[19px]">close</span>
                        </button>
                    </div>

                    <div class="px-6 py-5 space-y-4">
                        <div>
                            <label for="urgent_client_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Klien <span class="text-[var(--danger-text)]">*</span></label>
                            <select id="urgent_client_id" name="client_id" required {{ isset($urgentPreselectClientId) ? 'disabled' : '' }}
                                class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 disabled:bg-[var(--surface-page)] disabled:text-[var(--text-secondary)] {{ $errors->has('client_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                                @unless (isset($urgentPreselectClientId))
                                    <option value="">Pilih klien...</option>
                                @endunless
                                @foreach ($clientOptions as $client)
                                    <option value="{{ $client->id }}"
                                        {{ isset($urgentPreselectClientId)
                                            ? ((string) $urgentPreselectClientId === (string) $client->id ? 'selected' : '')
                                            : ((string) old('client_id') === (string) $client->id ? 'selected' : '') }}>
                                        {{ $client->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if (isset($urgentPreselectClientId))
                                <input type="hidden" name="client_id" value="{{ $urgentPreselectClientId }}">
                            @endif
                            @error('client_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="urgent_title" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Judul Konten <span class="text-[var(--danger-text)]">*</span></label>
                            <input id="urgent_title" type="text" name="title" required value="{{ old('title') }}" placeholder="Contoh: Dokumentasi Event Grand Opening"
                                class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('title') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                            @error('title') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="urgent_content_type_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Tipe Konten</label>
                                <select id="urgent_content_type_id" name="content_type_id" class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('content_type_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                                    <option value="">-</option>
                                    @foreach ($contentTypeOptions as $type)
                                        <option value="{{ $type->id }}" {{ (string) old('content_type_id') === (string) $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                                    @endforeach
                                </select>
                                @error('content_type_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="urgent_platform_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Platform</label>
                                <select id="urgent_platform_id" name="platform_id" class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('platform_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                                    <option value="">-</option>
                                    @foreach ($platformOptions as $platform)
                                        <option value="{{ $platform->id }}" {{ (string) old('platform_id') === (string) $platform->id ? 'selected' : '' }}>{{ $platform->name }}</option>
                                    @endforeach
                                </select>
                                @error('platform_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label for="urgent_deadline_at" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Deadline <span class="text-[var(--danger-text)]">*</span></label>
                            <input id="urgent_deadline_at" type="text" name="deadline_at" required value="{{ old('deadline_at', now()->addDay()->format('Y-m-d H:i')) }}" data-flatpickr="datetime" autocomplete="off"
                                class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('deadline_at') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                            @error('deadline_at') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="urgent_pic_id" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Penanggung Jawab</label>
                            <select id="urgent_pic_id" name="pic_id" class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('pic_id') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">
                                <option value="">Belum ditentukan</option>
                                @foreach ($picOptions as $pic)
                                    <option value="{{ $pic->id }}" {{ (string) old('pic_id') === (string) $pic->id ? 'selected' : '' }}>{{ $pic->name }}</option>
                                @endforeach
                            </select>
                            @error('pic_id') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="urgent_brief" class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Catatan / Brief Singkat</label>
                            <textarea id="urgent_brief" name="brief" rows="3" placeholder="Detail permintaan klien..."
                                class="w-full border rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 {{ $errors->has('brief') ? 'border-[var(--field-error-border)]' : 'border-[var(--border)]' }}">{{ old('brief') }}</textarea>
                            @error('brief') <p class="text-xs text-[var(--danger-text)] mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="submit" class="flex items-center gap-2 bg-[var(--danger-solid)] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[var(--danger-dark)] transition-colors">
                            <span class="material-symbols-outlined text-[17px]">save</span> Simpan Jobdesk Tambahan
                        </button>
                        <button type="button" @click="urgentOpen = false" class="btn-secondary">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
        </template>
    </div>
@endif
