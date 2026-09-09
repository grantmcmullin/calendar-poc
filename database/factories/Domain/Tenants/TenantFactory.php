<?php

namespace Database\Factories\Domain\Tenants;

use App\Domain\Tenants\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->companyEmail(),
            'timezone' => 'America/New_York',
        ];
    }
}
