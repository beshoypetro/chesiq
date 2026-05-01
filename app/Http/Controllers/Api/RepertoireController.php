<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpeningRepetition;
use App\Models\Repertoire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RepertoireController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $repertoires = Repertoire::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'repertoires' => $repertoires->map(fn ($r) => $this->format($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|in:white,black',
            'tree_json' => 'required|string',
        ]);

        $rep = Repertoire::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'color' => $data['color'],
            'tree_json' => $data['tree_json'],
            'updated_at' => now(),
        ]);

        return response()->json(['repertoire' => $this->format($rep)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $rep = Repertoire::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['repertoire' => $this->format($rep)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rep = Repertoire::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'tree_json' => 'nullable|string',
        ]);

        $rep->update(array_filter([
            'name' => $data['name'] ?? null,
            'tree_json' => $data['tree_json'] ?? null,
            'updated_at' => now(),
        ], fn ($v) => $v !== null));

        return response()->json(['repertoire' => $this->format($rep->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $rep = Repertoire::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $rep->delete();

        return response()->json(['message' => 'Repertoire deleted.']);
    }

    public function exportPgn(Request $request, int $id): Response
    {
        $rep = Repertoire::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $tree = json_decode($rep->tree_json, true) ?? [];
        $pgn = $this->treeToPgn($tree, $rep->name);

        return response($pgn, 200, [
            'Content-Type' => 'application/x-chess-pgn',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^a-z0-9]/i', '_', $rep->name) . '.pgn"',
        ]);
    }

    /**
     * F027: Return the repertoire tree annotated with SR (spaced repetition) status per node.
     * Status: green = interval > 30 days, yellow = due soon (<=7 days), red = never attempted.
     */
    public function coverage(Request $request, int $id): JsonResponse
    {
        $rep = Repertoire::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $userId = $request->user()->id;
        $openingKey = 'repertoire_' . $id;

        // Load all SR records for this repertoire
        $srRecords = OpeningRepetition::where('user_id', $userId)
            ->where('opening_key', $openingKey)
            ->get()
            ->keyBy('move_uci');

        $tree = json_decode($rep->tree_json, true) ?? [];
        $annotated = $this->annotateTree($tree, $srRecords->toArray());

        return response()->json([
            'repertoire_id' => $id,
            'tree' => $annotated,
        ]);
    }

    private function annotateTree(array $node, array $srByUci): array
    {
        $children = $node['children'] ?? $node['moves'] ?? [];
        $annotatedChildren = [];
        foreach ($children as $child) {
            $annotatedChildren[] = $this->annotateTree($child, $srByUci);
        }

        $uci = $node['uci'] ?? $node['move'] ?? null;
        $status = 'red'; // never attempted
        if ($uci && isset($srByUci[$uci])) {
            $sr = $srByUci[$uci];
            $interval = $sr['interval_days'] ?? 0;
            $dueAt = $sr['due_at'] ?? null;
            if ($interval > 30) {
                $status = 'green';
            } elseif ($dueAt && now()->diffInDays($dueAt, false) > 7) {
                $status = 'green';
            } else {
                $status = 'yellow';
            }
        }

        return array_merge($node, [
            'sr_status' => $status,
            'children' => $annotatedChildren,
        ]);
    }

    private function treeToPgn(array $tree, string $name): string
    {
        // Convert tree structure to PGN format
        // Expected tree: { moves: [{ san, annotation, children: [...] }] }
        $header = "[Event \"Chesiq Repertoire: {$name}\"]\n[Site \"Chesiq\"]\n[Date \"????.??.??\"]\n[Result \"*\"]\n\n";

        $moveText = $this->treeToMoveText($tree['moves'] ?? [], 1, true);

        return $header . trim($moveText) . " *\n";
    }

    private function treeToMoveText(array $moves, int $moveNum, bool $isWhite): string
    {
        if (empty($moves)) return '';

        $text = '';
        $firstMove = true;

        foreach ($moves as $i => $move) {
            $san = $move['san'] ?? '';
            $annotation = $move['annotation'] ?? '';

            if ($isWhite) {
                $text .= "{$moveNum}. {$san} ";
            } else {
                if ($firstMove) {
                    $text .= "{$moveNum}... {$san} ";
                } else {
                    $text .= "{$san} ";
                }
            }

            if ($annotation) {
                $text .= "{" . addslashes($annotation) . "} ";
            }

            // Handle variations (siblings after first)
            if ($i > 0) {
                // This is a variation — wrap in parens (handled by caller)
            }

            // Recurse into children (main line)
            $children = $move['children'] ?? [];
            if (!empty($children)) {
                $nextIsWhite = !$isWhite;
                $nextMoveNum = $isWhite ? $moveNum : $moveNum + 1;
                $text .= $this->treeToMoveText($children, $nextMoveNum, $nextIsWhite);
            }

            // Only process the first move as main line
            break;
        }

        return $text;
    }

    private function format(Repertoire $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'color' => $r->color,
            'tree_json' => $r->tree_json,
            'updated_at' => $r->updated_at?->toISOString(),
        ];
    }
}
