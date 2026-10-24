<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait PaginatesCollections
{
    private function collectionResponse(Request $request, $query, string $key, callable $map): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $query->paginate($data['per_page'] ?? 12, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            $key => $page->getCollection()->map($map)->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ]);
    }
}
