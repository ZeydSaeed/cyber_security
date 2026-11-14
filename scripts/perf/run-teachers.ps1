# The teachers' load test (tests/load/teachers.js, ADR-0034) on the
# disposable test database, never the development one: migrate it fresh,
# seed GradesPerformanceSeeder, serve it with PHP's own server (which passes
# the environment through; `artisan serve` does not), check the server got
# that environment before any load, run k6, stop.
#
#   .\scripts\perf\run-teachers.ps1 -Name teachers-16 -Vus 16 -Hold 1m
un-teachers.ps1 -Name teachers-16 -Vus 16 -Hold 1m
#
# On Windows the built-in server answers one request at a time: compare the
# application's own time (Server-Timing), not k6's waiting time.
param(
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9-]+$')][string]$Name,
    [int]$Vus = 16,
    [string]$Hold = '1m'
)
$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path (Join-Path $PSScriptRoot '..\..'))
. .\scripts\env.ps1 | Out-Null

$roles = @{}
foreach ($line in [IO.File]::ReadAllLines('.env.db-roles')) {
    if ($line -match '^([A-Z_]+)=(.*)$') { $roles[$Matches[1]] = $Matches[2] }
}
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:DB_CONNECTION = 'pgsql'
$env:DB_DATABASE = 'cyber_security_testing'
$env:DB_USERNAME = $roles['OWNER_DB_USERNAME']
$env:DB_PASSWORD = $roles['OWNER_DB_PASSWORD']
$env:PERF_DATABASE = 'cyber_security_testing'
$env:PERF_STUDENTS = '40'
$env:LOG_LEVEL = 'warning'
$env:CACHE_STORE = 'database'
# Sessions in files: the probe below writes to no database at all.
$env:SESSION_DRIVER = 'file'
$env:QUEUE_CONNECTION = 'database'
# Parallel workers where the platform supports them (not Windows, which
# lacks fork: there the server answers one request at a time).
$env:PHP_CLI_SERVER_WORKERS = '8'
$env:APP_URL = 'http://127.0.0.1:8124'
# Proof the server got these variables (artisan serve passes only a few;
# a first attempt reached the development database that way).
$env:APP_NAME = 'load-test-on-testing-db'

php artisan config:clear --quiet
php artisan migrate:fresh --force --quiet
php artisan db:seed --class=GradesPerformanceSeeder --force

$out = "docs\evidence\grades-g2\$((Get-Date).ToString('yyyy-MM-dd'))-$Name"
New-Item -ItemType Directory $out -Force | Out-Null
$router = (Resolve-Path 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php').Path
$server = Start-Process php -ArgumentList '-S', '127.0.0.1:8124', $router -WorkingDirectory (Resolve-Path 'public').Path -PassThru -WindowStyle Hidden
try {
    for ($i = 0; $i -lt 30; $i++) {
        try { Invoke-WebRequest http://127.0.0.1:8124/up -UseBasicParsing -TimeoutSec 2 | Out-Null; break } catch { Start-Sleep 1 }
    }
    $probe = (Invoke-WebRequest http://127.0.0.1:8124/teacher -UseBasicParsing).Content
    if ($probe -notmatch 'load-test-on-testing-db') { throw 'The server did not get the test environment; stopping before any load.' }
    $start = Get-Date
    $ErrorActionPreference = 'Continue'
    cmd /c "k6 run --summary-export $out\summary.json -e BASE_URL=http://127.0.0.1:8124 -e TEACHER_VUS=$Vus -e HOLD=$Hold tests\load\teachers.js > $out\k6.log 2>&1"
    Get-Content "$out\k6.log" | Select-Object -Last 45
    $marks = php artisan tinker --execute="echo \App\Modules\Grades\Models\GradeMark::count().' marks; '.\App\Platform\Audit\AuditEvent::where('action','grades.marks_saved')->count().' saves audited';"
    @(
        "Local run, $($start.ToString('yyyy-MM-dd HH:mm')).",
        'Server: PHP built-in server (php -S with Laravel''s router), PHP_CLI_SERVER_WORKERS=8, PHP 8.5 (Herd), PostgreSQL 18 local.',
        'Database: cyber_security_testing (disposable test database), migrated fresh and seeded by GradesPerformanceSeeder:',
        '16 teachers (one subject each, 8 per stage), 40 students per section (160 students), first month open.',
        "Load: tests/load/teachers.js, $Vus teacher VUs, 15 s ramp, $Hold hold, 10 s ramp down.",
        "After the run: $marks",
        'Not representative of a production server (Nginx + PHP-FPM); a local, relative measurement.'
    ) | Set-Content "$out\run.txt" -Encoding utf8
} finally {
    Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -match '8124|server.php' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

