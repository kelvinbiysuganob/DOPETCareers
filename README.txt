DOPET CAREERS - DEPLOYMENT GUIDE
================================
Files
  index.html        the website (upload to your site's public folder, e.g. public_html or htdocs)
  api.php           the backend (same folder as index.html)
  schema.sql        database tables (run in phpMyAdmin)
  uploads/          folder for CVs, includes .htaccess that blocks direct downloads

Requirements: PHP 8.0+ with PDO MySQL, MySQL 5.7+/MariaDB 10.3+, HTTPS recommended.

Steps
1. In your hosting control panel create a MySQL database and a database user. Note the 4 details:
   host, database name, username, password.
2. Open phpMyAdmin, click your database, open the SQL tab, paste the contents of schema.sql, click Go.
3. Open api.php in a text editor and replace the 4 settings at the top (DB_HOST, DB_NAME, DB_USER, DB_PASS).
4. Upload index.html, api.php and the uploads folder (including uploads/.htaccess) to the public folder.
   Make sure uploads/ is writable (permission 755, or 775 if uploads fail).
5. Visit your site and register an account. Then in phpMyAdmin run:
   UPDATE users SET role='admin' WHERE email='your-email@example.com';
6. Go to Staff in the menu, log in, post a job. It appears on the home page.

Security notes
  - Use HTTPS. Ask your host to enable the free SSL certificate.
  - If your host does not use Apache (.htaccess is ignored), move the uploads folder above the public
    folder and change UPLOAD_DIR in api.php to that path.
  - Back up the database and the uploads folder regularly. Free hosts often do not.
  - Set a strong password for the staff account.
