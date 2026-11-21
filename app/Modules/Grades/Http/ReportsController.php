<?php

namespace App\Modules\Grades\Http;

use App\Models\User;
use App\Modules\Grades\Application\Queries\AcademicReports;
use App\Modules\Grades\Application\Queries\ReportAdvice;
use App\Modules\Grades\Domain\Period;
use App\Platform\Audit\AuditLogger;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The academic reports (owner's request, 2026-11-20): results, teachers'
 * progress, students' sign-ins, appeals and subjects for one period, with
 * the recommendations drawn from them. They name students and show their
 * sign-ins, so opening them is audited (once in ten minutes per user).
 */
class ReportsController
{
    public function __invoke(Request $request, AcademicReports $reports, ReportAdvice $advice, AuditLogger $audit, #[CurrentUser] User $user): Response
    {
        $period = Period::tryFrom((string) $request->query('period', Period::Month1->value)) ?? Period::Month1;
        $report = $reports->build($period);

        if (Cache::add("grades:reports-viewed:{$user->id}", true, now()->addMinutes(10))) {
            $audit->record('grades.reports_viewed', metadata: ['period' => $period->value], actor: $user);
        }

        return Inertia::render('modules/grades/pages/admin/reports', [
            ...$report,
            'advice' => $advice->for($report),
            'thresholds' => [
                'pass_critical' => ReportAdvice::PASS_CRITICAL,
                'pass_warning' => ReportAdvice::PASS_WARNING,
            ],
            'generatedAt' => now()->toIso8601String(),
        ]);
    }
}
