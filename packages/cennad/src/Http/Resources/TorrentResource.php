<?php

declare(strict_types=1);

namespace Marque\Cennad\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/**
 * @mixin \Marque\Trove\Models\Torrent
 */
class TorrentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $routePrefix = config('cennad.route_names.prefix', 'cennad');

        return [
            'id' => $this->id,
            'info_hash' => $this->info_hash,
            'name' => $this->name,
            'description' => $this->description,
            'size' => $this->size,
            'size_formatted' => $this->sizeForHumans(),
            'file_count' => $this->file_count,
            'has_torrent_file' => $this->torrent_file !== null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'links' => [
                'self' => $this->buildRouteUrl("{$routePrefix}.torrents.show", $this->resource),
                'download' => $this->torrent_file ? $this->buildDownloadUrl() : null,
            ],
        ];
    }

    /**
     * Build a route URL if the route exists.
     */
    protected function buildRouteUrl(string $routeName, mixed $parameters = []): ?string
    {
        if (Route::has($routeName)) {
            return route($routeName, $parameters);
        }

        return null;
    }

    /**
     * Build the download URL using configured route name.
     */
    protected function buildDownloadUrl(): ?string
    {
        $downloadRoute = config('cennad.route_names.download', 'torrents.download');

        return $this->buildRouteUrl($downloadRoute, $this->resource);
    }
}
