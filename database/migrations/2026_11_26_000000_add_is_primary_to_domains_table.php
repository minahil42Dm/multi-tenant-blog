<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing tenants keep their oldest domain as the primary one.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('tenant_id');
            $table->index(['tenant_id', 'is_primary']);
        });

        $firstDomainIds = DB::table('domains')
            ->selectRaw('min(id) as id')
            ->groupBy('tenant_id')
            ->pluck('id')
            ->all();

        if ($firstDomainIds !== []) {
            DB::table('domains')->whereIn('id', $firstDomainIds)->update(['is_primary' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_primary']);
            $table->dropColumn('is_primary');
        });
    }
};
