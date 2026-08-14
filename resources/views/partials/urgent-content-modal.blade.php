{{-- Tombol + modal "Jobdesk Tambahan" - permintaan mendadak dari client
     (dokumentasi event, liputan kelas, dsb) yang butuh langsung masuk
     produksi tanpa lewat alur perencanaan bulanan biasa. Self-contained
     (x-data sendiri) supaya bisa ditaruh di halaman manapun tanpa perlu
     nyambung ke Alpine scope milik halaman itu.

     Variabel yang dibutuhkan dari controller: $clientOptions,
     $contentTypeOptions, $platformOptions, $picOptions.
     Opsional: $urgentPreselectClientId (kunci pilihan client, dipakai di
     halaman Content Plan per-client). --}}
@if (auth()->user()->hasPermissionTo('content_plan', 'create'))
    <div x-data="{ urgentOpen: false }">
        <button type="button" @click="urgentOpen = true"
                class="flex items-center gap-2 bg-[#b3423e] text-white text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#96352f] transition-colors whitespace-nowrap">
            <span class="material-symbols-outlined text-[17px]">bolt</span> Jobdesk Tambahan
        </button>

        <div x-show="urgentOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="urgentOpen = false"></div>
            <div x-show="urgentOpen" x-transition class="relative bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <form action="{{ route('content-items.quick-urgent') }}" method="POST">
                    @csrf
                    <div class="flex items-center justify-between px-6 py-5 border-b border-[#eef0f4]">
                        <div>
                            <h3 class="font-display text-lg font-semibold text-[#14181a]">Jobdesk Tambahan</h3>
                            <p class="text-xs text-[#9aa0a4] mt-0.5">Permintaan mendadak dari client - langsung masuk Production Workflow.</p>
                        </div>
                        <button type="button" @click="urgentOpen = false" class="text-[#9aa0a4] hover:text-[#5c6266]">
                            <span class="material-symbols-outlined text-[19px]">close</span>
                        </button>
                    </div>

                    <div class="px-6 py-5 space-y-3.5">
                        <div>
                            <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Client <span class="text-[#b3423e]">*</span></label>
                            <select name="client_id" required {{ isset($urgentPreselectClientId) ? 'disabled' : '' }}
                                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 disabled:bg-[#f7f8fc] disabled:text-[#5c6266]">
                                @unless (isset($urgentPreselectClientId))
                                    <option value="">Pilih client...</option>
                                @endunless
                                @foreach ($clientOptions as $client)
                                    <option value="{{ $client->id }}"
                                        {{ isset($urgentPreselectClientId) && (string) $urgentPreselectClientId === (string) $client->id ? 'selected' : '' }}>
                                        {{ $client->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if (isset($urgentPreselectClientId))
                                <input type="hidden" name="client_id" value="{{ $urgentPreselectClientId }}">
                            @endif
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Judul Konten <span class="text-[#b3423e]">*</span></label>
                            <input type="text" name="title" required placeholder="Contoh: Dokumentasi Event Grand Opening"
                                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Tipe Konten</label>
                                <select name="content_type_id" class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                                    <option value="">-</option>
                                    @foreach ($contentTypeOptions as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Platform</label>
                                <select name="platform_id" class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                                    <option value="">-</option>
                                    @foreach ($platformOptions as $platform)
                                        <option value="{{ $platform->id }}">{{ $platform->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Deadline <span class="text-[#b3423e]">*</span></label>
                            <input type="datetime-local" name="deadline_at" required value="{{ now()->addDay()->format('Y-m-d\TH:i') }}"
                                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">PIC</label>
                            <select name="pic_id" class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                                <option value="">Belum ditentukan</option>
                                @foreach ($picOptions as $pic)
                                    <option value="{{ $pic->id }}">{{ $pic->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-[#9aa0a4] uppercase mb-1">Catatan / Brief Singkat</label>
                            <textarea name="brief" rows="3" placeholder="Detail permintaan client..."
                                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40"></textarea>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[#eef0f4]">
                        <button type="submit" class="bg-[#b3423e] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#96352f] transition-colors">
                            Tambahkan ke Production Workflow
                        </button>
                        <button type="button" @click="urgentOpen = false" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
