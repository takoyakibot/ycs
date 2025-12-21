<?php

namespace Database\Factories;

use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Song>
 */
class SongFactory extends Factory
{
    protected $model = Song::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $spotifyTrackId = $this->faker->regexify('[a-zA-Z0-9]{22}');

        return [
            'id' => (string) Str::ulid(),
            'title' => $this->faker->words(3, true),
            'artist' => $this->faker->name(),
            'spotify_track_id' => $this->faker->optional(0.5)->passthrough($spotifyTrackId),
            'spotify_data' => $this->faker->optional(0.5)->passthrough([
                'album' => [
                    'name' => $this->faker->words(2, true),
                    'release_date' => $this->faker->date(),
                ],
                'duration_ms' => $this->faker->numberBetween(120000, 360000),
                'external_urls' => [
                    'spotify' => 'https://open.spotify.com/track/'.$spotifyTrackId,
                ],
            ]),
            'video_url' => null,
            'duration_ms' => null,
        ];
    }

    /**
     * Indicate that the song has a Spotify track ID.
     */
    public function withSpotify(): static
    {
        return $this->state(function (array $attributes) {
            $trackId = fake()->regexify('[a-zA-Z0-9]{22}');

            return [
                'spotify_track_id' => $trackId,
                'spotify_data' => [
                    'album' => [
                        'name' => fake()->words(2, true),
                        'release_date' => fake()->date(),
                    ],
                    'duration_ms' => fake()->numberBetween(120000, 360000),
                    'external_urls' => [
                        'spotify' => 'https://open.spotify.com/track/'.$trackId,
                    ],
                ],
            ];
        });
    }

    /**
     * Indicate that the song does not have Spotify data.
     */
    public function withoutSpotify(): static
    {
        return $this->state(fn (array $attributes) => [
            'spotify_track_id' => null,
            'spotify_data' => null,
        ]);
    }

    /**
     * Indicate that the song has a YouTube URL.
     */
    public function withYoutubeUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'video_url' => 'https://www.youtube.com/watch?v='.fake()->regexify('[a-zA-Z0-9_-]{11}'),
            'duration_ms' => fake()->numberBetween(120000, 360000),
        ]);
    }

    /**
     * Indicate that the song has a Niconico URL.
     */
    public function withNiconicoUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'video_url' => 'https://www.nicovideo.jp/watch/sm'.fake()->numerify('########'),
            'duration_ms' => fake()->numberBetween(120000, 360000),
        ]);
    }

    /**
     * Indicate that the song has a video URL with specific duration.
     */
    public function withVideoUrl(string $url, ?int $durationMs = null): static
    {
        return $this->state(fn (array $attributes) => [
            'video_url' => $url,
            'duration_ms' => $durationMs ?? fake()->numberBetween(120000, 360000),
        ]);
    }
}
