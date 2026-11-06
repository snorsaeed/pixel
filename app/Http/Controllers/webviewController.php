<?php

namespace App\Http\Controllers;

use App\Models\webproducts;
use Illuminate\Http\Request;

class webviewController extends Controller
{
    /**
     * SQL expression for the effective (post-discount) price.
     * discount is stored as a percentage 0–100.
     */
    private const DISCOUNTED_PRICE_SQL =
        '(price - (price * LEAST(GREATEST(COALESCE(discount, 0), 0), 100) / 100))';

    public function index(Request $request)
    {
        $query = webproducts::query();

        // Hide anything with null in important fields
        $query->whereNotNull('img')
              ->whereNotNull('gender')
              ->whereNotNull('price')
              ->whereNotNull('brand')
              ->whereNotNull('model')
              ->where('avb', 'yes');

        // Optionally hide explicitly hidden products (if column exists)
        if (\Schema::hasColumn('webproducts', 'is_hidden')) {
            $query->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', 0);
            });
        }

        // Filter by gender
        if ($gender = $request->query('gender')) {
            if ($gender !== 'all') {
                $query->where('gender', $gender);
            }
        }

        // Filter by season (via products table)
        if ($season = $request->query('season')) {
            if ($season !== 'all') {
                $query->whereHas('products', function ($q) use ($season) {
                    $q->where('season', 'like', "%{$season}%");
                });
            }
        }

        // Filter by price range — use the DISCOUNTED price so the
        // range matches what the customer will actually pay.
        if (($min = $request->query('min_price')) !== null && $min !== '') {
            $query->whereRaw(self::DISCOUNTED_PRICE_SQL . ' >= ?', [(float) $min]);
        }
        if (($max = $request->query('max_price')) !== null && $max !== '') {
            $query->whereRaw(self::DISCOUNTED_PRICE_SQL . ' <= ?', [(float) $max]);
        }

        // Filter by discount (only discounted items)
        if ($request->query('discounted') === 'true') {
            $query->where('discount', '>', 0);
        }

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('brand', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Sort — by the effective price, not the raw one
        $sort = $request->query('sort');
        if ($sort === 'price_asc') {
            $query->orderByRaw(self::DISCOUNTED_PRICE_SQL . ' ASC');
        } elseif ($sort === 'price_desc') {
            $query->orderByRaw(self::DISCOUNTED_PRICE_SQL . ' DESC');
        } else {
            $query->latest();
        }

        $products = $query->get()->map(function ($p) {
            $base = (float) $p->price;
            $pct  = max(0, min(100, (float) ($p->discount ?? 0)));
            $final = $pct > 0
                ? round($base - ($base * $pct / 100), 2)
                : $base;

            return array_merge($p->toArray(), [
                'price'           => $base,          // original price
                'final_price'     => $final,         // what checkout will charge
                'discount'        => $pct,           // % off
                'discount_amount' => round($base - $final, 2),
            ]);
        });

        return response()->json([
            'msg' => $products,
        ]);
    }
}