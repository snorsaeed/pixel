<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    private const CATEGORIES = ['rent', 'salary', 'utilities', 'supplies', 'marketing', 'other'];
    private const CURRENCIES = ['USD', 'IQD'];

    private function rate($shop): float
    {
        return (float) ($shop->setting?->iqd_rate ?? 0);
    }

    private function toUsd(float $amount, string $currency, float $rate): float
    {
        if (strtoupper($currency) === 'IQD' && $rate > 0) {
            return round($amount / $rate, 2);
        }
        return round($amount, 2);
    }

    private function toIqd(float $usd, float $rate): ?int
    {
        return $rate > 0 ? (int) round($usd * $rate) : null;
    }

    private function shape($e, float $rate): array
    {
        return [
            'id'           => $e->id,
            'title'        => $e->title,
            'amount'       => round((float) $e->amount, 2),
            'amount_iqd'   => $this->toIqd((float) $e->amount, $rate),
            'category'     => $e->category,
            'expense_date' => $e->expense_date?->toDateString(),
            'notes'        => $e->notes,
            'created_at'   => $e->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request)
    {
        $shop = $request->user()->shop;
        if (! $shop) return response()->json(['message' => 'No shop for this user'], 404);

        $rate = $this->rate($shop);

        $query = $shop->expenses()->orderByDesc('expense_date')->orderByDesc('id');

        if (($c = $request->query('category')) && in_array($c, self::CATEGORIES, true)) {
            $query->where('category', $c);
        }
        if ($from = $request->query('from')) $query->whereDate('expense_date', '>=', $from);
        if ($to   = $request->query('to'))   $query->whereDate('expense_date', '<=', $to);
        if ($s    = $request->query('search')) $query->where('title', 'like', "%{$s}%");

        $expenses = $query->get();

        $monthTotal = (float) $shop->expenses()
            ->whereYear('expense_date', now()->year)
            ->whereMonth('expense_date', now()->month)
            ->sum('amount');

        $yearTotal = (float) $shop->expenses()
            ->whereYear('expense_date', now()->year)
            ->sum('amount');

        $filteredTotal = (float) $expenses->sum(fn ($e) => (float) ($e->amount ?? 0));

        $byCategory = $expenses
            ->groupBy(fn ($e) => $e->category ?: 'other')
            ->map(fn ($rows) => round($rows->sum(fn ($e) => (float) ($e->amount ?? 0)), 2));

        return response()->json([
            'expenses' => $expenses->map(fn ($e) => $this->shape($e, $rate))->values(),
            'summary'  => [
                'month'       => round($monthTotal, 2),
                'month_iqd'   => $this->toIqd($monthTotal, $rate),
                'year'        => round($yearTotal, 2),
                'year_iqd'    => $this->toIqd($yearTotal, $rate),
                'total'       => round($filteredTotal, 2),
                'total_iqd'   => $this->toIqd($filteredTotal, $rate),
                'count'       => $expenses->count(),
                'iqd_rate'    => $rate,
                'by_category' => $byCategory,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $shop = $request->user()->shop;
        if (! $shop) return response()->json(['message' => 'No shop for this user'], 404);

        $v = $request->validate([
            'title'        => ['required', 'string', 'max:120'],
            'amount'       => ['required', 'numeric', 'min:0'],
            'currency'     => ['sometimes', 'string', Rule::in(self::CURRENCIES)],
            'category'     => ['nullable', Rule::in(self::CATEGORIES)],
            'expense_date' => ['required', 'date'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $currency = strtoupper($v['currency'] ?? 'USD');
        $rate = $this->rate($shop);

        if ($currency === 'IQD' && $rate <= 0) {
            return response()->json([
                'errors' => ['amount' => ['IQD rate is not set in Shop Settings.']],
            ], 422);
        }

        $usd = $this->toUsd((float) $v['amount'], $currency, $rate);

        $expense = $shop->expenses()->create([
            'title'        => $v['title'],
            'amount'       => $usd,
            'category'     => $v['category'] ?? null,
            'expense_date' => $v['expense_date'],
            'notes'        => $v['notes'] ?? null,
            'created_by'   => $request->user()->id,
        ]);

        return response()->json([
            'message'  => 'Expense added.',
            'expense'  => $this->shape($expense, $rate),
            'iqd_rate' => $rate,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $shop = $request->user()->shop;
        if (! $shop) return response()->json(['message' => 'No shop for this user'], 404);

        $expense = $shop->expenses()->findOrFail($id);

        $v = $request->validate([
            'title'        => ['sometimes', 'required', 'string', 'max:120'],
            'amount'       => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency'     => ['sometimes', 'string', Rule::in(self::CURRENCIES)],
            'category'     => ['sometimes', 'nullable', Rule::in(self::CATEGORIES)],
            'expense_date' => ['sometimes', 'required', 'date'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $rate = $this->rate($shop);

        if (array_key_exists('amount', $v)) {
            $currency = strtoupper($v['currency'] ?? 'USD');

            if ($currency === 'IQD' && $rate <= 0) {
                return response()->json([
                    'errors' => ['amount' => ['IQD rate is not set in Shop Settings.']],
                ], 422);
            }

            $v['amount'] = $this->toUsd((float) $v['amount'], $currency, $rate);
        }

        unset($v['currency']);
        $expense->fill($v)->save();

        return response()->json([
            'message'  => 'Expense updated.',
            'expense'  => $this->shape($expense->fresh(), $rate),
            'iqd_rate' => $rate,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $shop = $request->user()->shop;
        if (! $shop) return response()->json(['message' => 'No shop for this user'], 404);

        $shop->expenses()->findOrFail($id)->delete();

        return response()->json(['message' => 'Expense removed.']);
    }
}