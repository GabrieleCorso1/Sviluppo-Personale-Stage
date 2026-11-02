<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureManagedProject
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');
        abort_if($project instanceof Project && $project->is_course, 404);

        return $next($request);
    }
}
