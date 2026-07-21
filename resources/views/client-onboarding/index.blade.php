@extends('layouts.app')
@section('title', 'Client Onboarding')
@section('content')
<div class="p-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-[#191c1c]">Client Management</h2>
            <p class="text-sm text-gray-500 mt-1">Kelola data client 523 Studio</p>
        </div>
        <a href="{{ route('client-onboarding.create') }}"
           class="bg-[#044b46] text-white text-sm font-semibold px-4 py-2 rounded-lg hover:bg-[#044b46]/90">
            + Tambah Client
        </a>
    </div>

    @if (session('status'))
        <div class="bg-teal-50 text-[#044b46] text-sm p-3 rounded-lg mb-4">{{ session('status') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">Brand</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($clients as $client)
                    <tr class="border-t border-gray-100">
                        <td class="px-4 py-3 font-medium">{{ $client->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $client->brand_name }}</td>
                        <td class="px-4 py-3">{{ $client->category->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700">{{ $client->status }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection