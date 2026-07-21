<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientOnboardingController extends Controller
{
    public function index()
    {
        $this->authorizeManage();

        $clients = Client::with('category')->latest()->get();

        return view('client-onboarding.index', compact('clients'));
    }

    public function create()
    {
        $this->authorizeManage();

        $categories = ClientCategory::all();

        return view('client-onboarding.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand_name' => 'required|string|max:255',
            'client_category_id' => 'required|exists:client_categories,id',
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|email|unique:users,email',
            'owner_phone' => 'required|string',
        ]);

        DB::transaction(function () use ($validated) {
            $client = Client::create([
                'name' => $validated['name'],
                'brand_name' => $validated['brand_name'],
                'client_category_id' => $validated['client_category_id'],
                'status' => 'active',
            ]);

            $ownerRole = Role::firstOrCreate(['name' => 'Client Owner']);

            User::create([
                'role_id' => $ownerRole->id,
                'client_id' => $client->id,
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'phone_number' => PhoneNumberNormalizer::normalize($validated['owner_phone']),
                'status' => 'invited',
            ]);
        });

        return redirect()->route('client-onboarding.index')
            ->with('status', 'Client & akun Owner berhasil dibuat. Owner bisa login via WhatsApp menggunakan nomor yang didaftarkan.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can('client', 'manage'), 403);
    }
}