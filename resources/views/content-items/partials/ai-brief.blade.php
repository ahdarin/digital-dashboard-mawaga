@php
    $contentBrief = $contentItem->contentBriefDraft;
@endphp

@if (! $contentBrief)
    {{-- Belum ada brief - CTA menonjol supaya fitur AI ini kelihatan penting --}}
    <div class="card p-6 border-2 border-[#044b46]/20 bg-gradient-to-br from-[#f0f5f4] to-white">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 rounded-xl bg-[#044b46] text-white flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[22px]">auto_awesome</span>
                </div>
                <div>
                    <p class="text-[10px] font-semibold text-[#044b46] uppercase tracking-wide mb-1">AI Brief Execution Assistant</p>
                    <h3 class="text-sm font-semibold text-[#14181a] mb-1">Brief produksi belum dibuat</h3>
                    <p class="text-xs text-[#5c6266] max-w-md">Ubah ide mentah di bawah jadi brief produksi siap pakai — lengkap dengan naskah, talent, properti, dan estimasi kompleksitas teknis buat tim produksi.</p>
                </div>
            </div>
            <form action="{{ route('content-brief.generate', $contentItem) }}" method="POST">
                @csrf
                <button class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-semibold px-5 py-3 rounded-xl hover:bg-[#033b37] transition-colors shadow-sm whitespace-nowrap">
                    <span class="material-symbols-outlined text-[18px]">auto_awesome</span> Buat Brief dengan AI
                </button>
            </form>
        </div>
    </div>
@else
    @php
        $scenesForDisplay = $contentBrief->scenes_for_display;
        $isVideo = ($contentItem->contentType->name ?? '') === 'Video';
        $secondFieldLabel = $isVideo ? 'Script Talent' : 'Isi Design / Copy';
        $secondFieldIcon = $isVideo ? 'record_voice_over' : 'text_fields';
    @endphp
    <div class="card p-6 border border-[#044b46]/15" x-data="{ editing: false }">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[#044b46] text-[16px]">auto_awesome</span>
                <p class="text-[10px] font-semibold text-[#044b46] uppercase tracking-wide">AI Brief Execution Assistant</p>
                <span class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full
                    {{ $isVideo ? 'bg-[#eef2fb] text-[#3452a8]' : 'bg-[#fdf6ec] text-[#8a6423]' }}">
                    <span class="material-symbols-outlined text-[12px]">{{ $isVideo ? 'videocam' : 'palette' }}</span>
                    {{ $contentItem->contentType->name ?? '-' }}
                </span>
            </div>

            <div class="flex items-center justify-between gap-2 h-8 w-full">
                <span class="flex items-center h-8 text-xs font-medium px-3 rounded-full
                    {{ $contentBrief->status === 'finalized' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                    {{ $contentBrief->status === 'discussing' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}
                    {{ $contentBrief->status === 'draft' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}">
                    {{ match($contentBrief->status) { 'finalized' => 'Diterapkan', 'discussing' => 'Sedang Didiskusikan', default => 'Draft' } }}
                </span>

                @if (! $contentBrief->isLocked())
                    <div class="flex items-center h-8 gap-2">
                        <button type="button" @click="editing = !editing"
                            class="inline-flex items-center h-8 gap-1.5 border border-[#044b46]/30 text-[#044b46] text-xs font-semibold px-3 rounded-lg hover:bg-[#f0f5f4] transition-colors leading-none">
                            <span class="material-symbols-outlined text-[15px]" x-text="editing ? 'close' : 'edit'"></span>
                            <span x-text="editing ? 'Cancel' : 'Edit'"></span>
                        </button>

                        <form action="{{ route('content-brief.regenerate', $contentBrief) }}" method="POST" class="inline-flex h-8 m-0"
                            onsubmit="return confirm('Susun ulang brief dari awal? Isi brief saat ini akan tertimpa (masih bisa di-revert 1x kalau berubah pikiran).');">
                            @csrf
                            <button class="inline-flex items-center h-8 gap-1.5 border border-[#044b46]/30 text-[#044b46] text-xs font-semibold px-3 rounded-lg hover:bg-[#f0f5f4] transition-colors leading-none">
                                <span class="material-symbols-outlined text-[15px]">refresh</span> Regenerate
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        @if (! $contentBrief->isLocked())
            <div x-show="editing" x-cloak x-transition class="mb-5 -mx-6 -mt-1 px-6 pt-5 pb-6 bg-[#fafbfc] border-y border-[#eef0f4]">
                <form action="{{ route('content-brief.update', $contentBrief) }}" method="POST" class="space-y-5">
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">Hook / Judul Brief</label>
                            <input type="text" name="hook_title" value="{{ $contentBrief->hook_title }}" required
                                class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">Talent</label>
                            <input type="text" name="talent" value="{{ $contentBrief->talent }}"
                                class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">Properti</label>
                            <input type="text" name="properti" value="{{ $contentBrief->properti }}"
                                class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">Platform</label>
                            <input type="text" name="platform" value="{{ $contentBrief->platform }}"
                                class="w-full border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                        </div>
                    </div>

                    <div x-data="{
                        scenes: @js(count($scenesForDisplay) ? $scenesForDisplay : [['label' => null, 'visual' => '', 'talent_script' => '']])
                    }">
                        <div class="flex items-center justify-between mb-2.5">
                            <label class="block text-[10px] font-semibold text-[#9aa0a4] uppercase">Adegan / Slide</label>
                            <button type="button" @click="scenes.push({ label: 'ADEGAN ' + (scenes.length + 1), visual: '', talent_script: '' })"
                                class="flex items-center gap-1 text-xs font-medium text-[#044b46] hover:underline">
                                <span class="material-symbols-outlined text-[14px]">add</span> Tambah Adegan/Slide
                            </button>
                        </div>

                        <div class="space-y-3">
                            <template x-for="(scene, index) in scenes" :key="index">
                                <div class="border border-[#eef0f4] rounded-xl bg-white overflow-hidden">
                                    <div class="flex items-center justify-between gap-2 bg-[#f7f8fc] border-b border-[#eef0f4] px-3.5 py-2">
                                        <input type="text" :name="'scenes[' + index + '][label]'" x-model="scene.label"
                                            placeholder="Contoh: ADEGAN 1 / SLIDE 1"
                                            class="flex-1 text-xs font-semibold text-[#14181a] bg-transparent border-0 focus:outline-none focus:ring-0 p-0 uppercase placeholder:text-[#9aa0a4] placeholder:normal-case">
                                        <button type="button" @click="scenes.splice(index, 1)"
                                            class="text-[#b3423e] hover:opacity-70 shrink-0">
                                            <span class="material-symbols-outlined text-[16px]">delete</span>
                                        </button>
                                    </div>

                                    <div class="divide-y divide-[#eef0f4]">
                                        <div class="p-3.5">
                                            <label class="flex items-center gap-1.5 text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">
                                                <span class="material-symbols-outlined text-[13px]">visibility</span> Visual
                                            </label>
                                            <textarea :name="'scenes[' + index + '][visual]'" x-model="scene.visual" rows="2"
                                                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs bg-[#fafbfc] focus:outline-none focus:border-[#044b46]/40 focus:bg-white"></textarea>
                                        </div>
                                        <div class="p-3.5">
                                            <label class="flex items-center gap-1.5 text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">
                                                <span class="material-symbols-outlined text-[13px]">{{ $secondFieldIcon }}</span> {{ $secondFieldLabel }}
                                            </label>
                                            <textarea :name="'scenes[' + index + '][talent_script]'" x-model="scene.talent_script" rows="2"
                                                class="w-full border border-[#eef0f4] rounded-lg px-3 py-2 text-xs bg-[#fafbfc] focus:outline-none focus:border-[#044b46]/40 focus:bg-white"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-1">
                        <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                            Simpan Perubahan
                        </button>
                        <button type="button" @click="editing = false" class="text-sm font-medium text-[#9aa0a4] px-4 py-2.5 hover:text-[#14181a] transition-colors">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div x-show="! editing">

        <h3 class="font-display text-lg font-semibold text-[#14181a] mb-4">{{ $contentBrief->hook_title }}</h3>

        {{-- Jadwal & platform - ringkas, 3 kolom sejajar --}}
        <div class="grid grid-cols-3 gap-4 pb-4 mb-4 border-b border-[#f2f3f6]">
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[#9aa0a4] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">event</span> Start
                </p>
                <p class="text-sm text-[#14181a] font-medium">{{ $contentBrief->start_date?->format('d M Y') ?? '-' }}</p>
            </div>
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[#9aa0a4] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">event_available</span> Post
                </p>
                <p class="text-sm text-[#14181a] font-medium">{{ $contentBrief->post_date?->format('d M Y') ?? '-' }}</p>
            </div>
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[#9aa0a4] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">share</span> Platform
                </p>
                <p class="text-sm text-[#14181a] font-medium">{{ $contentBrief->platform ?? '-' }}</p>
            </div>
        </div>

        {{-- Naskah/script - per adegan/slide, visual & {{ $secondFieldLabel }} disusun atas-bawah --}}
        <div class="mb-5">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-[#14181a] mb-2">
                <span class="material-symbols-outlined text-[15px] text-[#044b46]">description</span> Naskah / Script
            </p>

            @if (count($scenesForDisplay))
                <div class="space-y-3">
                    @foreach ($scenesForDisplay as $i => $scene)
                        <div class="border border-[#eef0f4] rounded-xl overflow-hidden">
                            <div class="flex items-center gap-2 bg-[#f7f8fc] border-b border-[#eef0f4] px-4 py-2">
                                <span class="w-5 h-5 rounded-full bg-[#044b46] text-white text-[10px] font-bold flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                                <p class="text-xs font-semibold text-[#14181a] uppercase tracking-wide">
                                    {{ $scene['label'] ?: 'Adegan/Slide '.($i + 1) }}
                                </p>
                            </div>

                            <div class="divide-y divide-[#eef0f4]">
                                <div class="px-4 py-3">
                                    <p class="flex items-center gap-1.5 text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">
                                        <span class="material-symbols-outlined text-[13px]">visibility</span> Visual
                                    </p>
                                    <p class="text-sm text-[#14181a] whitespace-pre-line">{{ $scene['visual'] ?: '-' }}</p>
                                </div>
                                <div class="px-4 py-3">
                                    <p class="flex items-center gap-1.5 text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1.5">
                                        <span class="material-symbols-outlined text-[13px]">{{ $secondFieldIcon }}</span> {{ $secondFieldLabel }}
                                    </p>
                                    <p class="text-sm text-[#14181a] whitespace-pre-line">{{ $scene['talent_script'] ?: '-' }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="ai-markdown text-sm text-[#5c6266] bg-[#f7f8fc] border border-[#eef0f4] rounded-lg p-4">
                    <p>-</p>
                </div>
            @endif
        </div>

        {{-- Kebutuhan produksi --}}
        <div class="grid grid-cols-2 gap-4 pb-5 mb-5 border-b border-[#f2f3f6]">
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[#9aa0a4] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">groups</span> Talent
                </p>
                <p class="text-sm text-[#14181a]">{{ $contentBrief->talent ?? '-' }}</p>
            </div>
            <div>
                <p class="flex items-center gap-1 text-[10px] text-[#9aa0a4] uppercase font-semibold mb-1">
                    <span class="material-symbols-outlined text-[13px]">inventory_2</span> Properti
                </p>
                <p class="text-sm text-[#14181a]">{{ $contentBrief->properti ?? '-' }}</p>
            </div>
        </div>

        {{-- Fitur teknis - output AI ini, input untuk AI Delay Risk PIC 2 --}}
        <div class="card p-5 bg-[#eef2fb] border-0 mb-5">
            <div class="flex items-center gap-2 mb-3">
                <span class="material-symbols-outlined text-[#3452a8] text-[17px]">insights</span>
                <h3 class="text-sm font-semibold text-[#14181a]">Estimasi Kompleksitas Teknis</h3>
            </div>
            <p class="text-xs text-[#5c6266] mb-4">Dipakai modul Delay Risk Insight untuk menilai risiko keterlambatan.</p>

            <div class="grid grid-cols-2 gap-4 text-sm">
                @if ($contentBrief->estimated_duration_seconds)
                    <div><span class="text-[#9aa0a4]">Durasi:</span> {{ $contentBrief->estimated_duration_seconds }} detik</div>
                @endif
                @if ($contentBrief->slide_count)
                    <div><span class="text-[#9aa0a4]">Jumlah slide:</span> {{ $contentBrief->slide_count }}</div>
                @endif
                <div><span class="text-[#9aa0a4]">Talent:</span> {{ $contentBrief->talent_count ?? 0 }} orang</div>
                <div><span class="text-[#9aa0a4]">Lokasi:</span> {{ $contentBrief->location_count ?? 0 }} lokasi</div>
            </div>

            <div class="mt-3">
                <span class="text-xs font-medium px-2.5 py-1 rounded-full
                    {{ $contentBrief->complexity_level === 'complex' ? 'bg-[#fdf2f1] text-[#b3423e]' : '' }}
                    {{ $contentBrief->complexity_level === 'medium' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}
                    {{ $contentBrief->complexity_level === 'simple' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}">
                    Kompleksitas: {{ ucfirst($contentBrief->complexity_level ?? '-') }}
                </span>
            </div>
        </div>

        </div>{{-- /x-show="! editing" --}}

        @if (! $contentBrief->isLocked())
            <form action="{{ route('content-brief.finalize', $contentBrief) }}" method="POST" class="mb-3">
                @csrf
                <button class="w-full bg-[#044b46] text-white text-sm font-medium py-3 rounded-lg hover:bg-[#033b37] transition-colors">
                    Terapkan Brief ke Tim Produksi
                </button>
            </form>

            @if ($contentBrief->canRevert())
                <form action="{{ route('content-brief.revert', $contentBrief) }}" method="POST">
                    @csrf
                    <button class="w-full border border-[#e4c98f] text-[#8a6423] text-sm font-medium py-2.5 rounded-lg hover:bg-[#fdf6ec] transition-colors flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">undo</span> Kembalikan ke Versi Sebelumnya
                    </button>
                </form>
            @endif
        @else
            <form action="{{ route('content-brief.withdraw', $contentBrief) }}" method="POST">
                @csrf
                <button class="w-full bg-[#f2f3f6] text-[#5c6266] text-sm font-medium py-3 rounded-lg hover:bg-[#eef0f4] transition-colors">
                    Tarik Kembali untuk Direvisi
                </button>
            </form>
        @endif
    </div>

    <style>
        .ai-markdown p { margin-bottom: 0.6rem; }
        .ai-markdown p:last-child { margin-bottom: 0; }
        .ai-markdown strong { color: #044b46; font-weight: 700; }
        .ai-markdown em { color: #5c6266; font-style: normal; font-weight: 600; }
        .ai-markdown ul, .ai-markdown ol { padding-left: 1.1rem; margin-bottom: 0.6rem; }
        .ai-markdown ul { list-style: disc; }
        .ai-markdown ol { list-style: decimal; }
        .ai-markdown li { margin-bottom: 0.15rem; }
        .ai-markdown h1, .ai-markdown h2, .ai-markdown h3 {
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: #044b46;
            margin-top: 0.85rem;
            margin-bottom: 0.3rem;
        }
        .ai-markdown h1:first-child, .ai-markdown h2:first-child, .ai-markdown h3:first-child { margin-top: 0; }

        /* Heading "SLIDE N"/"ADEGAN N" - satu-satunya isi paragraf -
           dijadikan chip section marker biar keliatan sebagai pembatas
           bagian, bukan cuma teks bold nyempil di tengah kalimat. */
        .ai-markdown p > strong:only-child {
            display: inline-block;
            background: #044b46;
            color: #fff;
            font-size: 0.65rem;
            letter-spacing: 0.05em;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            margin-top: 0.5rem;
        }
        .ai-markdown p:first-child > strong:only-child { margin-top: 0; }
    </style>
@endif