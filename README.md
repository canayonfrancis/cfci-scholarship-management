# CFCI Scholarship Management System

---

# Tech Stack

- Laravel 12
- PHP 8.2 to 8.5
- MySQL
- Laravel Breeze
- Blade
- Tailwind CSS
- Vite
- Node.js / npm
- Composer

---

# Important

This project uses **MySQL**, not SQLite.

Your `.env` should use:

```env
DB_CONNECTION=mysql
```

Do not use:

```env
DB_CONNECTION=sqlite
```

---

# 1. Install Laravel Herd

For macOS, the easiest setup is Laravel Herd.

Download it here:

https://herd.laravel.com

After installation, close Terminal and open it again.

Check PHP:

```bash
php -v
```

Check Composer:

```bash
composer --version
```

Laravel 12 requires PHP 8.2 or newer.

Recommended:

```text
PHP 8.4.x
```

---

# 2. Install Node.js and npm

Check whether Node.js is installed:

```bash
node -v
```

Check npm:

```bash
npm -v
```

If either command is not found, install Node.js from:

https://nodejs.org

After installation, reopen Terminal and check again:

```bash
node -v
npm -v
```

---

# 3. Install MySQL

Install MySQL from:

https://dev.mysql.com/downloads/mysql/

You may also use:

- MySQL Community Server
- DBngin
- Laravel Herd Pro database services
- MAMP
- XAMPP

For this project, use these database settings:

```text
Host: 127.0.0.1
Port: 3306
Database: cfci_scholarship_management
Username: root
Password: your local MySQL password
```

Make sure MySQL is running before starting Laravel.

---

# 4. Clone the Project

Copy and paste:

```bash
git clone <repository-url>
```

Then enter the project folder:

```bash
cd cfci-scholarship-management
```

Replace `<repository-url>` with the actual GitHub repository URL.

If the project is already cloned, just go to the project folder:

```bash
cd path/to/cfci-scholarship-management
```

Example:

```bash
cd ~/Documents/cfci-scholarship-management
```

---

# 5. Install Laravel / PHP Dependencies

Inside the project folder, run:

```bash
composer install
```

You do **not** need to install Laravel Breeze again.

Authentication is already included in the project.

---

# 6. Install Frontend Dependencies

Run:

```bash
npm install
```

---

# 7. Create the `.env` File

Copy the example environment file:

```bash
cp .env.example .env
```

Generate the Laravel application key:

```bash
php artisan key:generate
```

---

# 8. Create the MySQL Database

Open MySQL:

```bash
mysql -u root -p
```

Enter your MySQL password when asked.

Then copy and paste this inside MySQL:

```sql
CREATE DATABASE cfci_scholarship_management
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

Check that the database exists:

```sql
SHOW DATABASES;
```

Exit MySQL:

```sql
exit;
```

---

# 9. Configure `.env`

Open the `.env` file and use:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cfci_scholarship_management
DB_USERNAME=root
DB_PASSWORD=
```

If your MySQL user has a password, use:

```env
DB_PASSWORD=your_mysql_password
```

