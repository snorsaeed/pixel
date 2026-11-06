<?php

use App\Http\Controllers\authConttroler;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\saleController;
use App\Http\Controllers\WebCheckoutController;
use App\Http\Controllers\webviewController;
use App\Http\Controllers\ShopSettingController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

// ─── Public (works even when shop is expired) ────────
Route::post('/register', [authConttroler::class, 'register']);
Route::post('/login',    [authConttroler::class, 'login']);
Route::post('/adduser',  [authConttroler::class, 'addUser']);

Route::get('/webview', [webviewController::class, 'index']);

Route::get('/shop-info/{shop_id}', function ($shopId) {
    $setting = \App\Models\ShopSetting::where('shop_id', $shopId)->first();
    $shop = \App\Models\Shop::find($shopId);

    return response()->json([
        'shop' => $shop?->only(['id', 'shop_name']),
        'hero_text' => $setting?->hero_text,
        'hero_url' => $setting?->hero_url,
        'website_url' => $setting?->website_url,
        'website_img' => $setting?->website_img,
    ]);
});

Route::post('/websales', [WebCheckoutController::class, 'checkout'])
    ->middleware('throttle:10,1');

// ─── Protected — requires auth AND non-expired shop ──
Route::middleware(['auth:sanctum', 'shop.active'])->group(function () {

    Route::post('/logout', [authConttroler::class, 'logout']);

    // ─── Inventory (must come BEFORE apiResource) ─
    Route::get('/inventory',            [ProductsController::class, 'inventory']);
    Route::post('/products/{id}/stock', [ProductsController::class, 'adjustStock']);

    // ─── Products CRUD ────────────────────────────
    Route::apiResource('products', ProductsController::class);

    // ─── POS Sales ────────────────────────────────
    Route::get('/sales',             [saleController::class, 'index']);
    Route::post('/sales',            [saleController::class, 'sale']);
    Route::post('/sales/returns',    [saleController::class, 'returns']);
    Route::get('/returns',           [saleController::class, 'returnsHistory']);
    Route::post('/sales/{id}/close', [saleController::class, 'closeDeal']);

    // ─── Debts ────────────────────────────────────
    Route::post('/sales/{id}/pay', [saleController::class, 'pay']);
    Route::get('/debts',           [saleController::class, 'debts']);
    Route::get('/debts/summary',   [saleController::class, 'debtsSummary']);

    // ─── Web Sales ────────────────────────────────
    Route::get('/websales',              [WebCheckoutController::class, 'index']);
    Route::post('/websales/returns',     [WebCheckoutController::class, 'returns']);
    Route::put('/websales/{id}/status',  [WebCheckoutController::class, 'updateStatus']);

    // ─── Web Products (edit storefront content) ───
    Route::get ('/web-products',             [WebCheckoutController::class, 'webProducts']);
    Route::post('/web-products/bulk',        [WebCheckoutController::class, 'bulkWebProducts']);
    Route::get ('/web-products/{id}',        [WebCheckoutController::class, 'showWebProduct']);
    Route::put ('/web-products/{id}',        [WebCheckoutController::class, 'updateWebProduct']);
    Route::post('/web-products/{id}/toggle', [WebCheckoutController::class, 'toggleWebProduct']);

    // ─── Shop Settings ────────────────────────────
    Route::get('/shop/settings',  [ShopSettingController::class, 'show']);
    Route::post('/shop/settings', [ShopSettingController::class, 'upsert']);
    Route::put('/shop/settings',  [ShopSettingController::class, 'upsert']);

    // ─── Shop Employees (per-shop, scoped via auth) ───
    Route::post  ('/shop/employees',      [ShopSettingController::class, 'storeEmployee']);
    Route::put   ('/shop/employees/{id}', [ShopSettingController::class, 'updateEmployee']);
    Route::delete('/shop/employees/{id}', [ShopSettingController::class, 'destroyEmployee']);

    // ─── Analytics ────────────────────────────────
    Route::get('/dashboard',          [AnalyticsController::class, 'dashboard']);
    Route::get('/reports/sales',      [AnalyticsController::class, 'salesReport']);
    Route::get('/reports/products',   [AnalyticsController::class, 'productsReport']);
    Route::get('/reports/customers',  [AnalyticsController::class, 'customersReport']);
    Route::get('/reports/brands',     [AnalyticsController::class, 'brandsReport']);
    Route::get('/reports/net-profit', [AnalyticsController::class, 'netProfitReport']);

    // ─── Customers ────────────────────────────────
    Route::get('/customers',         [CustomerController::class, 'index']);
    Route::get('/customers/{phone}', [CustomerController::class, 'show']);

    // ─── Expenses ─────────────────────────────────
    Route::get   ('/expenses',      [ExpenseController::class, 'index']);
    Route::post  ('/expenses',      [ExpenseController::class, 'store']);
    Route::put   ('/expenses/{id}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy']);
});