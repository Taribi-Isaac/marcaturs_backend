<?php

namespace Database\Seeders;

use App\Models\DisputeCategory;
use Illuminate\Database\Seeder;

class DisputeCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'unpaid_commission', 'name' => 'Unpaid commission', 'sort_order' => 10],
            ['code' => 'commission_amount_disputed', 'name' => 'Commission amount disputed', 'sort_order' => 20],
            ['code' => 'campaign_terms_changed', 'name' => 'Campaign terms changed', 'sort_order' => 30],
            ['code' => 'commission_withheld', 'name' => 'Commission withheld', 'sort_order' => 40],
            ['code' => 'unauthorized_claims', 'name' => 'Unauthorized claims', 'sort_order' => 50],
            ['code' => 'fraudulent_evidence', 'name' => 'Fraudulent evidence', 'sort_order' => 60],
            ['code' => 'misrepresentation', 'name' => 'Misrepresentation', 'sort_order' => 70],
            ['code' => 'customer_complaint', 'name' => 'Customer complaint', 'sort_order' => 80],
            ['code' => 'campaign_abuse', 'name' => 'Campaign abuse', 'sort_order' => 90],
            ['code' => 'other', 'name' => 'Other', 'sort_order' => 100],
        ];

        foreach ($categories as $category) {
            DisputeCategory::query()->updateOrCreate(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'description' => null,
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                ],
            );
        }
    }
}
