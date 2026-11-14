<?php

namespace App\Modules\Grades;

use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Jobs\NotifySectionCompleted;
use App\Modules\Grades\Application\Metrics\GradesMetrics;
use App\Modules\Grades\Application\Queries\GradesOverviewQuery;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Contracts\GradesOverview;
use App\Modules\Grades\Domain\ResultCalculator;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Observability\MetricsSource;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * The Grades module (ADR-0034): everything it adds to the application is
 * declared here, so removing the module is removing this provider, its
 * folder and its settings (config/grades.php, kept in config/ so env()
 * still works with a cached configuration).
 */
class GradesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Outbox topics this module publishes, and who handles them.
        config(['platform.outbox.handlers' => [
            ...(array) config('platform.outbox.handlers'),
            SaveMarks::TOPIC => NotifySectionCompleted::class,
        ]]);

        $this->app->singleton(TeacherCodes::class);
        $this->app->bind(ResultCalculator::class, fn (): ResultCalculator => new ResultCalculator((int) config('grades.pass_mark')));

        // The module's public read API (Contracts), for the dashboard.
        $this->app->bind(GradesOverview::class, GradesOverviewQuery::class);

        // Its part of /metrics (module_grades_*).
        $this->app->tag([GradesMetrics::class], MetricsSource::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        // Stable names in audit records instead of class names.
        Relation::morphMap([
            Teacher::MORPH => Teacher::class,
            Subject::MORPH => Subject::class,
            Student::MORPH => Student::class,
            GradePeriod::MORPH => GradePeriod::class,
        ]);
    }
}
