<?php

namespace App\Http\Controllers;

use App\Models\sales;
use App\Models\websales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    // GET /customers
    public function index(Request $request)
    {
        $shopId = $request->user()->shop_id;

        // ── POS rows: keep if phone OR name is present ──
        $pos = DB::table('sales')
            ->where('shop_id', $shopId)
            ->where(function ($q) {
                $q->where(function ($x) {
                    $x->whereNotNull('phone')->where('phone', '!=', '');
                })->orWhere(function ($x) {
                    $x->whereNotNull('name')->where('name', '!=', '');
                });
            })
            ->get(['phone', 'name', 'total', 'created_at']);

        // ── Web rows: keep if code OR name is present ──
        $web = DB::table('websales')
            ->where('shop_id', $shopId)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) {
                $q->where(function ($x) {
                    $x->whereNotNull('code')->where('code', '!=', '');
                })->orWhere(function ($x) {
                    $x->whereNotNull('name')->where('name', '!=', '');
                });
            })
            ->get(['code', 'name', 'total', 'created_at']);

        $merged = [];

        foreach ($pos as $r) {
            $key = $this->customerKey($r->phone, $r->name);
            if ($key === null) continue;
            $this->merge($merged, $key, $r->phone, $r->name, 1, (float) $r->total, $r->created_at);
        }

        foreach ($web as $r) {
            $key = $this->customerKey($r->code, $r->name);
            if ($key === null) continue;
            $this->merge($merged, $key, $r->code, $r->name, 1, (float) $r->total, $r->created_at);
        }

        $list = array_values($merged);

        // ── Search by name or phone (null-safe) ──
        if ($search = $request->query('search')) {
            $s = strtolower($search);
            $list = array_values(array_filter($list, fn ($c) =>
                str_contains(strtolower($c['name']  ?? ''), $s) ||
                str_contains(strtolower($c['phone'] ?? ''), $s)
            ));
        }

        usort($list, fn ($a, $b) => $b['spent'] <=> $a['spent']);

        foreach ($list as &$c) {
            $c['spent']     = round($c['spent'], 2);
            $c['avg_order'] = $c['orders'] > 0 ? round($c['spent'] / $c['orders'], 2) : 0;
            $c['key']       = $this->customerKey($c['phone'], $c['name']);
        }
        unset($c);

        return response()->json([
            'count'         => count($list),
            'total_revenue' => round(array_sum(array_column($list, 'spent')), 2),
            'customers'     => $list,
        ]);
    }

    // GET /customers/{key}
    // `key` is the value returned in the list: "p:0770..." (phone) or "n:ali" (name)
    public function show(Request $request, $key)
    {
        $shopId = $request->user()->shop_id;

        // Decode the composite key
        $phone = null;
        $name  = null;

        if (str_starts_with($key, 'p:')) {
            $phone = substr($key, 2);
        } elseif (str_starts_with($key, 'n:')) {
            $name = substr($key, 2);
        } else {
            // Fallback — treat as a raw phone for backward compatibility
            $phone = $key;
        }

        // ── POS ──
        $posQ = sales::where('shop_id', $shopId);

        if ($phone !== null) {
            $posQ->where('phone', $phone);
        } else {
            // Name-only lookup: name matches AND phone is empty
            $posQ->where(function ($q) use ($name) {
                $q->where(function ($x) {
                    $x->whereNull('phone')->orWhere('phone', '=', '');
                })->where('name', $name);
            });
        }

        $posOrders = $posQ
            ->with(['sale_items' => fn ($q) => $q->where('status', 'sold')->with('products')])
            ->latest()
            ->get();

        // ── Web ──
        $webQ = websales::where('shop_id', $shopId);

        if ($phone !== null) {
            $webQ->where('code', $phone);
        } else {
            $webQ->where(function ($q) use ($name) {
                $q->where(function ($x) {
                    $x->whereNull('code')->orWhere('code', '=', '');
                })->where('name', $name);
            });
        }

        $webOrders = $webQ
            ->with('items.product')
            ->latest()
            ->get();

        // ── Merge into a single timeline ──
        $orders = collect()
            ->merge($posOrders->map(fn ($o) => [
                'id'         => $o->id,
                'type'       => 'pos',
                'total'      => (float) $o->total,
                'name'       => $o->name,
                'created_at' => $o->created_at,
                'items'      => $o->sale_items->map(fn ($i) => [
                    'brand' => $i->products->brand ?? null,
                    'model' => $i->products->model ?? null,
                    'qty'   => $i->qyt,
                    'price' => (float) $i->price,
                ]),
            ]))
            ->merge($webOrders->map(fn ($o) => [
                'id'         => $o->id,
                'type'       => 'web',
                'total'      => (float) $o->total,
                'name'       => $o->name,
                'created_at' => $o->created_at,
                'items'      => $o->items->map(fn ($i) => [
                    'brand' => $i->product->brand ?? null,
                    'model' => $i->product->model ?? null,
                    'qty'   => $i->qyt,
                    'price' => (float) $i->price,
                ]),
            ]))
            ->sortByDesc('created_at')
            ->values();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        // Prefer real values, fall back to whatever we have
        $displayPhone = $phone ?? $orders->firstWhere('phone', '!=', null)['phone'] ?? null;
        $displayName  = $name  ?? $orders->first()['name'] ?? null;

        return response()->json([
            'customer' => [
                'key'         => $this->customerKey($displayPhone, $displayName),
                'phone'       => $displayPhone,
                'name'        => $displayName,
                'orders'      => $orders->count(),
                'total_spent' => round($orders->sum('total'), 2),
                'avg_order'   => round($orders->sum('total') / $orders->count(), 2),
                'first_order' => $orders->last()['created_at'],
                'last_order'  => $orders->first()['created_at'],
            ],
            'orders' => $orders,
        ]);
    }

    /**
     * Build a unique composite key for a customer.
     * Prefers phone; falls back to name; returns null if BOTH are empty.
     */
    private function customerKey($phone, $name): ?string
    {
        $p = trim((string) $phone);
        $n = trim((string) $name);

        if ($p !== '') return 'p:' . $p;
        if ($n !== '') return 'n:' . strtolower($n);

        return null; // no phone AND no name → skip
    }

    /**
     * Merge a row into the accumulator.
     */
    private function merge(&$arr, $key, $phone, $name, $orders, $spent, $last): void
    {
        if (! isset($arr[$key])) {
            $arr[$key] = [
                'key'        => $key,
                'phone'      => $phone ?: null,
                'name'       => $name  ?: null,
                'orders'     => $orders,
                'spent'      => $spent,
                'last_order' => $last,
            ];
            return;
        }

        $arr[$key]['orders'] += $orders;
        $arr[$key]['spent']  += $spent;

        if ($last > $arr[$key]['last_order']) {
            $arr[$key]['last_order'] = $last;
        }

        // Fill in whichever field was missing
        if (! $arr[$key]['name']  && $name)  $arr[$key]['name']  = $name;
        if (! $arr[$key]['phone'] && $phone) $arr[$key]['phone'] = $phone;
    }
}