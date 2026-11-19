<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim($data['search'] ?? '');

        $logs = AuditLog::with('actor')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('event_key', 'like', '%'.$search.'%')
                        ->orWhere('entity_type', 'like', '%'.$search.'%')
                        ->orWhere('entity_id', 'like', '%'.$search.'%')
                        ->orWhereHas('actor', function (Builder $actorQuery) use ($search): void {
                            $actorQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.history', [
            'logs' => $logs,
            'search' => $search,
        ]);
    }
}
