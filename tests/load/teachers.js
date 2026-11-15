// Teachers entering marks at once (ADR-0034): each VU is one teacher who
// signs in with their code, opens their subjects, opens a section's sheet
// and saves a full column of marks (different marks each round, so every
// save writes and is audited), then does it again. Local hosts only
// (lib/guard.js).
//
//   k6 run -e BASE_URL=http://127.0.0.1:8124 -e TEACHER_VUS=16 -e HOLD=1m tests/load/teachers.js
//
// Teachers come from storage/app/private/perf/teachers.csv
// (GradesPerformanceSeeder).
import { check, fail, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import http from 'k6/http';
import { Counter, Trend } from 'k6/metrics';
import { baseUrl } from './lib/guard.js';

const BASE = baseUrl();
const HOLD = __ENV.HOLD || '1m';
const teacherVus = Number(__ENV.TEACHER_VUS || 16);

const teachers = new SharedArray('teachers', () =>
    open(__ENV.TEACHERS_CSV || '../../storage/app/private/perf/teachers.csv')
        .trim()
        .split('\n')
        .slice(1)
        .map((line) => {
            const [code, subject] = line.trim().split(',');
            return { code, subject };
        }),
);

const appTime = new Trend('server_app_ms', true);
const dbQueries = new Trend('server_db_queries');
const saveTime = new Trend('save_marks_ms', true);
const signInFailures = new Counter('sign_in_failures');
const saveFailures = new Counter('save_failures');

export const options = {
    // Keep each teacher's session between rounds.
    noCookiesReset: true,
    scenarios: {
        teachers: {
            executor: 'ramping-vus',
            stages: [
                { duration: '15s', target: teacherVus },
                { duration: HOLD, target: teacherVus },
                { duration: '10s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: true }],
        checks: ['rate>0.99'],
        sign_in_failures: ['count==0'],
        save_failures: ['count==0'],
    },
};

function recordTiming(response) {
    const header = response.headers['Server-Timing'] || '';
    const app = /app;dur=([\d.]+)/.exec(header);
    const db = /db;dur=([\d.]+);desc="(\d+) queries"/.exec(header);
    const tags = {
        page: response.request.url
            .replace(BASE, '')
            .replace(/[0-9a-f-]{36}/, ':subject'),
    };

    if (app) appTime.add(Number(app[1]), tags);
    if (db) dbQueries.add(Number(db[2]), tags);
}

function xsrf() {
    const cookie = http.cookieJar().cookiesForURL(BASE)['XSRF-TOKEN'];
    return cookie ? decodeURIComponent(cookie[0]) : '';
}

// The assets version the server expects in X-Inertia-Version (a request
// without it gets 409 and a full reload, as a browser would).
let version = '';

/** The Inertia page's props, as the browser receives them after the first load. */
function inertia(path) {
    const response = http.get(`${BASE}${path}`, {
        headers: {
            'X-Inertia': 'true',
            'X-Inertia-Version': version,
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'text/html, application/xhtml+xml',
        },
    });
    recordTiming(response);

    return response;
}

const signedIn = {};

function signIn(teacher) {
    const page = http.get(`${BASE}/teacher`);
    const found =
        /&quot;version&quot;:&quot;([^&]*)&quot;|"version":"([^"]*)"/.exec(
            String(page.body),
        );
    version = found ? found[1] || found[2] || '' : '';
    const response = http.post(
        `${BASE}/teacher`,
        JSON.stringify({ code: teacher.code }),
        {
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
                'X-Inertia': 'true',
                'X-Inertia-Version': version,
                'X-Requested-With': 'XMLHttpRequest',
            },
            redirects: 0,
        },
    );
    const ok = check(response, {
        'signed in': (r) =>
            r.status === 302 &&
            (r.headers.Location || '').endsWith('/teacher/subjects'),
    });

    if (!ok) {
        signInFailures.add(1);
        fail(
            `sign-in failed for ${teacher.code}: ${response.status} ${String(response.body).slice(0, 200)}`,
        );
    }
}

export default function () {
    if (teachers.length < teacherVus) {
        fail(
            `teachers.csv has ${teachers.length} teachers; TEACHER_VUS=${teacherVus} needs one each`,
        );
    }

    const teacher = teachers[(__VU - 1) % teachers.length];

    if (!signedIn[__VU]) {
        signIn(teacher);
        signedIn[__VU] = true;
    }

    const subjects = inertia('/teacher/subjects');
    check(subjects, {
        'subjects page': (r) =>
            r.status === 200 &&
            r.json('component') === 'modules/grades/pages/teacher/subjects',
    });
    sleep(1);

    // The sections the subject's stage has (the first stage has only one).
    const own = (subjects.json('props.subjects') || []).find(
        (s) => s.uuid === teacher.subject,
    );
    const sections = own ? own.sections.map((s) => s.section) : ['A'];
    const section = sections[__ITER % sections.length];
    const sheet = inertia(`/teacher/subjects/${teacher.subject}/${section}`);
    if (!check(sheet, { 'sheet page': (r) => r.status === 200 })) {
        return;
    }

    // A teacher types for a while before saving.
    sleep(3);

    const marks = {};
    for (const [i, student] of sheet.json('props.students').entries()) {
        marks[student.uuid] =
            (__ITER + i) % 17 === 0
                ? 'absent'
                : String((__ITER * 7 + i * 3) % 101);
    }

    const started = Date.now();
    const saved = http.put(
        `${BASE}/teacher/subjects/${teacher.subject}/${section}`,
        JSON.stringify({ marks }),
        {
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
                'X-Inertia': 'true',
                'X-Inertia-Version': version,
                'X-Requested-With': 'XMLHttpRequest',
            },
            redirects: 0,
        },
    );
    saveTime.add(Date.now() - started);
    recordTiming(saved);

    // Inertia answers a PUT with 303 See Other.
    if (
        !check(saved, {
            saved: (r) =>
                r.status === 303 &&
                (r.headers.Location || '').endsWith(`/${section}`),
        })
    ) {
        saveFailures.add(1);
    }

    sleep(2);
}
