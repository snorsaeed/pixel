<?php

namespace App\Http\Controllers;

use App\Models\ShopEmployee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopSettingController extends Controller
{
    // GET /api/shop/settings
    public function show(Request $request)
    {
        $shop = $request->user()->shop;

        if (! $shop) {
            return response()->json(['message' => 'No shop for this user'], 404);
        }

        $setting = $shop->setting()->firstOrCreate([]);

        $payload = [
            'shop'    => $shop->only([
                'id',
                'shop_name',
                'phone',
                'expires_at',
                'rent',
                'decant_price',
                'min_decant_price',
            ]),
            'setting'   => $setting,
            'employees' => $shop->employees()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        ];

        if ($month = $request->query('month')) {
            try {
                $payload['rent_for_month'] = $shop->rentAmountForMonth(Carbon::parse($month));
            } catch (\Throwable $e) {
                // ignore bad month string
            }
        }

        $payload['rent_history'] = $shop->rents()
            ->orderByDesc('effective_from')
            ->get(['effective_from', 'amount']);

        return response()->json($payload);
    }

    // POST / PUT /api/shop/settings
    public function upsert(Request $request)
    {
        $shop = $request->user()->shop;

        if (! $shop) {
            return response()->json(['message' => 'No shop for this user'], 404);
        }

        $validated = $request->validate([
            'iqd_rate'           => ['sometimes', 'numeric', 'gt:0'],
            'discount_code'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'discount_min_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'discount_value'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'discount_type'      => ['sometimes', 'nullable', Rule::in(['percentage', 'fixed'])],
            'website_url'        => ['sometimes', 'nullable', 'url', 'max:255'],
            'website_img'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'hero_url'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'hero_text'          => ['sometimes', 'nullable', 'string'],

            'rent'             => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'decant_price'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_decant_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'printer_id'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'printer_api_key'  => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Round all money to 4 decimals — kills JS float junk like 363.6363636363636
        foreach (['rent', 'decant_price', 'min_decant_price', 'discount_min_spend', 'discount_value'] as $k) {
            if (array_key_exists($k, $validated) && $validated[$k] !== null) {
                $validated[$k] = round((float) $validated[$k], 4);
            }
        }

        // Cross-field checks
        if (
            ($validated['discount_type'] ?? null) === 'percentage' &&
            ($validated['discount_value'] ?? 0) > 100
        ) {
            return response()->json([
                'errors' => ['discount_value' => ['Percentage cannot exceed 100.']],
            ], 422);
        }

        $decantPrice    = $validated['decant_price']     ?? $shop->decant_price;
        $minDecantPrice = $validated['min_decant_price'] ?? $shop->min_decant_price;

        if (
            $decantPrice !== null &&
            $minDecantPrice !== null &&
            (float) $minDecantPrice > (float) $decantPrice
        ) {
            return response()->json([
                'errors' => [
                    'min_decant_price' => ['Minimum decant price cannot exceed the decant price.'],
                ],
            ], 422);
        }

        if (! empty($validated['discount_code'])) {
            $validated['discount_code'] = strtoupper($validated['discount_code']);
        }

        // RENT: append/update the current month only
        if (array_key_exists('rent', $validated) && $validated['rent'] !== null) {
            $newRent   = (float) $validated['rent'];
            $thisMonth = now()->startOfMonth()->toDateString();

            $row = $shop->rents()
                ->where('effective_from', $thisMonth)
                ->first();

            if ($row) {
                $row->update(['amount' => $newRent]);
            } else {
                $shop->rents()->create([
                    'effective_from' => $thisMonth,
                    'amount'         => $newRent,
                ]);
            }

            $shop->rent = $newRent;
        }

        $shopFields = array_intersect_key($validated, array_flip([
            'decant_price',
            'min_decant_price',
        ]));

        if (! empty($shopFields)) {
            $shop->fill($shopFields);
        }

        if (! empty($shopFields) || array_key_exists('rent', $validated)) {
            $shop->save();
        }

        $settingFields = array_diff_key(
            $validated,
            array_flip(['rent', 'decant_price', 'min_decant_price'])
        );

        $setting = $shop->setting()->updateOrCreate(
            ['shop_id' => $shop->id],
            $settingFields
        );

        return response()->json([
            'message'      => 'Shop settings saved.',
            'shop'         => $shop->only([
                'id',
                'shop_name',
                'phone',
                'expires_at',
                'rent',
                'decant_price',
                'min_decant_price',
            ]),
            'setting'      => $setting,
            'rent_history' => $shop->rents()
                ->orderByDesc('effective_from')
                ->get(['effective_from', 'amount']),
        ]);
    }

    // ───── Employees ─────

    public function storeEmployee(Request $request)
    {
        $shop = $request->user()->shop;
        if (! $shop) {
            return response()->json(['message' => 'No shop for this user'], 404);
        }

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'phone'     => ['nullable', 'string', 'max:40'],
            'role'      => ['nullable', 'string', 'max:40'],
            'salary'    => ['nullable', 'numeric', 'min:0'],
            'hire_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes'     => ['nullable', 'string'],
        ]);

        if (array_key_exists('salary', $validated) && $validated['salary'] !== null) {
            $validated['salary'] = round((float) $validated['salary'], 4);
        }

        $employee = $shop->employees()->create($validated);

        return response()->json([
            'message'  => 'Employee added.',
            'employee' => $employee,
        ], 201);
    }

    public function updateEmployee(Request $request, $id)
    {
        $shop = $request->user()->shop;
        if (! $shop) {
            return response()->json(['message' => 'No shop for this user'], 404);
        }

        $employee = $shop->employees()->findOrFail($id);

        $validated = $request->validate([
            'name'      => ['sometimes', 'required', 'string', 'max:120'],
            'phone'     => ['sometimes', 'nullable', 'string', 'max:40'],
            'role'      => ['sometimes', 'nullable', 'string', 'max:40'],
            'salary'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'hire_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes'     => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('salary', $validated) && $validated['salary'] !== null) {
            $validated['salary'] = round((float) $validated['salary'], 4);
        }

        $employee->fill($validated);
        $employee->save();

        return response()->json([
            'message'  => 'Employee updated.',
            'employee' => $employee,
        ]);
    }

    public function destroyEmployee(Request $request, $id)
    {
        $shop = $request->user()->shop;
        if (! $shop) {
            return response()->json(['message' => 'No shop for this user'], 404);
        }

        $employee = $shop->employees()->findOrFail($id);
        $employee->delete();

        return response()->json(['message' => 'Employee removed.']);
    }
}