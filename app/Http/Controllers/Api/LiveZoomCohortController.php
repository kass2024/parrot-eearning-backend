<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveZoomCohort;
use Illuminate\Http\Request;

class LiveZoomCohortController extends Controller
{
    public function index()
    {
        return response()->json(
            LiveZoomCohort::query()
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get(),
            200
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'day_of_week' => 'required|integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data['timezone'] = $data['timezone'] ?? 'Africa/Kigali';
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        if ($request->user()) {
            $data['created_by'] = $request->user()->id;
        }

        $slot = LiveZoomCohort::create($data);

        return response()->json([
            'message' => 'Live Zoom cohort created',
            'slot' => $slot,
        ], 201);
    }

    public function update(Request $request, LiveZoomCohort $liveZoomCohort)
    {
        $data = $request->validate([
            'day_of_week' => 'sometimes|required|integer|min:0|max:6',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (array_key_exists('start_time', $data) && array_key_exists('end_time', $data)) {
            if ($data['end_time'] <= $data['start_time']) {
                return response()->json(['message' => 'end_time must be after start_time'], 422);
            }
        }

        $liveZoomCohort->fill($data);
        $liveZoomCohort->save();

        return response()->json([
            'message' => 'Live Zoom cohort updated',
            'slot' => $liveZoomCohort,
        ], 200);
    }

    public function destroy(LiveZoomCohort $liveZoomCohort)
    {
        $liveZoomCohort->delete();

        return response()->json([
            'message' => 'Live Zoom cohort deleted',
        ], 200);
    }
}
