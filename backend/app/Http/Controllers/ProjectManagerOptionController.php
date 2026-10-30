<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexProjectManagerOptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ProjectManagerOptionController extends Controller
{
    public function index(IndexProjectManagerOptionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $users = User::query()->select(['id', 'first_name', 'last_name'])
            ->when($request->filled('search'), function ($query) use ($data) {
                $terms = preg_split('/\s+/u', trim($data['search']), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($terms as $term) {
                    $query->where(function ($query) use ($term) {
                        $query->where('first_name', 'like', '%'.$term.'%')
                            ->orWhere('last_name', 'like', '%'.$term.'%');
                    });
                }
            })
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($data['per_page'] ?? 20)->withQueryString();

        return response()->json($users);
    }
}
