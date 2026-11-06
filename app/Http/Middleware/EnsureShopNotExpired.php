<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not logged in — let auth middleware handle it
        if (!$user) {
            return $next($request);
        }

        // Load the shop (works whether user has shop_id or shop relationship)
        $shop = $user->shop; // adjust if your relation is different

        if (!$shop) {
            return response()->json([
                'message' => 'No shop associated with this account.',
                'code'    => 'no_shop',
            ], 403);
        }

        if ($shop->expires_at && now()->greaterThan($shop->expires_at)) {
            return response()->json([
                'message' => 'Your subscription has expired. Please renew to continue.',
                'code'    => 'shop_expired',
                'expired_at' => $shop->expires_at,
            ], 403);
        }

        return $next($request);
    }
}