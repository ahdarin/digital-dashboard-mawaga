<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\PackageTemplate;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Menampilkan daftar client.
     */
    public function index()
    {
        $clients = Client::with([
            'category',
            'activePackage',
        ])
        ->latest()
        ->get();

        return view('clients.index', compact('clients'));
    }

    /**
     * Form tambah client.
     */
    public function create()
    {
        $categories = ClientCategory::orderBy('name')->get();

        return view('clients.create', compact('categories'));
    }

    /**
     * Simpan client baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_category_id' => [
                'required',
                'exists:client_categories,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'brand_name' => [
                'required',
                'string',
                'max:255',
            ],

            'status' => [
                'required',
                'in:active,inactive',
            ],
        ]);

        $client = Client::create($validated);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Client berhasil ditambahkan.');
    }

    /**
     * Detail client.
     */
    public function show(Client $client)
    {
        $client->load([
            'category',
            'packages' => function ($query) {
                $query->latest('start_date');
            },
            'activePackage',
        ]);

        return view('clients.show', compact('client'));
    }

    /**
     * Form edit client.
     */
    public function edit(Client $client)
    {
        $categories = ClientCategory::orderBy('name')->get();

        return view(
            'clients.edit',
            compact('client', 'categories')
        );
    }

    /**
     * Update client.
     */
    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'client_category_id' => [
                'required',
                'exists:client_categories,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'brand_name' => [
                'required',
                'string',
                'max:255',
            ],

            'status' => [
                'required',
                'in:active,inactive',
            ],
        ]);

        $client->update($validated);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Data client berhasil diperbarui.');
    }

    /**
     * Hapus client.
     */
    public function destroy(Client $client)
    {
        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client berhasil dihapus.');
    }
}