<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tags\Models\Tag;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tags\SaveTagRequest;
use App\Http\Resources\TagResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class TagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TagResource::collection(Tag::query()->orderBy('name')->get());
    }

    public function store(SaveTagRequest $request): JsonResponse
    {
        return TagResource::make(Tag::create($request->validated()))->response()->setStatusCode(201);
    }

    public function update(SaveTagRequest $request, Tag $tag): TagResource
    {
        $tag->update($request->validated());

        return TagResource::make($tag);
    }

    public function destroy(Tag $tag): Response
    {
        $tag->delete();

        return response()->noContent();
    }
}
