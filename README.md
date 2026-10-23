# Grade A Paint Center System

A point of sale, inventory management, and custom paint mixing system built for retail paint centers. Developed with Laravel, Livewire Volt, Alpine.js, and Tailwind CSS.

---

## Overview

Grade A Paint Center System manages retail paint transactions, bulk and fractional container inventory, customer quotations, custom color formulation queues, physical inventory audits, and sales performance reporting.

---

## Features

### Point of Sale (POS)
- **Product Catalog Browser**: Filter by brand, category, package unit, and stock availability with table and grid layout toggles.
- **Retail & Fractional Sales**: Sell full standard units (gallons, pails) or repacked fractional sizes (liters, 1/2 liter, 1/4 liter, 1/8 liter).
- **Cart & Tender Validation**: Real-time validation of cash tendered, change calculation, and payment processing.
- **Discounts**: Built-in support for Senior Citizen, PWD, Employee, and Custom discounts with authorization tracking.
- **Receipts**: Printable sales receipts with itemized breakdown, tax details, discount authorization, and invoice numbers.

### Custom Paint Mixing
- **Formula Queue**: Track custom mix requests across stages (`pending_mix`, `mixed`, `ready_for_pickup`, `completed`).
- **Downpayment Workflow**: Accept initial downpayments during order intake before formulation.
- **Material Tracking**: Log specific base paints and tinting colorants used in mixing formulas.
- **Automatic Price Resolution**: Calculate final mix pricing using the highest component price basis.
- **POS Integration**: Collect remaining balances directly through the standard checkout screen with automatic change calculation.

### Inventory Management
- **Stock-In**: Inbound inventory recording with required Delivery Receipt (DR) number tracking and supplier records.
- **Repack Module**: Convert whole units into fractional units with automatic stock deduction and creation of repacked inventory.
- **Stock Movements**: Comprehensive transaction log capturing stock additions, sales deductions, adjustments, and mixing usage.
- **Physical Count & Audit**: Periodic stocktaking with automated variance detection and inventory reconciliation.
- **Purchase Orders**: Order requisition creation, tracking (draft, ordered, received), and PDF generation.

### Quotations
- **Quotation Builder**: Create customer estimates with configurable validity periods, itemized pricing, and optional discounts.
- **Print View**: Clean print-ready format for customer delivery.
- **Cart Conversion**: Load accepted quotations directly into the active POS cart for checkout.

### Reports & Analytics
- **Dashboard**: Real-time overview of daily sales, low stock alerts, stockout warnings, and mixing queues.
- **Sales Report**: Revenue metrics, transaction breakdowns, date range presets, and PDF export.
- **General Inventory Report**: Active SKU count, inventory valuation, stock distribution, and printable PDF summaries.
- **Mixing Paint Usage Report**: Track paints consumed during mixing operations derived from physical count variances.

### System Administration
- **Role-Based Access Control**: Strict authorization tiers for Superadmin, Admin, Manager, and Mixer roles.
- **User Management**: Create, edit, activate/deactivate accounts, and reset credentials.
- **Audit Logs**: Immutable log of authentication attempts, inventory adjustments, and administrative operations.
- **Database Backups**: Automated and manual database backup management via Spatie Laravel Backup.
- **Master References**: Maintain brand catalogs, categories, and package units.

---

## User Roles & Permissions

| Capability | Superadmin | Admin | Manager | Mixer |
| :--- | :---: | :---: | :---: | :---: |
| Point of Sale (POS) | Yes | Yes | Yes | No |
| Final Checkout & Balance Collection | Yes | Yes | Yes | No |
| Sales History & Receipts | Yes | Yes | Yes | No |
| Sales Reports | Yes | Yes | Yes | No |
| Custom Mix Queue & Formulas | Yes | Yes | Yes | Yes |
| Quotations Management | Yes | Yes | Yes | Yes |
| Product Catalog Management | Yes | Yes | Yes | View Only |
| Purchase Orders | Yes | Yes | Yes | No |
| Stock-In & Repacking | Yes | Yes | Yes | Yes |
| Physical Counts & Audits | Yes | Yes | Yes | Yes |
| Inventory Reports & Usage | Yes | Yes | Yes | Yes |
| User Access Management | Yes | Yes | No | No |
| Audit Trail | Yes | Yes | No | No |
| System Backups | Yes | Yes | No | No |
| System Troubleshooting | Yes | No | No | No |

