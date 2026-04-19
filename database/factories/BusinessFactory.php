<?php
namespace Database\Factories;
use App\Models\Business;
use App\Models\City;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessFactory extends Factory {
    protected $model = Business::class;
    public function definition(): array {
        return [
            'name' => $this->faker->company(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'address' => $this->faker->address(),
            'website' => $this->faker->url(),
            'city_id' => City::factory(),
            'category_id' => Category::factory(),
            'source' => 'local.ch',
        ];
    }

    public function noEmail(): static {
        return $this->state(['email' => null]);
    }
}
