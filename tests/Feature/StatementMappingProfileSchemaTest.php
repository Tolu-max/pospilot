<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatementMappingProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_mapping_composite_index_uses_a_mysql_safe_name(): void
    {
        $indexName = 'statement_mapping_profiles_agent_provider_schema_idx';
        $indexes = Schema::getIndexListing('statement_mapping_profiles');

        $this->assertContains($indexName, $indexes);
        $this->assertLessThanOrEqual(64, strlen($indexName));
    }
}
