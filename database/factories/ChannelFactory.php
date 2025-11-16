<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'handle' => '@'.$this->faker->unique()->userName(),
            'channel_id' => 'UC'.fake()->regexify('[A-Za-z0-9_-]{20}'),
            'title' => $this->faker->company().' Channel',
            'thumbnail' => $this->faker->imageUrl(200, 200, 'people', true),
            'user_id' => \App\Models\User::factory(),
        ];
    }
}