Example:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cfci_scholarship_management
DB_USERNAME=root
DB_PASSWORD=password123
```

Each developer should use their **own local MySQL password**.

Do not copy another developer's `.env` file unless the credentials are exactly the same.

---

# 10. Clear Laravel Configuration

After editing `.env`, run:

```bash
php artisan optimize:clear
```

---

# 11. Create the Database Tables

Run:

```bash
php artisan migrate
```

Check the migration status:

```bash
php artisan migrate:status
```

If the migrations show `Ran`, the database setup is working.

---

# 12. Optional: Run Database Seeders

Only run this if the project already contains seeders that are required:

```bash
php artisan db:seed
```

---

# 13. Build the Frontend

Run:

```bash
npm run build
```

---

# 14. Start the Project

The easiest way is:

```bash
composer run dev
```

Keep that Terminal window open.

Then open this in your browser:

```text
http://localhost:8000
```

You may also use:

```text
http://127.0.0.1:8000
```

---

# Important: Port 8000 vs 5173

Open the Laravel application here:

```text
http://localhost:8000
```

Do **not** use this as the main website:

```text
http://localhost:5173
```

Port `5173` is Vite's development server.

If you see a Laravel + Vite page on port `5173`, that is normal.

---

# Authentication

Laravel Breeze authentication is already installed.

Registration:

```text
http://localhost:8000/register
```

Login:

```text
http://localhost:8000/login
```

You do not need to run the Breeze installer again.

---

# First-Time Setup — Copy and Paste Version

After installing PHP, Composer, Node.js, npm, and MySQL, use the following commands.

## Step 1 — Clone the project

```bash
git clone <repository-url>
cd cfci-scholarship-management
```

## Step 2 — Install dependencies

```bash
composer install
npm install
```

## Step 3 — Create `.env`

```bash
cp .env.example .env
php artisan key:generate
```

## Step 4 — Create the database

```bash
mysql -u root -p
```

Then paste this inside MySQL:

```sql
CREATE DATABASE cfci_scholarship_management
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

Exit MySQL:

```sql
exit;
```

## Step 5 — Configure `.env`

Use:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cfci_scholarship_management
DB_USERNAME=root
DB_PASSWORD=
```

If MySQL has a password:

```env
DB_PASSWORD=your_mysql_password
```

## Step 6 — Finish setup

```bash
php artisan optimize:clear
php artisan migrate
npm run build
composer run dev
```

Then open:

```text
http://localhost:8000
```

---

# Daily Development Workflow

After the project has already been set up, use this each day.

## 1. Go to the project

```bash
cd path/to/cfci-scholarship-management
```

Example:

```bash
cd ~/Documents/cfci-scholarship-management
```

## 2. Get the latest code

```bash
git checkout main
git pull
```

## 3. Install any new dependencies

If you are unsure whether dependencies changed, it is safe to run:

```bash
composer install
npm install
```

## 4. Apply new database migrations

```bash
php artisan migrate
```

## 5. Start the application

```bash
composer run dev
```

Then open:

```text
http://localhost:8000
```

---

# Recommended Git Workflow for Our 2-Person Team

Before starting a new task:

```bash
git checkout main
git pull
```

Create a branch:

```bash
git checkout -b feature/name-of-task
```

Example:

```bash
git checkout -b feature/student-dashboard
```

Another example:

```bash
git checkout -b feature/admin-dashboard
```

Bug fix example:

```bash
git checkout -b fix/login-validation
```

After making changes, check what changed:

```bash
git status
```

Add the changes:

```bash
git add .
```

Commit:

```bash
git commit -m "Add student dashboard"
```

Push:

```bash
git push -u origin feature/student-dashboard
```

Then create a Pull Request on GitHub.

---

# If the Other Developer Pushes New Changes

Update your local project:

```bash
git checkout main
git pull
composer install
npm install
php artisan migrate
```

Then run:

```bash
composer run dev
```

---

# Database Team Rule

Each developer has their **own local MySQL database**.

Do not send MySQL database files to each other.

Share database structure using Laravel migrations.

For example, if Developer A adds a database column:

```bash
php artisan make:migration add_role_to_users_table --table=users
```

Developer A commits and pushes that migration.

Developer B gets it using:

```bash
git pull
php artisan migrate
```

This keeps both local databases synchronized.

---

# Important Git Rule: Never Commit `.env`

Never add `.env` to Git.

You can check Git status with:

```bash
git status
```

These should not be committed:

```text
.env
node_modules/
vendor/
```

If you add a new environment setting, add only a safe example to:

```text
.env.example
```

---

# Important Database Warning

Do not run this unless you intentionally want to delete all local database tables and data:

```bash
php artisan migrate:fresh
```

For normal development, use:

```bash
php artisan migrate
```

---

# Useful Commands

Check Laravel version:

```bash
php artisan --version
```

Check PHP:

```bash
php -v
```

Check Composer:

```bash
composer --version
```

Check Node.js:

```bash
node -v
```

Check npm:

```bash
npm -v
```

Check migrations:

```bash
php artisan migrate:status
```

Check Laravel routes:

```bash
php artisan route:list
```

Clear Laravel cache/config:

```bash
php artisan optimize:clear
```

Start Laravel only:

```bash
php artisan serve
```

Start Vite only:

```bash
npm run dev
```

Run both using Laravel's development script:

```bash
composer run dev
```

---

# Troubleshooting

## `php: command not found`

Install Laravel Herd:

https://herd.laravel.com

Then reopen Terminal and run:

```bash
php -v
```

---

## `composer: command not found`

Reopen Terminal after installing Laravel Herd.

Then run:

```bash
composer --version
```

---

## `node: command not found`

Install Node.js:

https://nodejs.org

Then reopen Terminal:

```bash
node -v
npm -v
```

---

## MySQL Connection Refused

Error may look like:

```text
SQLSTATE[HY000] [2002] Connection refused
```

Make sure MySQL is running.

Then verify `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cfci_scholarship_management
DB_USERNAME=root
DB_PASSWORD=
```

Clear Laravel config:

```bash
php artisan optimize:clear
```

Try again:

```bash
php artisan migrate
```

---

## Access Denied for MySQL User

If you get:

```text
Access denied for user 'root'
```

Check your `.env` password:

```env
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

