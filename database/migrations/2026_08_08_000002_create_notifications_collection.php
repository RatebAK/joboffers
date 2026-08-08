<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('mongodb')->getMongoDB();

        try {
            $db->createCollection('notifications');
        } catch (\Exception $e) {
            // Collection may already exist
        }

        $collection = $db->selectCollection('notifications');
        $collection->createIndex(['user_id'  => 1]);
        $collection->createIndex(['read_at'  => 1]);
        $collection->createIndex(['user_id'  => 1, 'read_at' => 1]);
        $collection->createIndex(['created_at' => -1]);
    }

    public function down(): void
    {
        DB::connection('mongodb')->getMongoDB()->dropCollection('notifications');
    }
};
