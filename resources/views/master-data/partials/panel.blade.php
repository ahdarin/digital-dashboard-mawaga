@php
    $mdTabs = [
        'content-pillar' => 'Content Pillar',
        'content-type' => 'Content Type',
        'platform' => 'Platform',
        'client-category' => 'Client Category',
    ];
@endphp
<div x-data="{ showAdd: false }">
    <div class="flex items-center gap-1 bg-[#f2f3f6] rounded-lg p-1 mb-5 max-w-full overflow-x-auto thin-autohide-scrollbar">
        @foreach ($mdTabs as $key => $label)
            <a href="{{ route('settings', ['tab' => 'data-pilihan', 'type' => $key]) }}"
               class="text-sm font-medium px-4 py-2 rounded-md transition-colors shrink-0 whitespace-nowrap {{ $mdTab === $key ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-5">
        <form method="GET" class="flex-1">
            <input type="hidden" name="tab" value="data-pilihan">
            <input type="hidden" name="type" value="{{ $mdTab }}">
            <div class="relative max-w-sm">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[19px]">search</span>
                <input type="text" name="search" value="{{ $mdSearch }}" placeholder="Cari data master..."
                       class="w-full pl-10 pr-4 py-2.5 text-sm border border-[#eef0f4] rounded-lg focus:outline-none focus:border-[#044b46]/40">
            </div>
        </form>

        <button type="button" x-on:click="showAdd = !showAdd" class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
            <span class="material-symbols-outlined text-[17px]">add</span> Tambah {{ $mdTabs[$mdTab] }}
        </button>
    </div>

    <div x-show="showAdd" x-cloak class="card p-5 mb-5">
        <form action="{{ route('master-data.store', $mdTab) }}" method="POST" class="flex flex-col sm:flex-row sm:items-center gap-3">
            @csrf
            <input type="text" name="name" required placeholder="Nama {{ $mdTabs[$mdTab] }} baru..."
                   class="flex-1 border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:border-[#044b46]/40">
            <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">Simpan</button>
        </form>
    </div>

    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama</th>
                    <th class="px-6 py-3 font-medium text-right whitespace-nowrap">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($mdItems as $item)
                    <tr class="border-t border-[#f2f3f6]">
                        <td class="px-6 py-3.5 font-medium text-[#14181a] whitespace-nowrap">{{ $item->name }}</td>
                        <td class="px-6 py-3.5 text-right">
                            <form action="{{ route('master-data.destroy', [$mdTab, $item->id]) }}" method="POST" class="inline"
                                  onsubmit="return appConfirm(this, 'Yakin hapus {{ addslashes($item->name) }}?', { danger: true })">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[#9aa0a4] hover:text-[#b3423e]">
                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-6 py-12 text-center">
                            <span class="material-symbols-outlined text-[#d4d7db] text-[26px] mb-2 block">database</span>
                            <p class="text-sm text-[#9aa0a4]">Belum ada {{ $mdTabs[$mdTab] }}.</p>
                            <p class="text-xs text-[#c3c7cb] mt-1">Klik "Tambah {{ $mdTabs[$mdTab] }}" di atas buat mulai.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>
</div>
