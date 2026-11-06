<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00:00 — old returned items (6 months)
Schedule::command('model:prune', [
    '--model' => [\App\Models\sale_items::class],
])->daily()->at('00:00');

// 00:01 — old web products (1 year)
Schedule::command('model:prune', [
    '--model' => [\App\Models\webproducts::class],
])->daily()->at('00:01');

// 00:02 — old main products (1 year)
Schedule::command('model:prune', [
    '--model' => [\App\Models\products::class],
])->daily()->at('00:02');