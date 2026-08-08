<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MongoDB — create collection with indexes
        $db = DB::connection('mongodb')->getMongoDB();

        // Ensure collection exists
        try {
            $db->createCollection('audit_logs');
        } catch (\Exception $e) {
            // Collection may already exist — safe to ignore
        }

        $collection = $db->selectCollection('audit_logs');
        $collection->createIndex(['actor_id'   => 1]);
        $collection->createIndex(['created_at' => -1]);
        $collection->createIndex(['action'     => 1]);
    }

    public function down(): void
    {
        DB::connection('mongodb')->getMongoDB()->dropCollection('audit_logs');
    }
};
