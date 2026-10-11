<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealisticMockProductsSeeder extends Seeder
{
    /**
     * Seed realistic small-scale store mock products matching existing catalog.
     */
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first() ?? User::first();
        $adminId = $admin?->id;

        // 1. Resolve existing Brands
        $guilder = Brand::firstOrCreate(['name' => 'Guilder'], ['active' => true]);
        $illinois = Brand::firstOrCreate(['name' => 'Illinois'], ['active' => true]);

        // 2. Resolve existing Categories
        $autoTintCat = Category::firstOrCreate(['name' => 'Automotive Acrylic Tinting'], ['active' => true]);
        $acrylicMixCat = Category::firstOrCreate(['name' => 'Acrylic Mixing'], ['active' => true]);
        $toolsCat = Category::firstOrCreate(['name' => 'Tools'], ['active' => true]);

        // 3. Resolve existing Package Units
        $gallonUnit = PackageUnit::where('name', 'gallon')->first()
            ?? PackageUnit::firstOrCreate(['name' => 'gallon'], ['abbreviation' => 'gal', 'is_for_paint' => true, 'active' => true]);
        $literUnit = PackageUnit::where('name', 'liter')->first()
            ?? PackageUnit::firstOrCreate(['name' => 'liter'], ['abbreviation' => 'L', 'is_for_paint' => true, 'gallon_fraction' => 0.2500, 'active' => true]);
        $pieceUnit = PackageUnit::where('name', 'pieces')->orWhere('name', 'piece')->first()
            ?? PackageUnit::firstOrCreate(['name' => 'pieces'], ['abbreviation' => 'pcs', 'is_for_paint' => false, 'active' => true]);

        // 4. Update existing 0-stock products with realistic inventory if needed
        $existingPaintRoller = Product::where('name', 'Paint Roller')->where('category_id', $toolsCat->id)->first();
        if ($existingPaintRoller) {
            $this->ensureInventory($existingPaintRoller, 8.0, 4.0, $adminId);
        }

        $existingCrimsonRed = Product::where('name', 'Crimson Red')->where('brand_id', $guilder->id)->first();
        if ($existingCrimsonRed) {
            $this->ensureInventory($existingCrimsonRed, 4.0, 2.0, $adminId);
        }

        $existingLemonYellow = Product::where('name', 'Lemon Yellow')->where('brand_id', $illinois->id)->first();
        if ($existingLemonYellow && $existingLemonYellow->getStorefrontStock() == 0) {
            $this->ensureInventory($existingLemonYellow, 15.0, 3.0, $adminId);
        }

        // 5. Catalog of realistic mock products for a Philippine automotive paint shop
        $mockCatalog = [
            // ── Guilder: Automotive Acrylic Tinting ──────────────────────────
            [
                'name' => 'Jet Black',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 390.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Jet Black',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 135.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 8.00,
                'storefront_stock' => 4.00,
            ],
            [
                'name' => 'Signal Red',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 420.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 4.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Signal Red',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 145.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Phthalo Blue',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 460.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 3.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Phthalo Blue',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 150.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 5.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Emerald Green',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 440.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 3.00,
                'storefront_stock' => 1.00,
            ],
            [
                'name' => 'Emerald Green',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 145.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 4.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Chrome Yellow',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 430.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 4.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Chrome Yellow',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 140.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 5.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Medium Aluminum Metallic',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 520.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 3.00,
                'storefront_stock' => 1.00,
            ],
            [
                'name' => 'Fine Aluminum Metallic',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 540.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 2.00,
                'storefront_stock' => 1.00,
            ],
            [
                'name' => 'Oxide Yellow',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 130.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 4.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Oxide Red',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 130.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 3.00,
                'storefront_stock' => 1.00,
            ],
            [
                'name' => 'Raw Sienna',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 125.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 2.00,
                'storefront_stock' => 1.00, // Total 3.00 -> low stock
            ],
            [
                'name' => 'Burnt Umber',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 125.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 0.00,
                'storefront_stock' => 0.00, // Out of stock
            ],
            [
                'name' => 'Violet Tint',
                'brand_id' => $guilder->id,
                'category_id' => $autoTintCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 160.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 2.00,
                'storefront_stock' => 0.00, // Total 2.00 -> low stock
            ],

            // ── Guilder: Acrylic Mixing / Primers / Clears ────────────────────
            [
                'name' => 'Acrylic Primer Surfacer Gray',
                'brand_id' => $guilder->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 440.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 8.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Acrylic Primer Surfacer Gray',
                'brand_id' => $guilder->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 145.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Wash Primer with Catalyst',
                'brand_id' => $guilder->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 650.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 4.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Acrylic Thinner Premium',
                'brand_id' => $guilder->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 350.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 12.00,
                'storefront_stock' => 5.00,
            ],
            [
                'name' => 'Acrylic Thinner Premium',
                'brand_id' => $guilder->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 105.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 10.00,
                'storefront_stock' => 6.00,
            ],
            [
                'name' => 'Clear Gloss Topcoat',
                'brand_id' => $guilder->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 590.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Topcoat Mixing Clear Binder',
                'brand_id' => $guilder->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 480.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 5.00,
                'storefront_stock' => 2.00,
            ],

            // ── Illinois: Acrylic Mixing Bases, Colors & Solvents ──────────────
            [
                'name' => 'Super White Base',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 380.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 10.00,
                'storefront_stock' => 4.00,
            ],
            [
                'name' => 'Super White Base',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 130.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 8.00,
                'storefront_stock' => 4.00,
            ],
            [
                'name' => 'Deep Black Base',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 360.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 8.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Deep Black Base',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 125.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Signal Red',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 410.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 4.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Royal Blue',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 430.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 4.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Lemon Yellow',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 120.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 5.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'Acrylic Primer Surfacer Gray',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 420.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 10.00,
                'storefront_stock' => 5.00,
            ],
            [
                'name' => 'Acrylic Primer Surfacer Gray',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 140.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 4.00,
            ],
            [
                'name' => 'Acrylic Primer Surfacer White',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 430.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 5.00,
                'storefront_stock' => 2.00,
            ],
            [
                'name' => 'High Gloss Acrylic Clear Coat',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 580.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 8.00,
                'storefront_stock' => 4.00,
            ],
            [
                'name' => 'High Gloss Acrylic Clear Coat',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 180.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 3.00,
            ],
            [
                'name' => 'Acrylic Thinner Standard',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 320.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 14.00,
                'storefront_stock' => 6.00,
            ],
            [
                'name' => 'Acrylic Thinner Standard',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $literUnit->id,
                'selling_price' => 95.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 12.00,
                'storefront_stock' => 8.00,
            ],
            [
                'name' => 'Acrylic Topcoat Clear Base',
                'brand_id' => $illinois->id,
                'category_id' => $acrylicMixCat->id,
                'package_unit_id' => $gallonUnit->id,
                'selling_price' => 460.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => true,
                'storage_stock' => 6.00,
                'storefront_stock' => 2.00,
            ],

            // ── Tools & Preparation Materials ────────────────────────────────
            [
                'name' => 'Baby Roller 4"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 45.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 15.00,
                'storefront_stock' => 10.00,
            ],
            [
                'name' => 'Baby Roller Refill 4"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 25.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 30.00,
                'storefront_stock' => 15.00,
            ],
            [
                'name' => 'Paint Roller 7" Complete',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 110.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 10.00,
                'storefront_stock' => 5.00,
            ],
            [
                'name' => 'Paint Roller Refill 7"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 55.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 20.00,
                'storefront_stock' => 10.00,
            ],
            [
                'name' => 'Paint Brush 1"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 30.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 20.00,
                'storefront_stock' => 8.00,
            ],
            [
                'name' => 'Paint Brush 1.5"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 38.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 18.00,
                'storefront_stock' => 8.00,
            ],
            [
                'name' => 'Paint Brush 2"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 45.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 25.00,
                'storefront_stock' => 12.00,
            ],
            [
                'name' => 'Paint Brush 3"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 70.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 15.00,
                'storefront_stock' => 6.00,
            ],
            [
                'name' => 'Paint Brush 4"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 95.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 10.00,
                'storefront_stock' => 4.00,
            ],
            [
                'name' => 'Putty Knife (Paleta) 3"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 55.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 12.00,
                'storefront_stock' => 6.00,
            ],
            [
                'name' => 'Putty Knife (Paleta) 4"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 65.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 10.00,
                'storefront_stock' => 5.00,
            ],
            [
                'name' => 'Masking Tape 1"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 35.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 40.00,
                'storefront_stock' => 20.00,
            ],
            [
                'name' => 'Masking Tape 2"',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 65.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 25.00,
                'storefront_stock' => 10.00,
            ],
            [
                'name' => 'Waterproof Sandpaper #240',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 18.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 50.00,
                'storefront_stock' => 25.00,
            ],
            [
                'name' => 'Waterproof Sandpaper #400',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 18.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 60.00,
                'storefront_stock' => 30.00,
            ],
            [
                'name' => 'Waterproof Sandpaper #1000',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 22.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 40.00,
                'storefront_stock' => 15.00,
            ],
            [
                'name' => 'Plastic Paint Mixing Cup 1L',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 35.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 20.00,
                'storefront_stock' => 10.00,
            ],
            [
                'name' => 'Paint Strainer Mesh Funnel',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 15.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 50.00,
                'storefront_stock' => 25.00,
            ],
            [
                'name' => 'Spray Gun Cleaning Brush Set',
                'brand_id' => null,
                'category_id' => $toolsCat->id,
                'package_unit_id' => $pieceUnit->id,
                'selling_price' => 120.00,
                'low_stock_threshold' => 5.00,
                'requires_physical_check' => false,
                'storage_stock' => 5.00,
                'storefront_stock' => 2.00,
            ],
        ];

        DB::transaction(function () use ($mockCatalog, $adminId): void {
            foreach ($mockCatalog as $item) {
                // Find or create product matching brand, category, unit, name
                $product = Product::where('name', $item['name'])
                    ->where('brand_id', $item['brand_id'])
                    ->where('category_id', $item['category_id'])
                    ->where('package_unit_id', $item['package_unit_id'])
                    ->first();

                if (! $product) {
                    $sku = 'PROD-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
                    while (Product::where('sku', $sku)->exists()) {
                        $sku = 'PROD-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
                    }

                    $product = Product::create([
                        'brand_id' => $item['brand_id'],
                        'category_id' => $item['category_id'],
                        'package_unit_id' => $item['package_unit_id'],
                        'name' => $item['name'],
                        'sku' => $sku,
                        'package_size' => 1.00,
                        'selling_price' => $item['selling_price'],
                        'low_stock_threshold' => $item['low_stock_threshold'],
                        'manufacturer_code' => null,
                        'description' => null,
                        'active' => true,
                        'requires_physical_check' => $item['requires_physical_check'],
                    ]);
                } else {
                    $product->update([
                        'selling_price' => $item['selling_price'],
                        'low_stock_threshold' => $item['low_stock_threshold'],
                        'requires_physical_check' => $item['requires_physical_check'],
                        'active' => true,
                    ]);
                }

                $this->ensureInventory($product, $item['storage_stock'], $item['storefront_stock'], $adminId);
            }
        });
    }

    /**
     * Helper to set inventory and initial movement log.
     */
    private function ensureInventory(Product $product, float $storageQty, float $storefrontQty, ?int $adminId): void
    {
        // 1. Storage location
        $storage = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'location' => 'storage'],
            ['quantity' => 0]
        );
        if ((float) $storage->quantity == 0 && $storageQty > 0) {
            $storage->update(['quantity' => $storageQty]);
            InventoryMovement::create([
                'product_id' => $product->id,
                'location' => 'storage',
                'user_id' => $adminId,
                'type' => 'stock_in',
                'quantity_change' => $storageQty,
                'quantity_before' => 0,
                'quantity_after' => $storageQty,
                'reference_text' => 'Initial Store Stock (Storage)',
            ]);
        }

        // 2. Storefront location
        $storefront = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'location' => 'storefront'],
            ['quantity' => 0]
        );
        if ((float) $storefront->quantity == 0 && $storefrontQty > 0) {
            $storefront->update(['quantity' => $storefrontQty]);
            InventoryMovement::create([
                'product_id' => $product->id,
                'location' => 'storefront',
                'user_id' => $adminId,
                'type' => 'stock_in',
                'quantity_change' => $storefrontQty,
                'quantity_before' => 0,
                'quantity_after' => $storefrontQty,
                'reference_text' => 'Initial Storefront Stock',
            ]);
        }
    }
}
