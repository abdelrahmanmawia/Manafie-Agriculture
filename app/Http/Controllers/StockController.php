<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class StockController extends Controller
{
    /**
     * Display the stock management dashboard.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        // You can fetch summary data here if needed for the dashboard
        // For now, it will just render the dashboard with navigation links
        return Inertia::render('Stock/Dashboard');
    }
}
