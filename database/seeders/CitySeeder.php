<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            // Governorate capitals
            'Damascus',
            'Aleppo',
            'Homs',
            'Hama',
            'Latakia',
            'Tartus',
            'Deir ez-Zor',
            'Raqqa',
            'Hasakah',
            'Daraa',
            'As-Suwayda',
            'Quneitra',
            'Idlib',
            // Major cities / districts
            'Qamishli',
            'Manbij',
            'Palmyra',
            'Rif Dimashq',
            'Jaramana',
            'Douma',
            'Al-Bab',
            'Afrin',
            'Kobani',
            'Deir Hafer',
            'Al-Thawrah',
            'Jisr ash-Shughur',
            'Baniyas',
            'Jableh',
        ];

        foreach ($cities as $name) {
            City::firstOrCreate(['name' => $name]);
        }
    }
}
