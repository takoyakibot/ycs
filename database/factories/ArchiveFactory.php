<?php

namespace Database\Factories;

use App\Models\Archive;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Archive>
 */
class ArchiveFactory extends Factory
{
    protected $model = Archive::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'channel_id' => Channel::factory(),
            'video_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'title' => $this->faker->sentence(5),
            'thumbnail' => $this->faker->imageUrl(640, 480, 'video', true),
            'is_public' => true,
            'is_display' => true,
            'published_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'comments_updated_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
