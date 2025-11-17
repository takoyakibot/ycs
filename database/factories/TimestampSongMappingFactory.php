<?php

namespace Database\Factories;

use App\Models\Song;
use App\Models\TimestampSongMapping;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TimestampSongMapping>
 */
class TimestampSongMappingFactory extends Factory
{
    protected $model = TimestampSongMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = $this->faker->words(3, true);

        return [
            'id' => (string) Str::ulid(),
            'normalized_text' => \App\Helpers\TextNormalizer::normalize($text),
            'song_id' => null,
            'is_not_song' => false,
            'is_manual' => false,
            'confidence' => null,
        ];
    }

    /**
     * Indicate that the mapping is linked to a song.
     */
    public function withSong(?Song $song = null): static
    {
        return $this->state(fn (array $attributes) => [
            'song_id' => $song ? $song->id : Song::factory(),
            'is_not_song' => false,
            'confidence' => fake()->randomFloat(2, 0.7, 1.0),
        ]);
    }

    /**
     * Indicate that the timestamp is marked as not a song.
     */
    public function notSong(): static
    {
        return $this->state(fn (array $attributes) => [
            'song_id' => null,
            'is_not_song' => true,
            'is_manual' => true,
            'confidence' => 1.0,
        ]);
    }

    /**
     * Indicate that the mapping was created manually.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => true,
            'confidence' => 1.0,
        ]);
    }

    /**
     * Indicate that the mapping was created automatically.
     */
    public function automatic(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => false,
            'confidence' => fake()->randomFloat(2, 0.7, 0.95),
        ]);
    }

    /**
     * Set a specific normalized text.
     */
    public function withText(string $text): static
    {
        return $this->state(fn (array $attributes) => [
            'normalized_text' => \App\Helpers\TextNormalizer::normalize($text),
        ]);
    }
}
