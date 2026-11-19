<?php

namespace App\Modules\Grades;

use App\Modules\Grades\Application\Actions\SaveMarks;
use App\Modules\Grades\Application\Jobs\NotifyGradesManagers;
use App\Modules\Grades\Application\Jobs\NotifySectionCompleted;
use App\Modules\Grades\Application\Metrics\GradesMetrics;
use App\Modules\Grades\Application\Queries\GradesOverviewQuery;
use App\Modules\Grades\Application\StudentCodes;
use App\Modules\Grades\Application\TeacherCodes;
use App\Modules\Grades\Contracts\GradesOverview;
use App\Modules\Grades\Domain\ResultCalculator;
use App\Modules\Grades\Http\StudentsTable;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradeEditRequest;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Modules\Grades\Models\Subject;
use App\Modules\Grades\Models\SubjectTeacher;
use App\Modules\Grades\Models\Teacher;
use App\Platform\Observability\MetricsSource;
use App\Platform\Tables\TableRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
        // Teachers sign in on their own guard with a code (ADR-0034): the
        // `web` guard, the users' pages and their second factor never see a
        // teacher, and a teacher's session reaches only mark entry.
        config([
            'auth.guards.'.Teacher::GUARD => ['driver' => 'session', 'provider' => 'grade_teachers'],
            'auth.providers.grade_teachers' => ['driver' => 'eloquent', 'model' => Teacher::class],
            // Students see their own published result with a code, the same way.
            'auth.guards.'.Student::GUARD => ['driver' => 'session', 'provider' => 'grade_students'],
            'auth.providers.grade_students' => ['driver' => 'eloquent', 'model' => Student::class],
        ]);
        $this->app->singleton(StudentCodes::class);

        // Outbox topics this module publishes, and who handles them.
        config(['platform.outbox.handlers' => [
            ...(array) config('platform.outbox.handlers'),
            SaveMarks::TOPIC => NotifySectionCompleted::class,
            NotifyGradesManagers::TOPIC => NotifyGradesManagers::class,
        ]]);

        $this->app->singleton(TeacherCodes::class);
        // The department's bands and exemption, read when a result is made (ADR-0039).
        $this->app->bind(ResultCalculator::class, fn (): ResultCalculator => GradeSettings::current()->calculator());

        // The module's public read API (Contracts), for the dashboard.
        $this->app->bind(GradesOverview::class, GradesOverviewQuery::class);

        // Its part of /metrics (module_grades_*).
        $this->app->tag([GradesMetrics::class], MetricsSource::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        // The owner's student list keeps its columns and views per person.
        $this->app->make(TableRegistry::class)->register(StudentsTable::NAME, StudentsTable::class, StudentsTable::ABILITY);

        // Saving marks: 60 a minute for each teacher (the route sits behind
        // TeacherSession, so there is always one).
        RateLimiter::for('grades-teacher-save', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('grades-teacher:'.(Auth::guard(Teacher::GUARD)->id() ?? $request->ip())));

        // Stable names in audit records instead of class names.
        Relation::morphMap([
            Teacher::MORPH => Teacher::class,
            Subject::MORPH => Subject::class,
            Student::MORPH => Student::class,
            GradePeriod::MORPH => GradePeriod::class,
            GradeSettings::MORPH => GradeSettings::class,
            GradeEditRequest::MORPH => GradeEditRequest::class,
            SubjectTeacher::MORPH => SubjectTeacher::class,
            GradeAppeal::MORPH => GradeAppeal::class,
        ]);
    }
}