Then run:

```bash
php artisan optimize:clear
```

---

## Unknown Database

If you see:

```text
Unknown database 'cfci_scholarship_management'
```

Open MySQL:

```bash
mysql -u root -p
```

Create the database:

```sql
CREATE DATABASE cfci_scholarship_management
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

Exit:

```sql
exit;
```

Then migrate:

```bash
php artisan migrate
```

---

## Missing Application Key

If Laravel says:

```text
No application encryption key has been specified
```

Run:

```bash
php artisan key:generate
```

---

## Page Has No Styling

Run:

```bash
npm install
npm run build
```

Then restart:

```bash
composer run dev
```

---

## `.env` Changes Are Not Working

Run:

```bash
php artisan optimize:clear
```

Then restart:

```bash
composer run dev
```

---

## Port 8000 Is Already Being Used

Start Laravel on another port:

```bash
php artisan serve --port=8001
```

Start Vite in another Terminal:

```bash
npm run dev
```

Open:

```text
http://127.0.0.1:8001
```

---

# New Developer Checklist

Before asking for help, check these:

- [ ] Laravel Herd installed
- [ ] PHP works
- [ ] Composer works
- [ ] Node.js works
- [ ] npm works
- [ ] MySQL installed
- [ ] MySQL running
- [ ] Repository cloned
- [ ] `composer install` completed
- [ ] `npm install` completed
- [ ] `.env` created
- [ ] Laravel application key generated
- [ ] `cfci_scholarship_management` database created
- [ ] `.env` configured for MySQL
- [ ] `php artisan migrate` completed
- [ ] `npm run build` completed
- [ ] `composer run dev` is running
- [ ] App opens at `http://localhost:8000`
- [ ] Login works
- [ ] Registration works

---

# Quick Setup Check

Run all of these:
test

```bash
php -v
composer --version
node -v
npm -v
php artisan --version
php artisan migrate:status
```

Then start the project:

```bash
composer run dev
```

Open:

```text
http://localhost:8000
```

If the application loads and login/register work, setup is complete.

---

# Project Information

**Project:** CFCI Scholarship Management System

**Framework:** Laravel 12

**Database:** MySQL

**Authentication:** Laravel Breeze

**Frontend:** Blade + Tailwind CSS

**Team:** 2 developers

---

# Official References

Laravel 12:

https://laravel.com/docs/12.x

Laravel Installation:

https://laravel.com/docs/12.x/installation

Laravel Herd:

https://herd.laravel.com

MySQL:

https://dev.mysql.com/downloads/mysql/

Node.js:

https://nodejs.org
