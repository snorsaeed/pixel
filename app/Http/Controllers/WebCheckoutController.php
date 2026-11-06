<?php

namespace App\Http\Controllers;

use App\Models\webproducts;
use App\Models\websales;
use App\Models\webitems;
use App\Models\ShopSetting;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebCheckoutController extends Controller
{
    // ═══════════════════════════════════════════════════════
    //  STOREFRONT — public checkout
    // ═══════════════════════════════════════════════════════

    public function index(Request $request)
    {
        return websales::where('shop_id', $request->user()->shop_id)
            ->with(['items.product'])
            ->latest()
            ->get();
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'shop_id'        => 'required|exists:shops,id',
            'name'           => 'required|string',
            'city'           => 'required|string',
            'address'        => 'required|string',
            'code'           => 'nullable|string',
            'discount_code'  => 'nullable|string',
            'delivery_price' => 'nullable|numeric|min:0',
            'items'          => 'required|array|min:1',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.barcode'  => 'required',
            'items.*.price'    => 'nullable|numeric|min:0',
        ]);

        $shopId = $data['shop_id'];

        DB::beginTransaction();

        try {
            $setting = ShopSetting::where('shop_id', $shopId)->first();

            $subtotal = 0;
            $lines = [];

            foreach ($data['items'] as $item) {
                $p = webproducts::where('shop_id', $shopId)
                    ->where(function ($q) use ($item) {
                        $q->where('barcode', $item['barcode'])
                          ->orWhere('model', $item['barcode']);
                    })
                    ->firstOrFail();

                if ($p->stock < $item['quantity']) {
                    throw new Exception('Not enough stock for ' . $p->model);
                }

                // Base price: ignore client-supplied price unless explicitly
                // allowed and within a sane range.
                $basePrice = (float) $p->price;

                if (isset($item['price']) && $item['price'] !== null) {
                    $client = (float) $item['price'];
                    // Only honour a client price if it's > 0 and not above the
                    // stored price (prevents selling below/above the shop price).
                    if ($client > 0 && $client <= $basePrice) {
                        $basePrice = $client;
                    }
                }

                // Apply the product-level discount (0–100 %)
                $price = $this->applyProductDiscount($p, $basePrice);

                $subtotal += $price * $item['quantity'];

                $lines[] = [
                    'product'      => $p,
                    'base_price'   => $basePrice,
                    'discount_pct' => (float) ($p->discount ?? 0),
                    'price'        => $price,   // discounted unit price
                    'quantity'     => $item['quantity'],
                ];
            }

            $discount = 0;
            $discountCodeUsed = null;

            if (! empty($data['discount_code'])) {
                if (! $setting || ! $setting->discount_code) {
                    throw new Exception('This shop has no discount codes');
                }
                if (strtoupper($data['discount_code']) !== strtoupper($setting->discount_code)) {
                    throw new Exception('Invalid discount code');
                }
                if ($subtotal < ($setting->discount_min_spend ?? 0)) {
                    throw new Exception(
                        'Minimum spend for this code is $' . number_format($setting->discount_min_spend, 2)
                    );
                }
                $discount = $setting->applyDiscount($data['discount_code'], $subtotal);
                if ($discount > 0) {
                    $discountCodeUsed = strtoupper($data['discount_code']);
                }
            }

            $deliveryPrice = $data['delivery_price'] ?? 0;
            $total = max(0, $subtotal - $discount) + $deliveryPrice;

            $iqdRate  = $setting?->iqd_rate ?? 0;
            $iqdTotal = $iqdRate > 0
                ? round(($total * $iqdRate) / 250) * 250
                : 0;

            $sale = websales::create([
                'shop_id'        => $shopId,
                'name'           => $data['name'],
                'city'           => $data['city'],
                'address'        => $data['address'],
                'code'           => $data['code'] ?? null,
                'status'         => 'pending',
                'total'          => $total,
                'delivery_price' => $deliveryPrice,
                'iqd_rate'       => $iqdRate,
                'iqd_total'      => $iqdTotal,
            ]);

            foreach ($lines as $line) {
                webitems::create([
                    'websales_id' => $sale->id,
                    'products_id' => $line['product']->id,
                    'price'       => $line['price'], // already discounted
                    'qyt'         => $line['quantity'],
                ]);

                $line['product']->stock -= $line['quantity'];
                $line['product']->save();
            }

            DB::commit();

            return response()->json([
                'message'         => 'Checkout complete',
                'discount_code'   => $discountCodeUsed,
                'discount_amount' => $discount,
                'lines'           => array_map(fn ($l) => [
                    'products_id'  => $l['product']->id,
                    'barcode'      => $l['product']->barcode,
                    'base_price'   => $l['base_price'],
                    'discount_pct' => $l['discount_pct'],
                    'unit_price'   => $l['price'],
                    'quantity'     => $l['quantity'],
                    'line_total'   => round($l['price'] * $l['quantity'], 2),
                ], $lines),
                'sale'            => $sale->load('items.product'),
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Checkout failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,confirmed,shipped,delivered,cancelled',
        ]);

        $sale = websales::where('shop_id', $request->user()->shop_id)->findOrFail($id);
        $sale->status = $data['status'];
        $sale->save();

        return response()->json([
            'message' => 'Status updated',
            'sale'    => $sale,
        ]);
    }

    public function returns(Request $request)
    {
        $data = $request->validate([
            'sale_id'          => 'required|exists:websales,id',
            'items'            => 'required|array',
            'items.*.barcode'  => 'required',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $shopId = $request->user()->shop_id;

        DB::beginTransaction();

        try {
            $sale = websales::where('shop_id', $shopId)->findOrFail($data['sale_id']);

            foreach ($data['items'] as $item) {
                $p = webproducts::where('shop_id', $shopId)
                    ->where(function ($q) use ($item) {
                        $q->where('barcode', $item['barcode'])
                          ->orWhere('model', $item['barcode']);
                    })
                    ->firstOrFail();

                $si = webitems::where('websales_id', $sale->id)
                    ->where('products_id', $p->id)
                    ->firstOrFail();

                if ($item['quantity'] > $si->qyt) {
                    throw new Exception('Cannot return more than sold');
                }

                $p->stock += $item['quantity'];
                $p->save();

                if ($item['quantity'] == $si->qyt) {
                    $si->delete();
                } else {
                    $si->qyt -= $item['quantity'];
                    $si->save();
                }
            }

            if ($sale->items()->count() === 0) {
                $sale->delete();
            }

            DB::commit();

            return response()->json(['message' => 'Returned']);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Return failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  WEB PRODUCTS
    // ═══════════════════════════════════════════════════════

    public function webProducts(Request $request)
{
    $shopId = $request->user()->shop_id;
    $search = trim((string) $request->query('search', ''));
    $filter = $request->query('filter', 'all');

    $perPage = max(1, min(100, (int) $request->query('per_page', 30)));
    $page    = max(1, (int) $request->query('page', 1));

    $q = webproducts::with('products')
        ->where('shop_id', $shopId);

    if ($search !== '') {
        $q->where(function ($w) use ($search) {
            $w->where('brand', 'like', "%{$search}%")
              ->orWhere('model', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%");
        });
    }

    // Counts are computed on the filtered set so the KPI cards stay correct.
    $all = $q->latest()->get();

    $visibleCount    = $all->filter(fn ($w) => $this->webVisible($w))->count();
    $incompleteCount = $all->count() - $visibleCount;

    // Apply the visible/incomplete filter BEFORE paginating.
    if ($filter === 'visible') {
        $all = $all->filter(fn ($w) => $this->webVisible($w))->values();
    } elseif ($filter === 'incomplete' || $filter === 'hidden') {
        $all = $all->filter(fn ($w) => ! $this->webVisible($w))->values();
    }

    $total  = $all->count();
    $offset = ($page - 1) * $perPage;
    $slice  = $all->slice($offset, $perPage)->values();

    return response()->json([
        'filter'       => $filter,
        'search'       => $search,
        'counts'       => [
            'total'      => $q->count(),
            'visible'    => $visibleCount,
            'incomplete' => $incompleteCount,
        ],
        'pagination'   => [
            'page'         => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'total_pages'  => (int) ceil($total / $perPage),
            'has_more'     => ($offset + $perPage) < $total,
        ],
        'products'     => $slice->map(fn ($w) => $this->shapeWebProduct($w)),
    ]);
}

    public function showWebProduct(Request $request, $id)
    {
        $wp = webproducts::with('products')
            ->where('shop_id', $request->user()->shop_id)
            ->findOrFail($id);

        return response()->json($this->shapeWebProduct($wp, true));
    }

    public function updateWebProduct(Request $request, $id)
    {
        $wp = webproducts::where('shop_id', $request->user()->shop_id)->findOrFail($id);

        $data = $request->validate([
            'img'         => 'nullable|string|max:500',
            'gender'      => 'nullable|string|max:30',
            'disc'        => 'nullable|string|max:2000',
            'size'        => 'nullable|integer|min:1',
            'notes'       => 'nullable|string|max:1000',
            'discount'    => 'nullable|numeric|min:0|max:100',
            'price'       => 'nullable|numeric|min:0',
            'is_featured' => 'nullable|boolean',
            'avb'         => 'nullable|in:yes,no',
        ]);

        foreach ($data as $k => $v) {
            if ($v === '' && $k !== 'avb') $data[$k] = null;
        }

        $wp->fill($data);
        $wp->save();

        return response()->json([
            'message' => 'Web product updated',
            'product' => $this->shapeWebProduct($wp->fresh()->load('products'), true),
        ]);
    }

    public function toggleWebProduct(Request $request, $id)
    {
        $wp = webproducts::where('shop_id', $request->user()->shop_id)->findOrFail($id);

        if (! \Schema::hasColumn('webproducts', 'is_hidden')) {
            return response()->json(['message' => 'is_hidden column not present'], 400);
        }

        $wp->is_hidden = ! $wp->is_hidden;
        $wp->save();

        return response()->json([
            'message'   => $wp->is_hidden ? 'Hidden from storefront' : 'Visible on storefront',
            'is_hidden' => (bool) $wp->is_hidden,
        ]);
    }

    public function bulkWebProducts(Request $request)
    {
        $data = $request->validate([
            'ids'        => 'required|array|min:1',
            'ids.*'      => 'integer',
            'gender'     => 'nullable|string|max:30',
            'discount'   => 'nullable|numeric|min:0|max:100',
            'is_hidden'  => 'nullable|boolean',
            'size'       => 'nullable|integer|min:1',
            'avb'        => 'nullable|in:yes,no',
        ]);

        $patch = collect($data)
            ->only(['gender', 'discount', 'is_hidden', 'size', 'avb'])
            ->filter(fn ($v) => ! is_null($v))
            ->all();

        if (empty($patch)) {
            return response()->json(['message' => 'Nothing to update'], 422);
        }

        $affected = webproducts::where('shop_id', $request->user()->shop_id)
            ->whereIn('id', $data['ids'])
            ->update($patch);

        return response()->json([
            'message'  => 'Bulk update applied',
            'affected' => $affected,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════

    /**
     * Apply the product-level discount percentage (0–100) to a base price.
     */
    private function applyProductDiscount($product, $basePrice): float
    {
        $basePrice = (float) $basePrice;
        $pct = (float) ($product->discount ?? 0);

        if ($pct <= 0) {
            return $basePrice;
        }

        if ($pct > 100) {
            $pct = 100;
        }

        return round($basePrice - ($basePrice * $pct / 100), 2);
    }

    private function webVisible($wp): bool
    {
        if (property_exists($wp, 'is_hidden') && $wp->is_hidden) return false;

        // avb must be 'yes'
        if (property_exists($wp, 'avb') && strtolower((string) $wp->avb) !== 'yes') return false;

        return ! is_null($wp->img)    && $wp->img    !== ''
            && ! is_null($wp->gender) && $wp->gender !== ''
            && ! is_null($wp->price)
            && ! is_null($wp->brand)  && $wp->brand  !== ''
            && ! is_null($wp->model)  && $wp->model  !== '';
    }

    private function shapeWebProduct($wp, bool $full = false): array
    {
        $missing = [];
        foreach (['img', 'gender', 'disc', 'size', 'notes', 'discount'] as $f) {
            if (is_null($wp->$f) || $wp->$f === '') $missing[] = $f;
        }

        $basePrice = (float) $wp->price;
        $pct       = max(0, min(100, (float) ($wp->discount ?? 0)));
        $final     = $this->applyProductDiscount($wp, $basePrice);

        $data = [
            'id'              => $wp->id,
            'product_id'      => $wp->products_id,
            'brand'           => $wp->brand,
            'model'           => $wp->model,
            'barcode'         => $wp->barcode,
            'price'           => $basePrice,   // original
            'final_price'     => $final,       // after product discount
            'discount_amount' => round($basePrice - $final, 2),
            'stock'           => (int) $wp->stock,
            'img'             => $wp->img,
            'gender'          => $wp->gender,
            'disc'            => $wp->disc,
            'size'            => $wp->size,
            'notes'           => $wp->notes,
            'discount'        => $pct,
            'is_hidden'       => (bool) ($wp->is_hidden ?? false),
            'avb'             => $wp->avb ?? 'yes',
            'is_visible'      => $this->webVisible($wp),
            'missing'         => $missing,
        ];

        if ($full) {
            $data['buy_price']  = $wp->products?->buy_price;
            $data['sale_price'] = $wp->products?->sale_price;
            $data['qyt']        = $wp->products?->qyt;
        }

        return $data;
    }
}