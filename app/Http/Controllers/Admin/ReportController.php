<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BuildAdminReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRequest;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Credit spend, provider costs and profit for a range of UTC days, reconciled with Pixelforge.
     */
    public function __invoke(ReportRequest $request, BuildAdminReport $buildReport): Response
    {
        return Inertia::render('admin/Report', [
            'startDate' => $request->startDate()->toDateString(),
            'endDate' => $request->endDate()->toDateString(),
            'report' => $buildReport->handle($request->startDate(), $request->endDate()),
        ]);
    }
}
