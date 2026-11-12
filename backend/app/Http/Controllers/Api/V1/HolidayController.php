<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HolidayController extends Controller
{
    /**
     * Get holidays for a given year/month.
     */
    public function index(Request $request)
    {
        $month = $request->query('month'); // YYYY-MM
        $year = $request->query('year');

        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $targetYear = (int) substr($month, 0, 4);
            $targetMonth = (int) substr($month, 5, 2);
        } elseif ($year && is_numeric($year)) {
            $targetYear = (int) $year;
            $targetMonth = null;
        } else {
            $now = Carbon::now();
            $targetYear = $now->year;
            $targetMonth = $now->month;
        }

        $allHolidays = DB::table('holidays')->get();
        $results = [];

        foreach ($allHolidays as $h) {
            $date = Carbon::parse($h->holiday_date);
            $hMonth = $date->month;
            $hDay = $date->day;

            if ($h->repeats_annually) {
                // Generates effective holiday date for target year
                $effectiveDate = sprintf('%04d-%02d-%02d', $targetYear, $hMonth, $hDay);

                if ($targetMonth === null || $hMonth === $targetMonth) {
                    $results[] = [
                        'id' => $h->id,
                        'name' => $h->name,
                        'date' => $effectiveDate,
                        'original_date' => $h->holiday_date,
                        'repeats_annually' => true,
                        'notes' => $h->notes,
                        'location_id' => $h->location_id,
                    ];
                }
            } else {
                if ($date->year === $targetYear && ($targetMonth === null || $date->month === $targetMonth)) {
                    $results[] = [
                        'id' => $h->id,
                        'name' => $h->name,
                        'date' => $date->format('Y-m-d'),
                        'original_date' => $h->holiday_date,
                        'repeats_annually' => false,
                        'notes' => $h->notes,
                        'location_id' => $h->location_id,
                    ];
                }
            }
        }

        // Sort by date ascending
        usort($results, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return response()->json([
            'status' => 'success',
            'data' => $results,
        ]);
    }
}
