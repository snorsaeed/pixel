<?php

namespace App\Http\Controllers;

use App\Models\sales;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    // ═══════════════════════════════════════════════════════
    //  DASHBOARD
    // ═══════════════════════════════════════════════════════
    public function dashboard(Request $request)
    {
        $shopId  = $request->user()->shop_id;
        $isAdmin = $this->isAdmin($request);
        $now     = Carbon::now();
        [$from, $to] = $this->resolveRange($request);

        return response()->json([
            'is_admin' => $isAdmin,
            'range' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
                'days' => (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1,
            ],
            'kpis' => [
                'today'    => $this->kpis($shopId, $now->copy()->startOfDay(), null, $isAdmin),
                'week'     => $this->kpis($shopId, $now->copy()->startOfWeek(Carbon::SUNDAY), null, $isAdmin),
                'month'    => $this->kpis($shopId, $now->copy()->startOfMonth(), null, $isAdmin),
                'year'     => $this->kpis($shopId, $now->copy()->startOfYear(), null, $isAdmin),
                'selected' => $this->kpis($shopId, $from, $to, $isAdmin),
            ],
            'chart'          => $this->revenueChart($shopId, $from, $to, $isAdmin),
            'top_products'   => $this->productPerformance($shopId, $from, $to, 5, 'best', $isAdmin),
            'worst_products' => $this->productPerformance($shopId, $from, $to, 5, 'worst', $isAdmin),
            'top_customers'  => $this->topCustomers($shopId, $from, $to, 5),
            'recent_sales'   => $this->recentSales($shopId, 10),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  REPORT — SALES
    // ═══════════════════════════════════════════════════════
    public function salesReport(Request $request)
    {
        $shopId  = $request->user()->shop_id;
        $isAdmin = $this->isAdmin($request);
        [$from, $to] = $this->resolveRange($request);
        $groupBy = $request->query('group', 'day');

        return response()->json([
            'is_admin'      => $isAdmin,
            'range'         => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary'       => $this->kpis($shopId, $from, $to, $isAdmin),
            'breakdown'     => $this->salesBreakdown($shopId, $from, $to, $groupBy),
            'chart'         => $this->revenueChart($shopId, $from, $to, $isAdmin),
            'brand_split'   => $this->brandSplit($shopId, $from, $to),
            'top_products'  => $this->productPerformance($shopId, $from, $to, 5, 'best', $isAdmin),
            'top_customers' => $this->topCustomers($shopId, $from, $to, 5),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  REPORT — PRODUCTS
    // ═══════════════════════════════════════════════════════
    public function productsReport(Request $request)
    {
        $shopId  = $request->user()->shop_id;
        $isAdmin = $this->isAdmin($request);
        [$from, $to] = $this->resolveRange($request);
        $limit  = (int) $request->query('limit', 20);
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            return response()->json([
                'is_admin' => $isAdmin,
                'range'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'search'   => $search,
                'mode'     => 'search',
                'products' => $this->searchProducts($shopId, $from, $to, $search, $limit, $isAdmin),
            ]);
        }

        return response()->json([
            'is_admin' => $isAdmin,
            'range'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'search'   => '',
            'mode'     => 'list',
            'best'     => $this->productPerformance($shopId, $from, $to, $limit, 'best', $isAdmin),
            'worst'    => $this->productPerformance($shopId, $from, $to, $limit, 'worst', $isAdmin),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  REPORT — CUSTOMERS
    // ═══════════════════════════════════════════════════════
    public function customersReport(Request $request)
    {
        $shopId  = $request->user()->shop_id;
        $isAdmin = $this->isAdmin($request);
        [$from, $to] = $this->resolveRange($request);
        $search = trim((string) $request->query('search', ''));
        $limit  = (int) $request->query('limit', 100);

        $q = DB::table('sales')
            ->where('shop_id', $shopId)
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->when($from, fn($x) => $x->where('created_at', '>=', $from))
            ->when($to,   fn($x) => $x->where('created_at', '<=', $to));

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('phone', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $rows = $q->groupBy('phone')
            ->selectRaw('phone, MAX(name) as name, COUNT(*) as orders, SUM(total) as spent, MAX(created_at) as last_order')
            ->orderByDesc('spent')
            ->limit($limit)
            ->get()
            ->map(fn($c) => [
                'phone'      => $c->phone,
                'name'       => $c->name,
                'orders'     => (int) $c->orders,
                'spent'      => round((float) $c->spent, 2),
                'avg_order'  => $c->orders > 0 ? round((float) $c->spent / $c->orders, 2) : 0,
                'last_order' => $c->last_order,
            ])
            ->all();

        return response()->json([
            'is_admin'  => $isAdmin,
            'range'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'search'    => $search,
            'customers' => $rows,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  REPORT — BRANDS
    // ═══════════════════════════════════════════════════════
    public function brandsReport(Request $request)
    {
        $shopId  = $request->user()->shop_id;
        $isAdmin = $this->isAdmin($request);
        [$from, $to] = $this->resolveRange($request);
        $search = trim((string) $request->query('search', ''));

        $q = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
            ->join('products', 'products.id', '=', 'sale_items.products_id')
            ->where('sales.shop_id', $shopId)
            ->where('sale_items.status', 'sold')
            ->when($from, fn($x) => $x->where('sales.created_at', '>=', $from))
            ->when($to,   fn($x) => $x->where('sales.created_at', '<=', $to));

        if ($search !== '') {
            $q->where('products.brand', 'like', "%{$search}%");
        }

        $q->groupBy('products.brand')
            ->selectRaw("COALESCE(NULLIF(products.brand, ''), 'Other') as brand")
            ->selectRaw('SUM(sale_items.qyt) as qty')
            ->selectRaw('SUM(sale_items.qyt * sale_items.price) as revenue');

        if ($isAdmin) {
            // ✅ Decants: profit = price × qty. Normal: price − buy_price.
            $q->selectRaw('SUM(CASE WHEN sale_items.is_tester = 1 THEN sale_items.qyt * sale_items.price ELSE sale_items.qyt * (sale_items.price - products.buy_price) END) as profit');
        }

        $rows = $q->orderByDesc('revenue')
            ->limit(100)
            ->get()
            ->map(function ($r) use ($isAdmin) {
                $out = [
                    'brand'   => $r->brand,
                    'qty'     => (int) $r->qty,
                    'revenue' => round((float) $r->revenue, 2),
                ];
                if ($isAdmin) {
                    $out['profit'] = round((float) $r->profit, 2);
                }
                return $out;
            })
            ->all();

        return response()->json([
            'is_admin' => $isAdmin,
            'range'    => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'search'   => $search,
            'brands'   => $rows,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  REPORT — NET PROFIT (monthly)
    // ═══════════════════════════════════════════════════════
    public function netProfitReport(Request $request)
    {
        $shop   = $request->user()->shop;
        $shopId = $shop->id;
        [$from, $to] = $this->resolveRange($request);

        $rentMonthly = (float) ($shop->currentRentAmount() ?? 0);

        $employees       = DB::table('shop_employees')
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->get(['id', 'name', 'role', 'salary']);
        $salariesMonthly = (float) $employees->sum('salary');

        $revenue = (float) DB::table('sales')
            ->where('shop_id', $shopId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('total');

        // COGS — decants contribute 0 COGS because their profit is price × qty
        $cogs = (float) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
            ->join('products', 'products.id', '=', 'sale_items.products_id')
            ->where('sales.shop_id', $shopId)
            ->where('sale_items.status', 'sold')
            ->where('sale_items.is_tester', 0)
            ->whereBetween('sales.created_at', [$from, $to])
            ->sum(DB::raw('sale_items.qyt * products.buy_price'));

        // Gross profit — decants: qyt × price; normal: qyt × (price − buy_price)
        $grossProfit = (float) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
            ->join('products', 'products.id', '=', 'sale_items.products_id')
            ->where('sales.shop_id', $shopId)
            ->where('sale_items.status', 'sold')
            ->whereBetween('sales.created_at', [$from, $to])
            ->sum(DB::raw('CASE WHEN sale_items.is_tester = 1 THEN sale_items.qyt * sale_items.price ELSE sale_items.qyt * (sale_items.price - products.buy_price) END'));

        $expenses = DB::table('expenses')
            ->where('shop_id', $shopId)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->get(['category', 'amount']);

        $totalExpenses = (float) $expenses->sum('amount');

        $rentForPeriod     = $this->rentCostForRange($shop, $from, $to);
        $salariesForPeriod = $this->costForRange($salariesMonthly, $from, $to);

        $netProfit = $grossProfit - $totalExpenses - $rentForPeriod - $salariesForPeriod;

        $expenseByCategory = $expenses
            ->groupBy(fn ($e) => $e->category ?: 'other')
            ->map(fn ($rows) => round((float) $rows->sum('amount'), 2));

        return response()->json([
            'range' => [
                'from'   => $from->toDateString(),
                'to'     => $to->toDateString(),
                'days'   => (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1,
                'months' => $this->effectiveMonths($from, $to),
            ],
            'current' => [
                'revenue'       => round($revenue, 2),
                'cogs'          => round($cogs, 2),
                'gross_profit'  => round($grossProfit, 2),
                'expenses'      => round($totalExpenses, 2),
                'rent'          => round($rentForPeriod, 2),
                'salaries'      => round($salariesForPeriod, 2),
                'net_profit'    => round($netProfit, 2),
                'gross_margin'  => $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0,
                'net_margin'    => $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0,
            ],
            'fixed' => [
                'rent_monthly'     => round($rentMonthly, 2),
                'salaries_monthly' => round($salariesMonthly, 2),
                'months'           => $this->effectiveMonths($from, $to),
            ],
            'monthly'           => $this->netProfitMonthly($shop, $from, $to, $salariesMonthly),
            'expense_breakdown' => $expenseByCategory,
            'employees' => $employees->map(fn ($e) => [
                'id'     => $e->id,
                'name'   => $e->name,
                'role'   => $e->role,
                'salary' => round((float) $e->salary, 2),
            ])->values(),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════
    private function isAdmin(Request $request): bool
    {
        return ($request->user()->role ?? 'user') === 'admin';
    }

    private function costForRange(float $monthlyCost, $from, $to): float
    {
        if ($monthlyCost <= 0) return 0;

        $total  = 0;
        $cursor = $from->copy()->startOfMonth();
        $last   = $to->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $mStart = $cursor->copy()->startOfMonth();
            $mEnd   = $cursor->copy()->endOfMonth();

            $clampStart = $mStart->lt($from) ? $from->copy() : $mStart->copy();
            $clampEnd   = $mEnd->gt($to)     ? $to->copy()   : $mEnd->copy();

            $daysInMonth = (int) $mStart->copy()->endOfMonth()->day;
            $daysInRange = (int) $clampStart->copy()->startOfDay()
                ->diffInDays($clampEnd->copy()->startOfDay()) + 1;

            $total += $monthlyCost * ($daysInRange / $daysInMonth);

            $cursor->addMonth();
        }

        return round($total, 2);
    }

    private function rentCostForRange($shop, $from, $to): float
    {
        $total  = 0;
        $cursor = $from->copy()->startOfMonth();
        $last   = $to->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $mStart = $cursor->copy()->startOfMonth();
            $mEnd   = $cursor->copy()->endOfMonth();

            $monthlyRent = (float) ($shop->rentAmountForMonth($mStart) ?? 0);

            if ($monthlyRent > 0) {
                $clampStart = $mStart->lt($from) ? $from->copy() : $mStart->copy();
                $clampEnd   = $mEnd->gt($to)     ? $to->copy()   : $mEnd->copy();

                $daysInMonth = (int) $mStart->copy()->endOfMonth()->day;
                $daysInRange = (int) $clampStart->copy()->startOfDay()
                    ->diffInDays($clampEnd->copy()->startOfDay()) + 1;

                $total += $monthlyRent * ($daysInRange / $daysInMonth);
            }

            $cursor->addMonth();
        }

        return round($total, 2);
    }

    private function effectiveMonths($from, $to): float
    {
        $total  = 0;
        $cursor = $from->copy()->startOfMonth();
        $last   = $to->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $mStart = $cursor->copy()->startOfMonth();
            $mEnd   = $cursor->copy()->endOfMonth();

            $clampStart = $mStart->lt($from) ? $from->copy() : $mStart->copy();
            $clampEnd   = $mEnd->gt($to)     ? $to->copy()   : $mEnd->copy();

            $daysInMonth = (int) $mStart->copy()->endOfMonth()->day;
            $daysInRange = (int) $clampStart->copy()->startOfDay()
                ->diffInDays($clampEnd->copy()->startOfDay()) + 1;

            $total += $daysInRange / $daysInMonth;

            $cursor->addMonth();
        }

        return round($total, 2);
    }

    private function netProfitMonthly($shop, $from, $to, $salariesPerMonth): array
    {
        $shopId = $shop->id;
        $out = [];
        $cursor = $from->copy()->startOfMonth();
        $lastMonth = $to->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $mStart = $cursor->copy()->startOfMonth();
            $mEnd   = $cursor->copy()->endOfMonth();

            $clampStart = $mStart->lt($from) ? $from->copy() : $mStart->copy();
            $clampEnd   = $mEnd->gt($to)     ? $to->copy()   : $mEnd->copy();

            $daysInMonth = (int) $mStart->copy()->endOfMonth()->day;
            $daysInRange = (int) $clampStart->copy()->startOfDay()
                ->diffInDays($clampEnd->copy()->startOfDay()) + 1;
            $ratio = $daysInMonth > 0 ? $daysInRange / $daysInMonth : 1;

            $rev = (float) DB::table('sales')
                ->where('shop_id', $shopId)
                ->whereBetween('created_at', [$clampStart, $clampEnd->copy()->endOfDay()])
                ->sum('total');

            // ✅ Gross profit — decants: qyt × price; normal: qyt × (price − buy_price)
            $gross = (float) DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
                ->join('products', 'products.id', '=', 'sale_items.products_id')
                ->where('sales.shop_id', $shopId)
                ->where('sale_items.status', 'sold')
                ->whereBetween('sales.created_at', [$clampStart, $clampEnd->copy()->endOfDay()])
                ->sum(DB::raw('CASE WHEN sale_items.is_tester = 1 THEN sale_items.qyt * sale_items.price ELSE sale_items.qyt * (sale_items.price - products.buy_price) END'));

            $expenses = (float) DB::table('expenses')
                ->where('shop_id', $shopId)
                ->whereBetween('expense_date', [$clampStart->toDateString(), $clampEnd->toDateString()])
                ->sum('amount');

            $rentForMonth = (float) ($shop->rentAmountForMonth($mStart) ?? 0);
            $rent     = $rentForMonth    * $ratio;
            $salaries = $salariesPerMonth * $ratio;

            $net = $gross - $expenses - $rent - $salaries;

            $out[] = [
                'month'        => $cursor->format('Y-m'),
                'label'        => $cursor->format('M Y'),
                'revenue'      => round($rev, 2),
                'gross_profit' => round($gross, 2),
                'expenses'     => round($expenses, 2),
                'rent'         => round($rent, 2),
                'salaries'     => round($salaries, 2),
                'net_profit'   => round($net, 2),
            ];

            $cursor->addMonth();
        }

        return $out;
    }

    private function resolveRange(Request $request): array
    {
        $period = $request->query('period', 'month');
        $now    = Carbon::now();

        return match ($period) {
            'today', 'day' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'week' => [
                $now->copy()->startOfWeek(Carbon::SUNDAY),
                $now->copy()->endOfWeek(Carbon::SATURDAY),
            ],
            'month' => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
            ],
            'year' => [
                $now->copy()->startOfYear(),
                $now->copy()->endOfYear(),
            ],
            'custom' => [
                $request->query('from')
                    ? Carbon::parse($request->query('from'))->startOfDay()
                    : $now->copy()->subDays(29)->startOfDay(),
                $request->query('to')
                    ? Carbon::parse($request->query('to'))->endOfDay()
                    : $now->copy()->endOfDay(),
            ],
            default => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
            ],
        };
    }

    private function kpis($shopId, $from, $to, bool $isAdmin = true): array
    {
        $posQ = sales::where('shop_id', $shopId);
        if ($from) $posQ->where('created_at', '>=', $from);
        if ($to)   $posQ->where('created_at', '<=', $to);

        $revenue = (float) $posQ->sum('total');
        $orders  = (int)   $posQ->count();

        $itemsQ = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
            ->join('products', 'products.id', '=', 'sale_items.products_id')
            ->where('sales.shop_id', $shopId)
            ->where('sale_items.status', 'sold')
            ->when($from, fn($q) => $q->where('sales.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->where('sales.created_at', '<=', $to))
            ->selectRaw('SUM(sale_items.qyt) as items');

        if ($isAdmin) {
            // ✅ Decants: price × qty. Normal: (price − buy_price) × qty.
            $itemsQ->selectRaw('SUM(CASE WHEN sale_items.is_tester = 1 THEN sale_items.qyt * sale_items.price ELSE sale_items.qyt * (sale_items.price - products.buy_price) END) as profit');
        }

        $items = $itemsQ->first();

        $out = [
            'revenue'   => round($revenue, 2),
            'orders'    => $orders,
            'items'     => (int) ($items->items ?? 0),
            'avg_order' => $orders > 0 ? round($revenue / $orders, 2) : 0,
        ];

        if ($isAdmin) {
            $out['profit'] = round((float) ($items->profit ?? 0), 2);
        }

        return $out;
    }

    private function revenueChart($shopId, $from, $to, bool $isAdmin = true): array
    {
        $pos = DB::table('sales')
            ->where('shop_id', $shopId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, SUM(total) as r, COUNT(*) as o')
            ->groupBy('d')->get()->keyBy('d');

        $posProfit = collect();
        if ($isAdmin) {
            // ✅ Decants: price × qty. Normal: (price − buy_price) × qty.
            $posProfit = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
                ->join('products', 'products.id', '=', 'sale_items.products_id')
                ->where('sales.shop_id', $shopId)
                ->where('sale_items.status', 'sold')
                ->whereBetween('sales.created_at', [$from, $to])
                ->selectRaw('DATE(sales.created_at) as d, SUM(CASE WHEN sale_items.is_tester = 1 THEN sale_items.qyt * sale_items.price ELSE sale_items.qyt * (sale_items.price - products.buy_price) END) as p')
                ->groupBy('d')->get()->keyBy('d');
        }

        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay());
        $out = [];
        for ($i = 0; $i <= $days; $i++) {
            $d = $from->copy()->addDays($i)->format('Y-m-d');
            $row = [
                'date'    => $d,
                'revenue' => round((float) ($pos[$d]->r ?? 0), 2),
                'orders'  => (int) ($pos[$d]->o ?? 0),
            ];
            if ($isAdmin) {
                $row['profit'] = round((float) ($posProfit[$d]->p ?? 0), 2);
            }
            $out[] = $row;
        }
        return $out;
    }

    private function salesBreakdown($shopId, $from, $to, $groupBy): array
    {
        if ($groupBy === 'week') {
            $rows = DB::table('sales')
                ->where('shop_id', $shopId)
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("CONCAT(YEARWEEK(created_at, 0), '') as period")
                ->selectRaw('SUM(total) as revenue, COUNT(*) as orders')
                ->groupBy('period')->get()->keyBy('period');
        } else {
            $format = match ($groupBy) {
                'month' => '%Y-%m',
                'year'  => '%Y',
                default => '%Y-%m-%d',
            };

            $rows = DB::table('sales')
                ->where('shop_id', $shopId)
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("DATE_FORMAT(created_at, '{$format}') as period")
                ->selectRaw('SUM(total) as revenue, COUNT(*) as orders')
                ->groupBy('period')->get()->keyBy('period');
        }

        $keys = collect($rows->keys())->sort()->values();
        $out = [];
        foreach ($keys as $k) {
            $out[] = [
                'period'  => (string) $k,
                'revenue' => round((float) ($rows[$k]->revenue ?? 0), 2),
                'orders'  => (int) ($rows[$k]->orders ?? 0),
            ];
        }
        return $out;
    }

    private function brandSplit($shopId, $from, $to): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
            ->join('products', 'products.id', '=', 'sale_items.products_id')
            ->where('sales.shop_id', $shopId)
            ->where('sale_items.status', 'sold')
            ->when($from, fn($q) => $q->where('sales.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->where('sales.created_at', '<=', $to))
            ->groupBy('products.brand')
            ->selectRaw("COALESCE(NULLIF(products.brand, ''), 'Other') as brand")
            ->selectRaw('SUM(sale_items.qyt * sale_items.price) as revenue')
            ->selectRaw('SUM(sale_items.qyt) as qty')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn($r) => [
                'brand'   => $r->brand,
                'revenue' => round((float) $r->revenue, 2),
                'qty'     => (int) $r->qty,
            ])
            ->all();
    }

    private function searchProducts($shopId, $from, $to, $search, $limit, bool $isAdmin = true): array
    {
        $products = DB::table('products')
            ->where('shop_id', $shopId)
            ->where(function ($w) use ($search) {
                $w->where('model',   'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%")
                  ->orWhere('barcode','like', "%{$search}%");
            })
            ->limit($limit)
            ->get(['id', 'brand', 'model', 'barcode']);

        if ($products->isEmpty()) return [];

        $ids = $products->pluck('id');

        $perfQ = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
            ->join('products', 'products.id', '=', 'sale_items.products_id')
            ->where('sales.shop_id', $shopId)
            ->whereIn('sale_items.products_id', $ids)
            ->where('sale_items.status', 'sold')
            ->when($from, fn($q) => $q->where('sales.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->where('sales.created_at', '<=', $to))
            ->groupBy('products.id')
            ->selectRaw('products.id, SUM(sale_items.qyt) as qty_sold')
            ->selectRaw('SUM(sale_items.qyt * sale_items.price) as revenue');

        if ($isAdmin) {
            // ✅ Decants: price × qty. Normal: (price − buy_price) × qty.
            $perfQ->selectRaw('SUM(CASE WHEN sale_items.is_tester = 1 THEN sale_items.qyt * sale_items.price ELSE sale_items.qyt * (sale_items.price - products.buy_price) END) as profit');
        }

        $perf = $perfQ->get()->keyBy('id');

        return $products->map(function ($p) use ($perf, $isAdmin) {
            $r = $perf[$p->id] ?? null;
            $out = [
                'id'       => $p->id,
                'brand'    => $p->brand,
                'model'    => $p->model,
                'barcode'  => $p->barcode,
                'qty_sold' => (int) ($r->qty_sold ?? 0),
                'revenue'  => round((float) ($r->revenue ?? 0), 2),
            ];
            if ($isAdmin) {
                $out['profit'] = round((float) ($r->profit ?? 0), 2);
            }
            return $out;
        })->all();
    }

    private function productPerformance($shopId, $from, $to, $limit, $sort, bool $isAdmin = true): array
    {
        $dir = $sort === 'worst' ? 'asc' : 'desc';

        $posQ = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sales_id')
            ->join('products', 'products.id', '=', 'sale_items.products_id')
            ->where('sales.shop_id', $shopId)
            ->where('sale_items.status', 'sold')
            ->when($from, fn($q) => $q->where('sales.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->where('sales.created_at', '<=', $to))
            ->groupBy('products.id', 'products.brand', 'products.model', 'products.barcode')
            ->select('products.id', 'products.brand', 'products.model', 'products.barcode')
            ->selectRaw('SUM(sale_items.qyt) as qty_sold')
            ->selectRaw('SUM(sale_items.qyt * sale_items.price) as revenue');

        if ($isAdmin) {
            // ✅ Decants: price × qty. Normal: (price − buy_price) × qty.
            $posQ->selectRaw('SUM(CASE WHEN sale_items.is_tester = 1 THEN sale_items.qyt * sale_items.price ELSE sale_items.qyt * (sale_items.price - products.buy_price) END) as profit');
        }

        $pos = $posQ->get();

        $list = $pos->map(function ($p) use ($isAdmin) {
            $row = [
                'id'       => $p->id,
                'brand'    => $p->brand,
                'model'    => $p->model,
                'barcode'  => $p->barcode,
                'qty_sold' => (int) $p->qty_sold,
                'revenue'  => (float) $p->revenue,
            ];
            if ($isAdmin) {
                $row['profit'] = (float) $p->profit;
            }
            return $row;
        })->all();

        if (empty($list)) {
            return DB::table('products')
                ->where('shop_id', $shopId)
                ->select('id', 'brand', 'model', 'barcode')
                ->limit($limit)
                ->get()
                ->map(function ($p) use ($isAdmin) {
                    $row = [
                        'id' => $p->id, 'brand' => $p->brand, 'model' => $p->model, 'barcode' => $p->barcode,
                        'qty_sold' => 0, 'revenue' => 0,
                    ];
                    if ($isAdmin) $row['profit'] = 0;
                    return $row;
                })
                ->toArray();
        }

        usort($list, fn($a, $b) => $dir === 'asc'
            ? $a['qty_sold'] <=> $b['qty_sold']
            : $b['qty_sold'] <=> $a['qty_sold']
        );

        if ($sort === 'worst') {
            $existing = array_column($list, 'id');

            $noSales = DB::table('products')
                ->where('shop_id', $shopId)
                ->whereNotIn('id', $existing)
                ->select('id', 'brand', 'model', 'barcode')
                ->limit($limit)
                ->get()
                ->map(function ($p) use ($isAdmin) {
                    $row = [
                        'id' => $p->id, 'brand' => $p->brand, 'model' => $p->model, 'barcode' => $p->barcode,
                        'qty_sold' => 0, 'revenue' => 0,
                    ];
                    if ($isAdmin) $row['profit'] = 0;
                    return $row;
                })
                ->toArray();

            $list = array_merge($noSales, $list);
        }

        return array_slice($list, 0, $limit);
    }

    private function topCustomers($shopId, $from, $to, $limit): array
    {
        $pos = DB::table('sales')
            ->where('shop_id', $shopId)
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->when($from, fn($q) => $q->where('created_at', '>=', $from))
            ->when($to,   fn($q) => $q->where('created_at', '<=', $to))
            ->groupBy('phone')
            ->selectRaw('phone, MAX(name) as name, COUNT(*) as orders, SUM(total) as spent')
            ->get();

        return $pos->map(function ($c) {
            $spent = (float) $c->spent;
            return [
                'phone'     => $c->phone,
                'name'      => $c->name,
                'orders'    => (int) $c->orders,
                'spent'     => round($spent, 2),
                'avg_order' => $c->orders > 0 ? round($spent / $c->orders, 2) : 0,
            ];
        })->sortByDesc('spent')->take($limit)->values()->all();
    }

    private function recentSales($shopId, $limit): array
    {
        return sales::where('shop_id', $shopId)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn($s) => [
                'id'         => $s->id,
                'type'       => 'pos',
                'total'      => (float) $s->total,
                'name'       => $s->name,
                'phone'      => $s->phone,
                'created_at' => $s->created_at,
            ])
            ->values()
            ->all();
    }
}