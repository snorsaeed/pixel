<?php

namespace App\Http\Controllers;

use App\Models\products;
use App\Models\sales;
use App\Models\sale_items;
use App\Models\ShopSetting;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class saleController extends Controller
{
    // ═══════════════════════════════════════════════════════════
    // GET /sales — sold + pending items, profit per sale + per item
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $shopId = $request->user()->shop_id;

        $query = sales::where('shop_id', $shopId)
            ->whereHas('sale_items', function ($q) {
                $q->whereIn('status', ['sold', 'pending']);
            })
            ->with(['sale_items' => function ($q) {
                $q->whereIn('status', ['sold', 'pending'])->with('products');
            }]);

        $this->applyDateFilter($query, $request);
        $this->applySearch($query, $request);

        $sales = $query->latest()->get();

        $sales->each(function ($sale) {
            $sale->iqd_rate = $sale->total > 0
                ? round($sale->iqd_total / $sale->total, 6)
                : 0;

            // 4 decimals — matches DB decimal(12,4) columns so 350000 IQD
            // survives the round-trip without drifting to 350001.
            $sale->amount_paid = round((float) $sale->sale_items->sum('amount_paid'), 4);
            $sale->amount_due  = round((float) $sale->sale_items->sum('amount_due'), 4);

            $sale->sale_items->each(function ($item) {
                if ($item->is_tester) {
                    $item->unit_profit  = 0;
                    $item->line_profit  = 0;
                    $item->line_revenue = 0;
                    return;
                }
                $buy  = (float) ($item->products->buy_price ?? 0);
                $sell = (float) $item->price;
                $qty  = (int) $item->qyt;

                $item->unit_profit  = round($sell - $buy, 4);
                $item->line_profit  = round(($sell - $buy) * $qty, 4);
                $item->line_revenue = round($sell * $qty, 4);
            });

            $sale->total_profit = round(
                (float) $sale->sale_items
                    ->reject(fn($i) => $i->is_tester)
                    ->sum('line_profit'),
                4
            );
        });

        return $sales;
    }

    // ═══════════════════════════════════════════════════════════
    // POST /sales
    // ═══════════════════════════════════════════════════════════
    public function sale(Request $request)
    {
        $data = $request->validate([
            'name'        => 'nullable',
            'phone'       => 'nullable',
            'discount'    => 'nullable|numeric|min:0',
            'is_debt'     => 'nullable|boolean',
            'items'       => 'required|array',
            'items.*.products_id' => 'nullable|integer',
            'items.*.quantity'    => 'required|integer|min:1',
            'items.*.barcode'     => 'required',
            'items.*.price'       => 'nullable|numeric|min:0',
            'items.*.is_tester'   => 'nullable|boolean',
        ]);

        $shopId = $request->user()->shop_id;

        DB::beginTransaction();

        try {
            $subtotalRaw = 0;
            $resolved    = [];

            foreach ($data['items'] as $item) {
                // Prefer an explicit products_id (sent by the POS picker) so
                // duplicate barcodes resolve to the exact product the cashier chose.
                $p = isset($item['products_id'])
                    ? products::where('shop_id', $shopId)->findOrFail($item['products_id'])
                    : products::where('shop_id', $shopId)
                        ->where(function ($q) use ($item) {
                            $q->where('barcode', $item['barcode'])
                              ->orWhere('model', $item['barcode']);
                        })
                        ->firstOrFail();

                $isTester = !empty($item['is_tester']);
                $qty      = (int) $item['quantity'];

                if (!$isTester && $p->qyt < $qty) {
                    throw new Exception('Not enough stock for ' . $p->model);
                }

                $unit = (float) ($item['price'] ?? $p->sale_price);
                $line = $unit * $qty;
                $subtotalRaw += $line;

                $resolved[] = [
                    'product'   => $p,
                    'is_tester' => $isTester,
                    'qty'       => $qty,
                    'unit'      => $unit,
                    'line'      => $line,
                ];
            }

            $discount = (float) ($data['discount'] ?? 0);
            $total    = round($subtotalRaw - ($subtotalRaw * $discount / 100), 4);

            $setting  = ShopSetting::where('shop_id', $shopId)->first();
            $iqdRate  = $setting?->iqd_rate ?? 0;
            $iqdTotal = $iqdRate > 0
                ? round(($total * $iqdRate) / 250) * 250
                : 0;

            $isDebt = !empty($data['is_debt']);

            $sale = sales::create([
                'shop_id'   => $shopId,
                'total'     => $total,
                'iqd_total' => $iqdTotal,
                'discount'  => $discount,
                'name'      => $data['name'] ?? null,
                'phone'     => $data['phone'] ?? null,
            ]);

            foreach ($resolved as $r) {
                $p        = $r['product'];
                $isTester = $r['is_tester'];
                $qty      = $r['qty'];
                $unit     = $r['unit'];
                $line     = $r['line'];

                $lineAfterDiscount = $discount > 0
                    ? round($line - ($line * $discount / 100), 4)
                    : $line;

                if ($isTester) {
                    $status = 'sold';
                    $paid   = 0;
                    $due    = 0;
                } elseif ($isDebt) {
                    $status = 'pending';
                    $paid   = 0;
                    $due    = $lineAfterDiscount;
                } else {
                    $status = 'sold';
                    $paid   = $lineAfterDiscount;
                    $due    = 0;
                }

                sale_items::create([
                    'sales_id'          => $sale->id,
                    'products_id'       => $p->id,
                    'price'             => $unit,
                    'qyt'               => $qty,
                    'status'            => $status,
                    'is_tester'         => $isTester,
                    'amount_paid'       => round($paid, 4),
                    'amount_due'        => round($due, 4),
                ]);

                if (!$isTester) {
                    $p->qyt -= $qty;
                    $p->save();

                    if ($w = $p->webproduct) {
                        $w->stock = $p->qyt;
                        $w->save();
                    }
                }
            }

            DB::commit();

            $sale->iqd_rate = $iqdRate;

            return response()->json([
                'message' => $isDebt ? 'Sale created (debt)' : 'Sale created',
                'sale'    => $sale->load('sale_items.products'),
                'shop'    => [
                    'id'        => $request->user()->shop->id,
                    'shop_name' => $request->user()->shop->shop_name,
                    'phone'     => $request->user()->shop->phone,
                ],
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Sale failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // POST /sales/returns
    // ═══════════════════════════════════════════════════════════
    public function returns(Request $request)
    {
        $data = $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'items' => 'required|array',
            'items.*.products_id' => 'nullable|integer',
            'items.*.barcode'     => 'required',
            'items.*.quantity'    => 'required|integer|min:1',
            'items.*.is_tester'   => 'required|boolean',
        ]);

        $shopId = $request->user()->shop_id;

        DB::beginTransaction();

        try {
            $sale = sales::where('shop_id', $shopId)->findOrFail($data['sale_id']);

            foreach ($data['items'] as $item) {
                // Prefer products_id when supplied; fall back to barcode/model lookup.
                $p = isset($item['products_id'])
                    ? products::where('shop_id', $shopId)->findOrFail($item['products_id'])
                    : products::where('shop_id', $shopId)
                        ->where(function ($q) use ($item) {
                            $q->where('barcode', $item['barcode'])
                              ->orWhere('model', $item['barcode']);
                        })
                        ->firstOrFail();

                $wantsTester = filter_var($item['is_tester'], FILTER_VALIDATE_BOOLEAN);

                $si = sale_items::where('sales_id', $sale->id)
                    ->where('products_id', $p->id)
                    ->where('status', 'sold')
                    ->where('is_tester', $wantsTester)
                    ->first();

                if (!$si) {
                    throw new Exception(
                        $wantsTester
                            ? 'No decant of ' . $p->model . ' found in this sale'
                            : 'No normal sale of ' . $p->model . ' found in this sale'
                    );
                }

                if ($item['quantity'] > $si->qyt) {
                    throw new Exception('Cannot return more than sold');
                }

                if (!$si->is_tester) {
                    $p->qyt += $item['quantity'];
                    $p->save();

                    if ($w = $p->webproduct) {
                        $w->stock = $p->qyt;
                        $w->save();
                    }
                }

                $price    = $si->price;
                $isTester = (bool) $si->is_tester;

                $si->qyt -= $item['quantity'];
                if ($si->qyt <= 0) {
                    $si->delete();
                } else {
                    $si->save();
                }

                sale_items::create([
                    'sales_id'          => $sale->id,
                    'products_id'       => $p->id,
                    'price'             => $price,
                    'qyt'               => $item['quantity'],
                    'status'            => 'returned',
                    'is_tester'         => $isTester,
                    'amount_paid'       => 0,
                    'amount_due'        => 0,
                ]);
            }

            $remaining = sale_items::where('sales_id', $sale->id)
                ->whereIn('status', ['sold', 'pending'])
                ->get();

            if ($remaining->count() === 0) {
                $sale->total     = 0;
                $sale->iqd_total = 0;
                $sale->save();

                DB::commit();

                return response()->json([
                    'message'       => 'All items returned.',
                    'new_total'     => 0,
                    'new_iqd_total' => 0,
                ]);
            }

            $subtotal = $remaining->sum(fn ($i) => $i->qyt * $i->price);
            $discount = $sale->discount ?? 0;
            $newTotal = round($subtotal - ($subtotal * $discount / 100), 4);

            $oldTotal    = (float) $sale->total;
            $oldIqd      = (float) $sale->iqd_total;
            $newIqdTotal = $oldTotal > 0
                ? round(($oldIqd * ($newTotal / $oldTotal)) / 250) * 250
                : 0;

            $sale->total     = $newTotal;
            $sale->iqd_total = $newIqdTotal;
            $sale->save();

            DB::commit();

            return response()->json([
                'message'       => 'Returned',
                'new_total'     => $newTotal,
                'new_iqd_total' => $newIqdTotal,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Return failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // GET /returns
    // ═══════════════════════════════════════════════════════════
    public function returnsHistory(Request $request)
    {
        $shopId = $request->user()->shop_id;

        $query = sales::where('shop_id', $shopId)
            ->whereHas('sale_items', function ($q) {
                $q->where('status', 'returned');
            })
            ->with(['sale_items' => function ($q) {
                $q->with('products');
            }]);

        $this->applyDateFilter($query, $request);
        $this->applySearch($query, $request);

        $sales = $query->latest()->get();

        $sales->each(function ($sale) {
            $sale->iqd_rate = $sale->total > 0
                ? round($sale->iqd_total / $sale->total, 6)
                : 0;
        });

        return $sales;
    }

    // ═══════════════════════════════════════════════════════════
    // GET /debts
    // ═══════════════════════════════════════════════════════════
    public function debts(Request $request)
    {
        $shopId = $request->user()->shop_id;

        $query = sales::where('shop_id', $shopId)
            ->whereHas('sale_items', fn($q) => $q->where('status', 'pending'))
            ->with(['sale_items' => function ($q) {
                $q->whereIn('status', ['sold', 'pending'])
                  ->where('qyt', '>', 0)
                  ->with('products');
            }]);

        $this->applyDateFilter($query, $request);
        $this->applySearch($query, $request);

        $sales = $query->latest()->get();

        return $sales->map(function ($sale) {
            $sale->amount_due  = round((float) $sale->sale_items->sum('amount_due'), 4);
            $sale->amount_paid = round((float) $sale->sale_items->sum('amount_paid'), 4);
            $sale->iqd_rate    = $sale->total > 0
                ? round($sale->iqd_total / $sale->total, 6)
                : 0;
            return $sale;
        })->filter(fn($s) => $s->amount_due > 0)->values();
    }

    // ═══════════════════════════════════════════════════════════
    // GET /debts/summary
    // ═══════════════════════════════════════════════════════════
    public function debtsSummary(Request $request)
    {
        $shopId = $request->user()->shop_id;

        $query = sales::where('shop_id', $shopId)
            ->whereHas('sale_items', fn($q) => $q->where('status', 'pending'))
            ->with(['sale_items' => fn($q) => $q->where('status', 'pending')]);

        $this->applyDateFilter($query, $request);
        $this->applySearch($query, $request);

        $sales = $query->get();

        $count    = 0;
        $totalDue = 0.0;
        $totalVal = 0.0;

        foreach ($sales as $sale) {
            $pending = (float) $sale->sale_items->sum('amount_due');
            if ($pending > 0) {
                $count++;
                $totalDue += $pending;
                $totalVal += (float) $sale->total;
            }
        }

        return response()->json([
            'count'       => $count,
            'total_due'   => round($totalDue, 4),
            'total_sales' => round($totalVal, 4),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // POST /sales/{id}/pay
    // ═══════════════════════════════════════════════════════════
    public function pay(Request $request, $id)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $shopId = $request->user()->shop_id;

        DB::beginTransaction();
        try {
            $sale = sales::where('shop_id', $shopId)->lockForUpdate()->findOrFail($id);

            $pending = sale_items::where('sales_id', $sale->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $due = (float) $pending->sum('amount_due');

            if ($due <= 0.001) {
                throw new Exception('This sale is already fully paid');
            }
            if ((float) $data['amount'] > $due + 0.001) {
                throw new Exception('Amount exceeds remaining debt');
            }

            $cash = (float) $data['amount'];

            foreach ($pending as $item) {
                if ($cash <= 0.001) break;

                $itemDue = (float) $item->amount_due;

                if ($cash + 0.001 >= $itemDue) {
                    $item->amount_paid = round((float) $item->amount_paid + $itemDue, 4);
                    $item->amount_due  = 0;
                    $item->status      = 'sold';
                    $item->save();
                    $cash -= $itemDue;
                } else {
                    $item->amount_paid = round((float) $item->amount_paid + $cash, 4);
                    $item->amount_due  = round($itemDue - $cash, 4);
                    $item->save();
                    $cash = 0;
                }
            }

            DB::commit();

            $fresh = $sale->fresh()->load('sale_items.products');
            $newDue  = round((float) $fresh->sale_items->sum('amount_due'), 4);
            $newPaid = round((float) $fresh->sale_items->sum('amount_paid'), 4);

            $fresh->amount_due  = $newDue;
            $fresh->amount_paid = $newPaid;

            return response()->json([
                'message'    => $newDue <= 0.001 ? 'Debt fully paid' : 'Payment recorded',
                'sale'       => $fresh,
                'amount_due' => $newDue,
                'fully_paid' => $newDue <= 0.001,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Payment failed',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // POST /sales/{id}/close
    //
    // On close:
    //   amount_paid = amount_paid (before) + amount collected now
    //   price       = amount_paid / qty   (= amount_paid when qty = 1)
    //   amount_due  = 0
    //   status      = sold
    //
    // Example: paid 165 → close with 30 → price = 195, amount_paid = 195
    // ═══════════════════════════════════════════════════════════
    public function closeDeal(Request $request, $id)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0',
        ]);

        $settleAmount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        $shopId       = $request->user()->shop_id;

        DB::beginTransaction();
        try {
            $sale = sales::where('shop_id', $shopId)->lockForUpdate()->findOrFail($id);

            $pending = sale_items::where('sales_id', $sale->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($pending->isEmpty()) {
                throw new Exception('This sale has no pending items');
            }

            $due = (float) $pending->sum('amount_due');

            if ($settleAmount > $due + 0.001) {
                throw new Exception('Amount exceeds remaining debt');
            }

            $cash = $settleAmount;

            foreach ($pending as $item) {
                $itemDue  = (float) $item->amount_due;
                $paidPrev = (float) $item->amount_paid;
                $qty      = max(1, (int) $item->qyt);

                $collectNow = min($cash, $itemDue);
                $cash      -= $collectNow;

                $item->amount_paid = round($paidPrev + $collectNow, 4);  // 165 + 30 = 195
                // 4 decimals so price × qty keeps exact amount_paid
                $item->price       = round($item->amount_paid / $qty, 4);
                $item->amount_due  = 0;
                $item->status      = 'sold';
                $item->save();
            }

            $sold = sale_items::where('sales_id', $sale->id)
                ->where('status', 'sold')
                ->get();

            $subtotal = $sold->sum(fn($i) => (float) $i->price * (int) $i->qyt);
            $discount = (float) ($sale->discount ?? 0);
            $newTotal = round($subtotal - ($subtotal * $discount / 100), 4);

            $oldTotal = (float) $sale->total;
            $oldIqd   = (float) $sale->iqd_total;
            $newIqd   = $oldTotal > 0
                ? round(($oldIqd * ($newTotal / $oldTotal)) / 250) * 250
                : 0;

            $sale->total     = $newTotal;
            $sale->iqd_total = $newIqd;
            $sale->save();

            DB::commit();

            $fresh = $sale->fresh()->load('sale_items.products');
            $newDue  = round((float) $fresh->sale_items->sum('amount_due'), 4);
            $newPaid = round((float) $fresh->sale_items->sum('amount_paid'), 4);

            $fresh->amount_due  = $newDue;
            $fresh->amount_paid = $newPaid;

            return response()->json([
                'message'           => 'Sale settled',
                'sale'              => $fresh,
                'amount_due'        => $newDue,
                'amount_paid'       => $newPaid,
                'fully_paid'        => $newDue <= 0.001,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Close failed',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════
    private function applySearch($query, Request $request)
    {
        $search = trim((string) $request->input('search', $request->input('q', '')));
        if ($search === '') return $query;

        $query->where(function ($outer) use ($search) {
            $outer->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('id', 'like', "%{$search}%")
                  ->orWhereHas('sale_items', function ($q) use ($search) {
                      $q->whereHas('products', function ($p) use ($search) {
                          $p->where('model', 'like', "%{$search}%")
                            ->orWhere('brand', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                      });
                  });
        });

        return $query;
    }

    private function applyDateFilter($query, Request $request)
    {
        $filter = strtolower((string) $request->input('filter', $request->input('period', '')));

        if (!$filter || $filter === 'all') return $query;

        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::now();

        switch ($filter) {
            case 'daily':
                $query->whereDate('created_at', $date->toDateString());
                break;
            case 'weekly':
                $query->whereBetween('created_at', [
                    $date->copy()->startOfWeek(),
                    $date->copy()->endOfWeek(),
                ]);
                break;
            case 'monthly':
                $query->whereMonth('created_at', $date->month)
                      ->whereYear('created_at', $date->year);
                break;
            case 'yearly':
                $query->whereYear('created_at', $date->year);
                break;
            case 'custom':
                if (!$request->filled('start_date') || !$request->filled('end_date')) {
                    return $query;
                }
                $validated = $request->validate([
                    'start_date' => 'required|date',
                    'end_date'   => 'required|date|after_or_equal:start_date',
                ]);
                $query->whereBetween('created_at', [
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->endOfDay(),
                ]);
                break;
            default:
                break;
        }

        return $query;
    }
}