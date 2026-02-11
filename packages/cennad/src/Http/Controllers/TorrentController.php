<?php

declare(strict_types=1);

namespace Marque\Cennad\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Marque\Cennad\Http\Resources\TorrentCollection;
use Marque\Cennad\Http\Resources\TorrentResource;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentService;
use Symfony\Component\HttpFoundation\Response;

class TorrentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private TorrentService $service
    ) {}

    /**
     * Display a listing of torrents.
     */
    public function index(Request $request): TorrentCollection
    {
        $torrents = $this->service->list(
            perPage: $request->integer('per_page', 25),
            search: $request->string('search')->toString() ?: null,
        );

        return new TorrentCollection($torrents);
    }

    /**
     * Store a newly created torrent.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Torrent::class);

        $validated = $request->validate([
            'torrent_file' => 'required|file|max:2048',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
        ]);

        $torrent = $this->service->createFromUpload(
            file: $request->file('torrent_file'),
            user: $request->user(),
            name: $validated['name'],
            description: $validated['description'] ?? null,
        );

        return (new TorrentResource($torrent->load('user')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified torrent.
     */
    public function show(Torrent $torrent): TorrentResource
    {
        return new TorrentResource($torrent->load('user'));
    }

    /**
     * Update the specified torrent.
     */
    public function update(Request $request, Torrent $torrent): TorrentResource
    {
        $this->authorize('update', $torrent);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:10000',
        ]);

        $updated = $this->service->update($torrent, $validated);

        return new TorrentResource($updated->load('user'));
    }

    /**
     * Remove the specified torrent.
     */
    public function destroy(Torrent $torrent): JsonResponse
    {
        $this->authorize('delete', $torrent);

        $this->service->delete($torrent);

        return response()->json(null, 204);
    }
}
