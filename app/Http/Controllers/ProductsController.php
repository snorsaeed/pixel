<?php

namespace App\Http\Controllers;

use App\Models\products;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProductsController extends Controller
{
 public function index(Request $request)
{
    $shopId = $request->user()->shop_id;

    // POS asks for per_page=500; allow it, cap only for safety
    $perPage = (int) $request->input('per_page', 50);
    $perPage = max(1, min($perPage, 1000));

    $query = products::where('shop_id', $shopId)->orderBy('id');

    $search = trim((string) $request->input('search', ''));
    if ($search !== '') {
        $query->where(function ($w) use ($search) {
            $w->where('brand',   'like', "%{$search}%")
              ->orWhere('model',  'like', "%{$search}%")
              ->orWhere('barcode','like', "%{$search}%");
        });
    }

    return $query->paginate($perPage);
}

    // POST /products
    public function store(Request $request)
    {
        $data = $request->validate([
            'brand' => 'required|string',
            'model' => 'required|string',
            'barcode' => 'required|string',
            'buy_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0',
            'qyt' => 'required|integer|min:0',
            'img' => 'nullable|string',
            'disc' => 'nullable|string',
            'gender' => 'nullable|string',
            'size' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $product = products::create([
            'brand' => $data['brand'],
            'model' => $data['model'],
            'barcode' => $data['barcode'],
            'buy_price' => $data['buy_price'],
            'sale_price' => $data['sale_price'],
            'qyt' => $data['qyt'],
            'shop_id' => $request->user()->shop_id,
        ]);

        $product->webproduct()->create([
            'shop_id' => $request->user()->shop_id,
            'img' => $data['img'] ?? null,
            'brand' => $product->brand,
            'model' => $product->model,
            'barcode' => $product->barcode,
            'price' => $product->sale_price,
            'stock' => $product->qyt,
            'disc' => $data['disc'] ?? null,
            'gender' => $data['gender'] ?? null,
            'size' => $data['size'] ?? null,
            'notes' => $data['notes'] ?? null,
            'discount' => $data['discount'] ?? 0,
        ]);

        $fresh = $product->load('webproduct');

        if (($request->user()->role ?? 'user') !== 'admin') {
            $fresh->makeHidden('buy_price');
        }

        return response()->json([
            'msg' => 'Product created successfully',
            'product' => $fresh,
        ], 201);
    }

    // GET /products/{id}
    public function show(Request $request, $id)
    {
        $product = products::with('webproduct')
            ->where('shop_id', $request->user()->shop_id)
            ->findOrFail($id);

        if (($request->user()->role ?? 'user') !== 'admin') {
            $product->makeHidden('buy_price');
        }

        return response()->json($product);
    }

    // PUT /products/{id}
    public function update(Request $request, $id)
    {
        $product = products::where('shop_id', $request->user()->shop_id)
            ->findOrFail($id);

        $data = $request->validate([
            'brand' => 'sometimes|required|string',
            'model' => 'sometimes|required|string',
            'barcode' => 'sometimes|required|string',
            'buy_price' => 'sometimes|required|numeric|min:0',
            'sale_price' => 'sometimes|required|numeric|min:0',
            'qyt' => 'sometimes|required|integer|min:0',
            'img' => 'sometimes|nullable|string',
            'disc' => 'sometimes|nullable|string',
            'gender' => 'sometimes|nullable|string',
            'size' => 'sometimes|nullable|integer|min:1',
            'notes' => 'sometimes|nullable|string',
            'discount' => 'sometimes|nullable|numeric|min:0',
        ]);

        $product->update($data);

        if ($product->webproduct) {
            $product->webproduct->update([
                'brand' => $product->brand,
                'model' => $product->model,
                'barcode' => $product->barcode,
                'price' => $product->sale_price,
                'stock' => $product->qyt,
                'img' => $data['img'] ?? $product->webproduct->img,
                'disc' => $data['disc'] ?? $product->webproduct->disc,
                'gender' => $data['gender'] ?? $product->webproduct->gender,
                'size' => $data['size'] ?? $product->webproduct->size,
                'notes' => $data['notes'] ?? $product->webproduct->notes,
                'discount' => $data['discount'] ?? $product->webproduct->discount,
            ]);
        }

        $fresh = $product->fresh()->load('webproduct');

        if (($request->user()->role ?? 'user') !== 'admin') {
            $fresh->makeHidden('buy_price');
        }

        return response()->json([
            'msg' => 'Product updated successfully',
            'product' => $fresh,
        ]);
    }

    // DELETE /products/{id}
    public function destroy(Request $request, $id)
    {
        products::where('shop_id', $request->user()->shop_id)
            ->findOrFail($id)
            ->delete();

        return response()->json([
            'msg' => 'Product deleted successfully',
        ]);
    }

    // GET /inventory
    public function inventory(Request $request)
    {
        $shopId  = $request->user()->shop_id;
        $isAdmin = ($request->user()->role ?? 'user') === 'admin';

        // Apply daily / weekly / monthly / yearly / custom filter
        $query = products::where('shop_id', $shopId);
        $this->applyInventoryDateFilter($query, $request);

        $all = $query->orderByDesc('id')->get();

        $summary = [
            'total_products' => $all->count(),
            'total_stock'    => (int) $all->sum('qyt'),
            'retail_value'   => round($all->sum(fn ($p) => $p->qyt * $p->sale_price), 2),
            'out_of_stock'   => $all->where('qyt', '<=', 0)->count(),
            'low_stock'      => $all->where('qyt', '>', 0)->where('qyt', '<=', 5)->count(),
            'healthy'        => $all->where('qyt', '>', 5)->count(),
        ];

        if ($isAdmin) {
            $stockValue = (float) $all->sum(fn ($p) => $p->qyt * $p->buy_price);
            $summary['stock_value']      = round($stockValue, 2);
            $summary['potential_profit'] = round($summary['retail_value'] - $stockValue, 2);
        }

        return response()->json([
            'summary'      => $summary,
            'all_products' => $all->map(fn ($p) => $this->productSummary($p, $isAdmin)),
        ]);
    }

    // POST /products/{id}/stock
    public function adjustStock(Request $request, $id)
    {
        $data = $request->validate([
            'qyt' => 'required|integer|min:0',
        ]);

        $product = products::where('shop_id', $request->user()->shop_id)->findOrFail($id);
        $product->qyt = $data['qyt'];
        $product->save();

        if ($wp = $product->webproduct) {
            $wp->stock = $product->qyt;
            $wp->save();
        }

        $isAdmin = ($request->user()->role ?? 'user') === 'admin';

        return response()->json([
            'msg'     => 'Stock updated',
            'product' => $this->productSummary($product->fresh(), $isAdmin),
        ]);
    }

    /**
     * Filter inventory by products.created_at.
     * Params:
     *   - filter / period: daily | weekly | monthly | yearly | custom
     *   - date: reference date (defaults to now) — daily/weekly/monthly/yearly
     *   - start_date & end_date: required for custom
     */
    private function applyInventoryDateFilter($query, Request $request)
    {
        $filter = strtolower($request->input('filter', $request->input('period', '')));

        if (!$filter || $filter === 'all') {
            return $query;
        }

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
                // Unknown filter — ignore silently
                break;
        }

        return $query;
    }

    /**
     * Shared product shape for inventory / stock responses.
     * Only includes buy_price + stock_value when $showBuyPrice = true (admin).
     */
    private function productSummary($p, bool $showBuyPrice = false): array
    {
        $data = [
            'id'           => $p->id,
            'brand'        => $p->brand,
            'model'        => $p->model,
            'barcode'      => $p->barcode,
            'qyt'          => (int) $p->qyt,
            'sale_price'   => (float) $p->sale_price,
            'created_at'   => $p->created_at,
            'img'          => $p->webproduct?->img,
            'gender'       => $p->webproduct?->gender,
            'size'         => $p->webproduct?->size,
            'disc'         => $p->webproduct?->disc,
            'notes'        => $p->webproduct?->notes,
            'discount'     => $p->webproduct?->discount ?? 0,
            'retail_value' => round($p->qyt * $p->sale_price, 2),
        ];

        if ($showBuyPrice) {
            $data['buy_price']   = (float) $p->buy_price;
            $data['stock_value'] = round($p->qyt * $p->buy_price, 2);
        }

        return $data;
    }
}