<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Port;

use App\Catalog\Application\Geo\CommuneRecord;
use App\Catalog\Application\Geo\DepartmentRecord;
use App\Catalog\Application\Geo\RegionRecord;

/** Source du référentiel géographique (BO07 : import INSEE / La Poste, mise à jour annuelle). */
interface GeoReferentialSource
{
    /** @return iterable<RegionRecord> */
    public function regions(): iterable;

    /** @return iterable<DepartmentRecord> */
    public function departments(): iterable;

    /** @return iterable<CommuneRecord> */
    public function communes(): iterable;
}
