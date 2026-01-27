<?php

declare(strict_types=1);

namespace Marque\Trove\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Marque\Trove\Models\Torrent;

/**
 * @extends Factory<Torrent>
 */
class TorrentFactory extends Factory
{
    protected $model = Torrent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        return [
            'info_hash' => fake()->sha1(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional(0.7)->paragraph(),
            'size' => fake()->numberBetween(100_000, 50_000_000_000),
            'file_count' => fake()->numberBetween(1, 100),
            'torrent_file' => null,
            'user_id' => $userModel::factory(),
        ];
    }

    /**
     * Indicate that the torrent is large (multi-GB).
     */
    public function large(): static
    {
        return $this->state(fn (array $attributes) => [
            'size' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
            'file_count' => fake()->numberBetween(10, 500),
        ]);
    }

    /**
     * Indicate that the torrent is small (under 100MB).
     */
    public function small(): static
    {
        return $this->state(fn (array $attributes) => [
            'size' => fake()->numberBetween(100_000, 100_000_000),
            'file_count' => fake()->numberBetween(1, 5),
        ]);
    }

    /**
     * Indicate that the torrent has no description.
     */
    public function withoutDescription(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => null,
        ]);
    }

    /**
     * Indicate that the torrent has a stored file.
     */
    public function withFile(): static
    {
        return $this->state(fn (array $attributes) => [
            'torrent_file' => 'torrents/'.fake()->uuid().'.torrent',
        ]);
    }
}
