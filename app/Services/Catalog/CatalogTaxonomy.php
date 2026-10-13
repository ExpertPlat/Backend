<?php

namespace App\Services\Catalog;

use App\Models\CatalogNode;
use App\Models\CatalogVersion;

class CatalogTaxonomy
{
    public static function domains(?bool $regulated = null): array
    {
        $q = CatalogNode::where('kind', 'domain');
        if ($regulated !== null) {
            $q->where('regulated', $regulated);
        }

        return $q->orderBy('id')->pluck('code')->all();
    }

    public static function countries(): array
    {
        return CatalogNode::where('kind', 'country')->orderBy('code')->pluck('code')->all();
    }

    public static function deliveryModes(): array
    {
        return CatalogNode::where('kind', 'delivery_mode')->where('status', 'enabled')->pluck('code')->all();
    }

    public static function enabledDomains(): array
    {
        return CatalogVersion::where('status', 'enabled')->whereHas('entry', fn ($q) => $q->where('status', 'enabled')->whereColumn('current_version_id', 'catalog_versions.id'))->get()->filter(fn ($v) => ! $v->policy['regulated'])->map(fn ($v) => $v->policy['domain'])->unique()->values()->all();
    }

    public static function enabledCountries(): array
    {
        $versions = CatalogVersion::where('status', 'enabled')->whereHas('entry', fn ($q) => $q->where('status', 'enabled')->whereColumn('current_version_id', 'catalog_versions.id'))->get();
        if ($versions->contains(fn ($v) => $v->policy['jurisdictionMode'] === 'GLOBAL')) {
            return self::countries();
        }
        $codes = $versions->flatMap(fn ($v) => $v->policy['jurisdictionCodes']);
        $ids = CatalogNode::where('kind', 'jurisdiction')->whereIn('code', $codes)->pluck('parent_id');

        return CatalogNode::whereIn('id', $ids)->pluck('code')->all();
    }
}
