<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Writer/Dashboard', [
            'tenantName' => tenant('name'),
        ]);
    }
}
