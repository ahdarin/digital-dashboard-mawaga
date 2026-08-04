@extends('layouts.app')

@section('content')

<div class="min-h-screen bg-[#f7f8f8]">

    {{-- =========================
        TOP BAR
    ========================== --}}
    <div class="border-b border-gray-200 bg-white">
        <div class="px-8 py-4 flex items-center justify-between">

            <div class="flex items-center gap-2 text-sm">
                <span class="text-gray-400">Clients</span>
                <span class="text-gray-400">›</span>
                <span class="font-medium text-gray-800">Client Management</span>
            </div>

            <div class="flex items-center gap-5">

                <button
                    type="button"
                    class="text-gray-500 hover:text-gray-800 transition">
                    <span class="material-symbols-outlined">
                        search
                    </span>
                </button>

                <div
                    class="w-8 h-8 rounded-full bg-[#0b615b] text-white flex items-center justify-center text-xs font-semibold">
                    JS
                </div>

            </div>
        </div>
    </div>


    {{-- =========================
        MAIN CONTENT
    ========================== --}}
    <main class="px-8 py-7">

        {{-- Header --}}
        <div class="flex items-start justify-between mb-7">

            <div>
                <h1 class="text-[28px] font-semibold text-gray-900">
                    Client Management
                </h1>

                <p class="mt-1 text-sm text-gray-500">
                    Manage clients, active packages, and package history.
                </p>
            </div>

            <a
                href="{{ route('clients.create') }}"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#075b55] hover:bg-[#064c47] text-white rounded-md text-sm font-medium transition">

                <span class="material-symbols-outlined text-[18px]">
                    add
                </span>

                Add Client
            </a>

        </div>


        {{-- Success Message --}}
        @if(session('success'))

            <div class="mb-6 flex items-center gap-3 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">

                <span class="material-symbols-outlined text-[19px]">
                    check_circle
                </span>

                {{ session('success') }}

            </div>

        @endif


        {{-- Validation Error --}}
        @if($errors->any())

            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3">

                <div class="flex items-center gap-2 text-sm font-medium text-red-700 mb-2">

                    <span class="material-symbols-outlined text-[18px]">
                        error
                    </span>

                    Please check the following:
                </div>

                <ul class="list-disc ml-6 text-sm text-red-600 space-y-1">

                    @foreach($errors->all() as $error)

                        <li>{{ $error }}</li>

                    @endforeach

                </ul>

            </div>

        @endif


        {{-- =========================
            CLIENT TABLE
        ========================== --}}
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">

            {{-- Table Header --}}
            <div class="px-6 py-5 border-b border-gray-200 flex items-center justify-between">

                <div>
                    <h2 class="text-[17px] font-semibold text-gray-900">
                        Clients
                    </h2>

                    <p class="text-xs text-gray-500 mt-1">
                        {{ $clients->count() }} client(s) registered
                    </p>
                </div>

            </div>


            {{-- Table --}}
            <div class="overflow-x-auto">

                <table class="w-full text-left">

                    <thead class="bg-[#fafafa] border-b border-gray-200">

                        <tr>

                            <th class="px-6 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                Client
                            </th>

                            <th class="px-6 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                Category
                            </th>

                            <th class="px-6 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                Active Package
                            </th>

                            <th class="px-6 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                Content / Design
                            </th>

                            <th class="px-6 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                Status
                            </th>

                            <th class="px-6 py-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500 text-right">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody class="divide-y divide-gray-100">

                        @forelse($clients as $client)

                            <tr class="hover:bg-[#f8faf9] transition">

                                {{-- CLIENT --}}
                                <td class="px-6 py-4">

                                    <div class="flex items-center gap-3">

                                        <div
                                            class="w-10 h-10 rounded-md bg-[#e7f3f1] text-[#075b55] flex items-center justify-center font-semibold text-sm">

                                            {{ strtoupper(substr($client->brand_name ?: $client->name, 0, 1)) }}

                                        </div>

                                        <div>

                                            <div class="font-semibold text-sm text-gray-900">
                                                {{ $client->brand_name }}
                                            </div>

                                            <div class="text-xs text-gray-500 mt-0.5">
                                                {{ $client->name }}
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                {{-- CATEGORY --}}
                                <td class="px-6 py-4">

                                    @if($client->category)

                                        <span class="text-sm text-gray-700">
                                            {{ $client->category->name }}
                                        </span>

                                    @else

                                        <span class="text-sm text-gray-400">
                                            —
                                        </span>

                                    @endif

                                </td>


                                {{-- ACTIVE PACKAGE --}}
                                <td class="px-6 py-4">

                                    @if($client->activePackage)

                                        <div class="text-sm font-medium text-gray-800">
                                            {{ $client->activePackage->package_name_snapshot }}
                                        </div>

                                        <div class="text-xs text-gray-500 mt-1">

                                            Started
                                            {{ optional($client->activePackage->start_date)->format('M d, Y') }}

                                        </div>

                                    @else

                                        <span class="text-sm text-gray-400">
                                            No active package
                                        </span>

                                    @endif

                                </td>


                                {{-- QUOTA --}}
                                <td class="px-6 py-4">

                                    @if($client->activePackage)

                                        <div class="text-sm text-gray-700">

                                            {{ $client->activePackage->monthly_content_quota }}
                                            Videos

                                            <span class="text-gray-300 mx-1">+</span>

                                            {{ $client->activePackage->monthly_design_quota }}
                                            Designs

                                        </div>

                                        <div class="text-xs text-gray-400 mt-1">
                                            / month
                                        </div>

                                    @else

                                        <span class="text-sm text-gray-400">
                                            —
                                        </span>

                                    @endif

                                </td>


                                {{-- STATUS --}}
                                <td class="px-6 py-4">

                                    @if($client->status === 'active')

                                        <span
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-50 text-green-700 text-[11px] font-semibold">

                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>

                                            Active

                                        </span>

                                    @else

                                        <span
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 text-[11px] font-semibold">

                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>

                                            Inactive

                                        </span>

                                    @endif

                                </td>


                                {{-- ACTION --}}
                                <td class="px-6 py-4">

                                    <div class="flex items-center justify-end gap-2">

                                        {{-- VIEW --}}
                                        <a
                                            href="{{ route('clients.show', $client) }}"
                                            class="w-8 h-8 flex items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-[#075b55] transition"
                                            title="View Client">

                                            <span class="material-symbols-outlined text-[18px]">
                                                visibility
                                            </span>

                                        </a>


                                        {{-- EDIT --}}
                                        <a
                                            href="{{ route('clients.edit', $client) }}"
                                            class="w-8 h-8 flex items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-[#075b55] transition"
                                            title="Edit Client">

                                            <span class="material-symbols-outlined text-[18px]">
                                                edit
                                            </span>

                                        </a>


                                        {{-- DELETE --}}
                                        <form
                                            action="{{ route('clients.destroy', $client) }}"
                                            method="POST"
                                            onsubmit="return confirm('Yakin ingin menghapus client ini?');">

                                            @csrf
                                            @method('DELETE')

                                            <button
                                                type="submit"
                                                class="w-8 h-8 flex items-center justify-center rounded-md text-gray-500 hover:bg-red-50 hover:text-red-600 transition"
                                                title="Delete Client">

                                                <span class="material-symbols-outlined text-[18px]">
                                                    delete
                                                </span>

                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td
                                    colspan="6"
                                    class="px-6 py-16 text-center">

                                    <div class="flex flex-col items-center">

                                        <div
                                            class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">

                                            <span class="material-symbols-outlined text-gray-400">
                                                group
                                            </span>

                                        </div>

                                        <h3 class="text-sm font-semibold text-gray-800">
                                            No clients yet
                                        </h3>

                                        <p class="text-xs text-gray-500 mt-1 mb-4">
                                            Add your first client to start managing their packages.
                                        </p>

                                        <a
                                            href="{{ route('clients.create') }}"
                                            class="inline-flex items-center gap-2 px-4 py-2 bg-[#075b55] text-white rounded-md text-xs font-medium hover:bg-[#064c47]">

                                            <span class="material-symbols-outlined text-[16px]">
                                                add
                                            </span>

                                            Add Client

                                        </a>

                                    </div>

                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </div>

    </main>

</div>

@endsection