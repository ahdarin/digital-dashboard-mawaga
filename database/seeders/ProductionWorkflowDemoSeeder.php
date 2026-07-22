<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Platform;
use App\Models\User;
use App\Models\ContentItemAssignment;
use Illuminate\Database\Seeder;
use App\Models\UserClientAssignment;

class ProductionWorkflowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::firstOrCreate(
            ['name' => 'TechNova Inc.'],
            ['client_category_id' => $category->id, 'brand_name' => 'TechNova', 'status' => 'active']
        );

        $type = ContentType::firstOrCreate(['name' => 'Design']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $user = User::where('email', 'ahdaalamin2506@gmail.com')->first();

        // Wajib ada dulu karena content_items butuh client_package_id via content_plans
        $clientPackage = ClientPackage::firstOrCreate(
            ['client_id' => $client->id],
            [
                'package_name_snapshot' => 'Paket Demo',
                'monthly_content_quota' => 20,
                'monthly_design_quota' => 20,
                'start_date' => now()->startOfMonth(),
                'status' => 'active',
            ]
        );

        $contentPlan = ContentPlan::firstOrCreate(
            [
                'client_id' => $client->id,
                'month' => now()->month,
                'year' => now()->year,
            ],
            [
                'client_package_id' => $clientPackage->id,
                'created_by' => $user->id,
                'status' => 'approved',
            ]
        );

        $statuses = ['brief_ready', 'brief_ready', 'in_progress', 'waiting_review', 'approved'];

        foreach ($statuses as $i => $status) {
            $item = ContentItem::create([
                'content_plan_id' => $contentPlan->id,
                'client_id' => $client->id,
                'content_type_id' => $type->id,
                'platform_id' => $platform->id,
                'title' => "Demo Content Item #" . ($i + 1),
                'brief' => 'Contoh brief untuk testing board.',
                'deadline_at' => now()->subDay()->addDays($i),
            ]);

            ContentWorkflow::create([
                'content_item_id' => $item->id,
                'current_pic_id' => $user->id,
                'current_status' => $status,
            ]);

            ContentItemAssignment::create([
                'content_item_id' => $item->id,
                'user_id' => $user->id,
                'assignment_role' => 'content_creator',
            ]);

            if ($i === 0) {
                ContentItemAssignment::create([
                    'content_item_id' => $item->id,
                    'user_id' => $user->id,
                    'assignment_role' => 'designer',
                ]);
            }
        }

        UserClientAssignment::firstOrCreate([
            'user_id' => $user->id,
            'client_id' => $client->id,
        ]);
    }
}