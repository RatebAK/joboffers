<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * MongoDB equivalent of Laravel's RefreshDatabase trait.
 *
 * There are no schema migrations for the document store, so instead of
 * migrating we simply drop every collection in the test database before each
 * test. This guarantees each test starts from a clean, isolated state and
 * removes the need for the manual `$model->delete()` cleanup that used to
 * clutter the end of every test.
 */
trait RefreshMongoDatabase
{
    protected function setUpRefreshMongoDatabase(): void
    {
        $db = DB::connection('mongodb')->getMongoDB();

        foreach ($db->listCollectionNames() as $collection) {
            $db->dropCollection($collection);
        }
    }
}
