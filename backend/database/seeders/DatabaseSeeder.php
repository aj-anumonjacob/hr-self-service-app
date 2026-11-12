<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\WeeklyOffRule;
use App\Models\Shift;
use App\Models\WorkLocation;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure core system roles exist
        $roles = [
            'super_admin' => 'Super Administrator',
            'owner' => 'Company Owner',
            'hr_admin' => 'HR Administrator',
            'manager' => 'Reporting Manager',
            'employee' => 'Employee'
        ];

        foreach ($roles as $slug => $name) {
            if (\Schema::hasTable('roles')) {
                Role::firstOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'description' => "$name role."
                ]);
            }
        }

        // Initialize default break policies for existing companies in Main System
        if (\Schema::hasTable('companies')) {
            $breakPolicyService = new \App\Services\BreakPolicyService();
            $companies = Company::all();
            foreach ($companies as $company) {
                try {
                    $breakPolicyService->getOrCreateCompanyDefaultPolicy($company->id);
                    $breakPolicyService->getOrCreateCompanyBreakSettings($company->id);
                } catch (\Throwable $e) {}
            }
        }
    }
}
