# Academic reports: integrity check (2026-11-20)

Owner's request (2026-11-20): reports with a check that they are right.

## Two checks

1. **Hand-worked data** (`database/seeders/ReportsCheckSeeder.php`): eight
   students, three subjects, three teachers; every figure worked out by hand
   in the seeder's comment. `tests/Feature/Grades/AcademicReportsTest.php`
   compares the reports with those figures (15 tests).
2. **Independent recount** (`php artisan grades:reports-check`): recounts the
   figures from the raw tables with its own arithmetic (not ResultSheets or
   ResultCalculator) and compares. Read only; exit code 1 on any difference.

## Full-size run

Database: `database/audit.sqlite` after the full-size audit of 2026-11-20
(100 students, 40 subject sections, 800 marks, 6 appeals), never a real
database.

```
DB_CONNECTION=sqlite DB_DATABASE=database/audit.sqlite php artisan grades:reports-check
```

```
+----+---------------------------------------+---------+-----------+
| | figure | reports | recounted |
+----+---------------------------------------+---------+-----------+
| ok | 1A students | 34 | 34 |
| ok | 1A complete | 34 | 34 |
| ok | 1A passed | 7 | 7 |
| ok | 1A mean % | 66 | 66 |
| ok | 2A students | 17 | 17 |
| ok | 2A complete | 17 | 17 |
| ok | 2A passed | 1 | 1 |
| ok | 2A mean % | 62.1 | 62.1 |
| ok | 2B students | 16 | 16 |
| ok | 2B complete | 16 | 16 |
| ok | 2B passed | 1 | 1 |
| ok | 2B mean % | 59.3 | 59.3 |
| ok | 3A students | 17 | 17 |
| ok | 3A complete | 17 | 17 |
| ok | 3A passed | 0 | 0 |
| ok | 3A mean % | 63.8 | 63.8 |
| ok | 3B students | 16 | 16 |
| ok | 3B complete | 16 | 16 |
| ok | 3B passed | 0 | 0 |
| ok | 3B mean % | 63.1 | 63.1 |
| ok | أساسيات الأمن السيبراني (1) entered | 34 | 34 |
| ok | أساسيات الأمن السيبراني (1) absent | 1 | 1 |
| ok | أساسيات الأمن السيبراني (1) passed | 25 | 25 |
| ok | شبكات الحاسوب (1) entered | 34 | 34 |
| ok | شبكات الحاسوب (1) absent | 2 | 2 |
| ok | شبكات الحاسوب (1) passed | 28 | 28 |
| ok | البرمجة المهيكلة (1) entered | 34 | 34 |
| ok | البرمجة المهيكلة (1) absent | 1 | 1 |
| ok | البرمجة المهيكلة (1) passed | 27 | 27 |
| ok | الرياضيات المتقطعة (1) entered | 34 | 34 |
| ok | الرياضيات المتقطعة (1) absent | 0 | 0 |
| ok | الرياضيات المتقطعة (1) passed | 26 | 26 |
| ok | أنظمة التشغيل (1) entered | 34 | 34 |
| ok | أنظمة التشغيل (1) absent | 0 | 0 |
| ok | أنظمة التشغيل (1) passed | 25 | 25 |
| ok | تراكيب البيانات (1) entered | 34 | 34 |
| ok | تراكيب البيانات (1) absent | 2 | 2 |
| ok | تراكيب البيانات (1) passed | 26 | 26 |
| ok | اللغة الإنكليزية (1) entered | 34 | 34 |
| ok | اللغة الإنكليزية (1) absent | 2 | 2 |
| ok | اللغة الإنكليزية (1) passed | 26 | 26 |
| ok | حقوق الإنسان والديمقراطية (1) entered | 34 | 34 |
| ok | حقوق الإنسان والديمقراطية (1) absent | 0 | 0 |
| ok | حقوق الإنسان والديمقراطية (1) passed | 29 | 29 |
| ok | التشفير (2) entered | 33 | 33 |
| ok | التشفير (2) absent | 2 | 2 |
| ok | التشفير (2) passed | 20 | 20 |
| ok | أمن الشبكات (2) entered | 33 | 33 |
| ok | أمن الشبكات (2) absent | 4 | 4 |
| ok | أمن الشبكات (2) passed | 23 | 23 |
| ok | قواعد البيانات (2) entered | 33 | 33 |
| ok | قواعد البيانات (2) absent | 2 | 2 |
| ok | قواعد البيانات (2) passed | 22 | 22 |
| ok | البرمجة الكيانية (2) entered | 33 | 33 |
| ok | البرمجة الكيانية (2) absent | 1 | 1 |
| ok | البرمجة الكيانية (2) passed | 22 | 22 |
| ok | هندسة البرمجيات (2) entered | 33 | 33 |
| ok | هندسة البرمجيات (2) absent | 4 | 4 |
| ok | هندسة البرمجيات (2) passed | 21 | 21 |
| ok | نظم إدارة الهوية (2) entered | 33 | 33 |
| ok | نظم إدارة الهوية (2) absent | 2 | 2 |
| ok | نظم إدارة الهوية (2) passed | 23 | 23 |
| ok | الإحصاء والاحتمالية (2) entered | 33 | 33 |
| ok | الإحصاء والاحتمالية (2) absent | 1 | 1 |
| ok | الإحصاء والاحتمالية (2) passed | 26 | 26 |
| ok | أخلاقيات المهنة (2) entered | 33 | 33 |
| ok | أخلاقيات المهنة (2) absent | 0 | 0 |
| ok | أخلاقيات المهنة (2) passed | 24 | 24 |
| ok | اختبار الاختراق (3) entered | 33 | 33 |
| ok | اختبار الاختراق (3) absent | 3 | 3 |
| ok | اختبار الاختراق (3) passed | 17 | 17 |
| ok | التحليل الجنائي الرقمي (3) entered | 33 | 33 |
| ok | التحليل الجنائي الرقمي (3) absent | 3 | 3 |
| ok | التحليل الجنائي الرقمي (3) passed | 22 | 22 |
| ok | أمن تطبيقات الويب (3) entered | 33 | 33 |
| ok | أمن تطبيقات الويب (3) absent | 0 | 0 |
| ok | أمن تطبيقات الويب (3) passed | 24 | 24 |
| ok | تحليل البرمجيات الخبيثة (3) entered | 33 | 33 |
| ok | تحليل البرمجيات الخبيثة (3) absent | 1 | 1 |
| ok | تحليل البرمجيات الخبيثة (3) passed | 26 | 26 |
| ok | الحوسبة السحابية الآمنة (3) entered | 33 | 33 |
| ok | الحوسبة السحابية الآمنة (3) absent | 0 | 0 |
| ok | الحوسبة السحابية الآمنة (3) passed | 25 | 25 |
| ok | إدارة المخاطر (3) entered | 33 | 33 |
| ok | إدارة المخاطر (3) absent | 0 | 0 |
| ok | إدارة المخاطر (3) passed | 24 | 24 |
| ok | منهجية البحث العلمي (3) entered | 33 | 33 |
| ok | منهجية البحث العلمي (3) absent | 3 | 3 |
| ok | منهجية البحث العلمي (3) passed | 23 | 23 |
| ok | مشروع التخرج (3) entered | 33 | 33 |
| ok | مشروع التخرج (3) absent | 0 | 0 |
| ok | مشروع التخرج (3) passed | 22 | 22 |
| ok | appeals | 6 | 6 |
| ok | approved subject sections | 39 | 39 |
| ok | teacher sign-ins (counter) | 42 | 42 |
| ok | student sign-ins (counter) | 101 | 101 |
| ok | student views of results | 100 | 100 |
| ok | subject sections without a teacher | 0 | 0 |
+----+---------------------------------------+---------+-----------+
All 98 figures agree.
```
