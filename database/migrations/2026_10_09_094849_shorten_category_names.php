<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $renames = [
            'Architectural & Decorative Paints' => 'Decorative Paints',
            'Enamels & Gloss Finishes' => 'Enamels & Gloss',
            'Roof & Elastomeric Paints' => 'Roof & Elastomeric',
            'Primers, Sealers & Undercoats' => 'Primers & Sealers',
            'Automotive & Industrial Coatings' => 'Automotive Coatings',
            'Thinners, Solvents & Reducers' => 'Thinners & Solvents',
            'Tinting Colors & Colorants' => 'Tinting Colors',
            'Putties, Fillers & Sealants' => 'Putties & Fillers',
            'Painting Tools & Applicators' => 'Painting Tools',
            'Hardware Tools & Accessories' => 'Hardware & Tools',
            'Abrasives & Surface Preparation' => 'Abrasives & Prep',
        ];

        foreach ($renames as $oldName => $newName) {
            DB::table('categories')->where('name', $oldName)->update(['name' => $newName]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $renames = [
            'Decorative Paints' => 'Architectural & Decorative Paints',
            'Enamels & Gloss' => 'Enamels & Gloss Finishes',
            'Roof & Elastomeric' => 'Roof & Elastomeric Paints',
            'Primers & Sealers' => 'Primers, Sealers & Undercoats',
            'Automotive Coatings' => 'Automotive & Industrial Coatings',
            'Thinners & Solvents' => 'Thinners, Solvents & Reducers',
            'Tinting Colors' => 'Tinting Colors & Colorants',
            'Putties & Fillers' => 'Putties, Fillers & Sealants',
            'Painting Tools' => 'Painting Tools & Applicators',
            'Hardware & Tools' => 'Hardware Tools & Accessories',
            'Abrasives & Prep' => 'Abrasives & Surface Preparation',
        ];

        foreach ($renames as $newName => $oldName) {
            DB::table('categories')->where('name', $newName)->update(['name' => $oldName]);
        }
    }
};