---

## Tech Stack

- **Framework**: Laravel 13
- **Language**: PHP 8.3+
- **Frontend / Reactivity**: Livewire 3 with Volt, Alpine.js
- **Styling**: Tailwind CSS v4, Vite
- **Database**: SQLite (default / testing) or MySQL 8.0+
- **PDF Engine**: DomPDF (`barryvdh/laravel-dompdf`)
- **Backups**: Spatie Laravel Backup
- **Testing**: Pest PHP
- **Code Standards**: Laravel Pint

---

## Requirements

Ensure the host environment meets the following requirements:
- PHP >= 8.3
  - Extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`
- Composer >= 2.0
- Node.js >= 18.x & NPM
- SQLite 3 or MySQL >= 8.0

---

## Installation & Setup

### 1. Clone the Repository
```bash
git clone <repository-url>
cd GradeAPaintCenterSystem
```

### 2. Environment Configuration
Copy the sample environment file:
```bash
cp .env.example .env
```

Configure your database connection in `.env`. For SQLite:
```ini
DB_CONNECTION=sqlite
# DB_DATABASE=/absolute/path/to/database/database.sqlite
```
For MySQL:
```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=grade_a_paint_center
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 3. Install Dependencies
```bash
composer install
npm install
```

### 4. Application Key & Database Setup
```bash
php artisan key:generate
php artisan migrate --seed
```

If using SQLite, ensure the database file exists before migrating:
```bash
touch database/database.sqlite
php artisan migrate --seed
```

### 5. Build Frontend Assets
For production:
```bash
npm run build
```
For local development with hot reload:
```bash
npm run dev
```

### 6. Start the Server
```bash
php artisan serve
```
Access the application at `http://localhost:8000`.

---

## Initial Administrator Setup

If setting up without pre-existing user records, create an initial administrator account using Tinker:

```bash
php artisan tinker
```
```php
App\Models\User::create([
    'name' => 'System Administrator',
    'username' => 'admin',
    'password' => Illuminate\Support\Facades\Hash::make('password123'),
    'role' => 'admin',
    'active' => true,
]);
```

Log in using the `admin` username and `password123` password at `http://localhost:8000/login`. Additional accounts can be created from the **System Settings &rarr; Users** panel.

---

## Testing & Quality

### Run Test Suite
The project uses Pest for automated testing:
```bash
php artisan test --compact
```
Or run directly:
```bash
vendor/bin/pest
```

### Code Formatting
Ensure PHP code matches project styling standards:
```bash
vendor/bin/pint --dirty --format agent
```

---

## Directory Structure

```
GradeAPaintCenterSystem/
├── app/
│   ├── Http/Controllers/     # PDF, receipt, and export controllers
│   ├── Models/               # Eloquent models (Sale, Product, Inventory, Mixing, etc.)
│   └── Support/              # Utility helpers (Currency, formatting)
├── database/
│   ├── factories/            # Model factories for testing
│   ├── migrations/           # Normalized database schema migrations
│   └── seeders/              # Master reference catalogs and mock data
├── resources/
│   ├── css/                  # Application stylesheets
│   └── views/
│       ├── layouts/          # Application layouts (app, guest, receipt)
│       ├── livewire/
│       │   ├── layout/       # Navigation and header components
│       │   └── pages/        # Volt single-file components
│       │       ├── admin/    # System backups
│       │       ├── audit/    # Audit logs
│       │       ├── inventory/# Stock-in, repack, physical count, movements
│       │       ├── products/ # Products & purchase orders
│       │       ├── reports/  # Inventory, sales, and mixing usage reports
│       │       ├── sales/    # POS, custom mixes, checkout, quotations, history
│       │       └── user-access/ # User accounts & permissions
│       └── reports/          # PDF print templates
├── routes/
│   ├── web.php               # Web and Volt page routes
│   └── auth.php              # Authentication routes
└── tests/
    └── Feature/              # Feature and regression test suites
```

---

## License

This software is developed for internal store operations. All rights reserved.
