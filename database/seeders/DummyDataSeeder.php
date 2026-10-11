<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\PackageUnit;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        // Get package units
        $gallon = PackageUnit::where('name', 'gallon')->first();
        $liter = PackageUnit::where('name', 'liter')->first();
        $piece = PackageUnit::where('name', 'piece')->first();
        $pail = PackageUnit::where('name', 'Pail')->first();

        // Create Brands
        $boysen = Brand::firstOrCreate(['name' => 'Boysen'], ['active' => true]);
        $davies = Brand::firstOrCreate(['name' => 'Davies'], ['active' => true]);
        $triton = Brand::firstOrCreate(['name' => 'Triton'], ['active' => true]);
        $stanley = Brand::firstOrCreate(['name' => 'Stanley'], ['active' => true]);
        $generic = Brand::firstOrCreate(['name' => 'Generic'], ['active' => true]);

        // Create Categories
        $paintCat = Category::firstOrCreate(['name' => 'Paints'], ['active' => true]);
        $chemicalCat = Category::firstOrCreate(['name' => 'Chemicals & Thinners'], ['active' => true]);
        $hardwareCat = Category::firstOrCreate(['name' => 'Hardware & Tools'], ['active' => true]);

        $dummyProducts = [
            // Paints
            ['brand_id' => $boysen->id, 'category_id' => $paintCat->id, 'package_unit_id' => $gallon->id, 'name' => 'Boysen Permacoat Flat White', 'sku' => 'BOY-PER-FW-GAL', 'price' => 750, 'low_stock' => 5, 'stock' => 12],
            ['brand_id' => $boysen->id, 'category_id' => $paintCat->id, 'package_unit_id' => $gallon->id, 'name' => 'Boysen Permacoat Semi-Gloss White', 'sku' => 'BOY-PER-SGW-GAL', 'price' => 850, 'low_stock' => 5, 'stock' => 3], // low stock
            ['brand_id' => $boysen->id, 'category_id' => $paintCat->id, 'package_unit_id' => $pail->id, 'name' => 'Boysen Permacoat Flat White', 'sku' => 'BOY-PER-FW-PAIL', 'price' => 2900, 'low_stock' => 2, 'stock' => 4],
            ['brand_id' => $davies->id, 'category_id' => $paintCat->id, 'package_unit_id' => $gallon->id, 'name' => 'Davies Megacryl Flat White', 'sku' => 'DAV-MEG-FW-GAL', 'price' => 730, 'low_stock' => 5, 'stock' => 10],
            ['brand_id' => $davies->id, 'category_id' => $paintCat->id, 'package_unit_id' => $liter->id, 'name' => 'Davies Sun & Rain Tinting Color', 'sku' => 'DAV-SR-TINT-L', 'price' => 220, 'low_stock' => 10, 'stock' => 1], // low stock

            // Chemicals
            ['brand_id' => $generic->id, 'category_id' => $chemicalCat->id, 'package_unit_id' => $gallon->id, 'name' => 'Paint Thinner', 'sku' => 'GEN-THIN-GAL', 'price' => 350, 'low_stock' => 5, 'stock' => 20],
            ['brand_id' => $generic->id, 'category_id' => $chemicalCat->id, 'package_unit_id' => $liter->id, 'name' => 'Lacquer Thinner', 'sku' => 'GEN-LAC-L', 'price' => 120, 'low_stock' => 10, 'stock' => 0], // out of stock

            // Hardware & Tools
            ['brand_id' => $stanley->id, 'category_id' => $hardwareCat->id, 'package_unit_id' => $piece->id, 'name' => 'Stanley Paint Brush 2"', 'sku' => 'STA-BRSH-2', 'price' => 85, 'low_stock' => 20, 'stock' => 45],
            ['brand_id' => $stanley->id, 'category_id' => $hardwareCat->id, 'package_unit_id' => $piece->id, 'name' => 'Stanley Paint Roller 9"', 'sku' => 'STA-ROLL-9', 'price' => 150, 'low_stock' => 15, 'stock' => 8], // low stock
            ['brand_id' => $generic->id, 'category_id' => $hardwareCat->id, 'package_unit_id' => $piece->id, 'name' => 'Masking Tape 1"', 'sku' => 'GEN-TAPE-1', 'price' => 35, 'low_stock' => 50, 'stock' => 120],
        ];

        foreach ($dummyProducts as $p) {
            $product = Product::create([
                'brand_id' => $p['brand_id'],
                'category_id' => $p['category_id'],
                'package_unit_id' => $p['package_unit_id'],
                'name' => $p['name'],
                'sku' => $p['sku'],
                'selling_price' => $p['price'],
                'low_stock_threshold' => $p['low_stock'],
                'active' => true,
            ]);

            // Add bulk inventory
            if ($p['stock'] > 0) {
                Inventory::create([
                    'product_id' => $product->id,
                    'location' => 'storefront',
                    'quantity' => $p['stock'],
                ]);
            }
        }
    }
}
