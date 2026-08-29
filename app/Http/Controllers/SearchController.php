<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    private int $perCategoryLimit = 5;

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $user = $request->user();

        $results = collect()
            ->concat($this->searchClients($term, $user))
            ->concat($this->searchUsers($term))
            ->concat($this->searchContentItems($term, $user));

        return response()->json($results->values());
    }

    private function searchClients(string $term, User $user): array
    {
        $query = Client::query()->with('category')->where('name', 'like', "%{$term}%");

        if (! $user->canSeeAllClients()) {
            $query->whereIn('id', $user->assignedClients()->pluck('clients.id'));
        }

        return $query->take($this->perCategoryLimit)->get()->map(fn (Client $client) => [
            'type' => 'client',
            'id' => $client->id,
            'title' => $client->name,
            'subtitle' => $client->category->name ?? null,
            'url' => route('client-management.show', $client),
        ])->all();
    }

    private function searchUsers(string $term): array
    {
        return User::query()
            ->where('status', 'active')
            ->where('name', 'like', "%{$term}%")
            ->with('roles')
            ->take($this->perCategoryLimit)
            ->get()
            ->map(fn (User $member) => [
                'type' => 'user',
                'id' => $member->id,
                'title' => $member->name,
                'subtitle' => $member->roleNamesLabel() ?: null,
                'url' => route('profile.show', $member),
            ])->all();
    }

    private function searchContentItems(string $term, User $user): array
    {
        $query = ContentItem::where('title', 'like', "%{$term}%")->with('client');

        if (! $user->canSeeAllClients()) {
            $query->whereIn('client_id', $user->assignedClients()->pluck('clients.id'));
        }

        return $query->take($this->perCategoryLimit)->get()->map(fn (ContentItem $item) => [
            'type' => 'content',
            'id' => $item->id,
            'title' => $item->title,
            'subtitle' => $item->client->name ?? null,
            'url' => route('content-items.show', $item),
        ])->all();
    }
}
