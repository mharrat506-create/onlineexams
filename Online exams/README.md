## Online Exam System

This folder contains a PHP + MySQL based online exam system with:
- Webcam + anti-cheating (tab switching, page leave detection)
- Question bank by subject
- Subject-based exams
- Admin analytics dashboard
- Teacher portal to create subjects, exams, question banks, and view student results per exam
- Automatic certificates for scores ≥ 70%
- Mobile-friendly responsive UI (Bootstrap).

To run:
1. Import the SQL from `db/online_exam.sql` into MySQL.
2. Configure `includes/config.php` with your DB credentials.
3. Access `index.php` for login and registration (student or teacher).
4. Teachers are redirected to `teacher/teacher.php` to create exams and add questions.
5. Access `admin/admin.php` for full administration.

Demo logins (password `admin123`):
- Admin: `admin@onlineexam.local`
- Teacher: `teacher@onlineexam.local`

