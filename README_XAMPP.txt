DATESHEET GENERATOR - READY-TO-RUN XAMPP PACKAGE
================================================

No PowerShell, Composer, npm, or Artisan command is required.

1. EXTRACT
----------
Extract the Datesheet_Generator folder to this exact location:

    C:\xampp\htdocs\Datesheet_Generator

After extraction, confirm this file exists:

    C:\xampp\htdocs\Datesheet_Generator\public\index.php

2. IMPORT THE SQL USING PHPMYADMIN
---------------------------------
Start Apache and MySQL from the XAMPP Control Panel, then open:

    http://localhost/phpmyadmin

In phpMyAdmin:

    a. Select Databases.
    b. Create a database named datesheet_generator.
    c. Use collation utf8mb4_unicode_ci.
    d. Select the new datesheet_generator database.
    e. Select Import, choose your .sql file, and click Import/Go.

If the SQL dump creates its own database with a different name, open the .env
file in Notepad and change DB_DATABASE to that database name.

The included .env currently uses:

    Database: datesheet_generator
    Username: root
    Password: blank

If MySQL root has a password, open .env in Notepad and put it after DB_PASSWORD=.

3. OPEN THE APPLICATION
-----------------------
Keep Apache and MySQL running in XAMPP and open:

    http://localhost/Datesheet_Generator/public

No php artisan serve command is needed.

TROUBLESHOOTING
---------------
If routes display 404, open XAMPP Control Panel > Apache > Config > httpd.conf.
Make sure the rewrite module line is enabled and the htdocs Directory section
uses AllowOverride All, then restart Apache.

If spreadsheet import/export reports missing GD or ZIP support, open XAMPP
Control Panel > Apache > Config > PHP (php.ini). Enable extension=gd and
extension=zip by removing any leading semicolon, then restart Apache.

This package includes production Composer dependencies and a configured .env.
Do not publish this package to the internet without changing its credentials and
using a proper Apache VirtualHost whose DocumentRoot points to the public folder.
