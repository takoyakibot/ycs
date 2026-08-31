<?php

namespace Database\Factories;

use App\Models\Song;
use App\Models\SongTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SongTagFactory extends Factory
{
    protected $model = SongTag::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'song_id' => Song::factory(),
            'value' => $this->faker->name(),
        ];
    }
}
