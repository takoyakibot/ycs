<?php

namespace Database\Factories;

use App\Models\TsItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TsItem>
 */
class TsItemFactory extends Factory
{
    protected $model = TsItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seconds = $this->faker->numberBetween(0, 3600);
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;

        return [
            'id' => Str::uuid()->toString(),
            'video_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'type' => '1', // 1: description, 2: comments
            'ts_text' => sprintf('%d:%02d', $minutes, $secs),
            'ts_num' => $seconds,
            'text' => $this->faker->words(3, true),
            'comment_id' => null,
            'is_display' => true,
        ];
    }

    /**
     * Indicate that the timestamp is from comments.
     */
    public function fromComments(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => '2',
            'comment_id' => fake()->regexify('[A-Za-z0-9_-]{26}'),
        ]);
    }
}
