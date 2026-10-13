<?php

namespace Database\Seeders;

use App\Models\CatalogEntry;
use App\Models\CatalogNode;
use App\Models\CatalogVersion;
use App\Services\Catalog\CatalogMutex;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('data/service_catalog_initial.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($data) {
            CatalogMutex::lock();
            foreach ($data['nodes'] as $d) {
                if (CatalogNode::where('kind', $d['kind'])->where('code', $d['code'])->exists()) {
                    continue;
                }
                $kind = match ($d['kind']) {
                    'jurisdiction' => 'country','specialty' => 'domain','service' => 'specialty',default => null
                };
                $parent = $kind ? CatalogNode::where('kind', $kind)->where('code', $d['parentCode'])->firstOrFail() : null;
                $n = new CatalogNode;
                $n->forceFill(['kind' => $d['kind'], 'code' => $d['code'], 'parent_id' => $parent?->id, 'labels' => $d['labels'], 'regulated' => $d['regulated'] ?? null, 'rules' => $d['rules'] ?? null, 'status' => $d['status']])->save();
            }
            foreach ($data['entries'] as $d) {
                if (CatalogEntry::where('code', $d['code'])->exists()) {
                    continue;
                }$e = new CatalogEntry;
                $e->forceFill(['code' => $d['code']])->save();
                $v = new CatalogVersion;
                $v->forceFill(['entry_id' => $e->id, 'version' => 1, 'policy' => $d['policy'], 'policy_hash' => hash('sha256', json_encode($d['policy'], JSON_THROW_ON_ERROR))])->save();
            }
            CatalogMutex::changed();
        });
    }
}
