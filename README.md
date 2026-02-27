# Laravel Model History

A simple yet powerful Laravel package to track every change in your Eloquent models. This package automatically manages history tables and tracks who made the changes and from which URL.

## Features

- **Automatic Change Tracking**: Log `created`, `updated`, and `deleted` events.
- **Dynamic History Tables**: Generate and synchronize history tables based on your original models.
- **User Auditing**: Track which user performed the action (`recorded_by`).
- **Contextual Tracking**: Store the URL where the action occurred (`recorded_url`).
- **Automated Metadata**: Manage `created_by`, `updated_by`, and `deleted_by` fields automatically.
- **Bulk Injection**: Commands to inject traits into all your models at once.
- **Easy Cleanup**: Built-in command to prune old history records.

## Requirements

- **PHP**: ^8.2
- **Laravel**: ^10.0 or ^11.0

## Installation

You can install the package via composer:

```bash
composer require lara-pack/model-history
```

### Local Installation (Development)

If you are developing locally and haven't published the package yet, you can add it to your `composer.json` using a path repository:

```json
"repositories": [
    {
        "type": "path",
        "url": "../path/to/lara-pack-model-history"
    }
],
"require": {
    "lara-pack/model-history": "dev-main"
}
```

Then run:

```bash
composer update
```

## Setup

### Commands

The package provides several artisan commands to simplify the setup:

#### 1. Sync History Tables

This command scans your models and generates migrations for your history tables. History tables are prefixed with `_history_`.

```bash
php artisan lara-pack:sync-history
```

Then run the migration command to apply the changes:

```bash
php artisan migrate
```

_Migrations will be created in `database/migrations/histories/Y_m_d/` and are automatically loaded by the package._

#### 2. Bulk Inject Traits

Inject the `HasHistory` or `HasCompleteHistory` traits into all models within a directory (default: `app/Models`).

```bash
# To track changes (creates _history_ tables data)
php artisan lara-pack:inject-has-history

# To add metadata (created_by, updated_by, deleted_by)
php artisan lara-pack:inject-has-complete-history
```

### Manual Usage

#### Tracking Changes

Add the `HasHistory` trait to any model you want to track:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaraPack\ModelHistory\Traits\HasHistory;

class Post extends Model
{
    use HasHistory;
}
```

#### Tracking Metadata (Created By, etc.)

Add the `HasCompleteHistory` trait to manage auditing fields:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaraPack\ModelHistory\Traits\HasCompleteHistory;

class Post extends Model
{
    use HasCompleteHistory;
}
```

_Note: For `HasCompleteHistory`, your migration should include the necessary columns. You can use the provided Blueprint macro:_

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    // ...
    $table->completeHistory(); // Adds timestamps, softDeletes, and audit columns
});
```

## Management

### Cleaning History

If your history tables grow too large, you can clean them up by date:

```bash
# Clean records older than 2025-01-01
php artisan lara-pack:clean-history 2025-01-01

# Clean a specific table
php artisan lara-pack:clean-history 2025-01-01 --table=posts

# Force deletion without confirmation
php artisan lara-pack:clean-history 2025-01-01 --force
```

## Database Schema

The history tables (`_history_tablename`) will contain all columns from the original table plus:

- `recorded_at`: Timestamp of the action.
- `recorded_by`: ID of the user who performed the action.
- `recorded_action`: The action performed (`created`, `updated`, `deleted`).
- `recorded_url`: The URL context of the action.

## License

The MIT License (MIT).
