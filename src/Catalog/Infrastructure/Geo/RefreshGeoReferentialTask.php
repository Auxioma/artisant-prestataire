<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Geo;

use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** BO07.1 : « import INSEE / La Poste, mise à jour annuelle » — le COG paraît en début d'année. */
#[AsCronTask('0 4 15 2 *', timezone: 'Europe/Paris')]
final readonly class RefreshGeoReferentialTask
{
    public function __construct(private GeoReferentialImporter $importer, private GeoReferentialSourceFactory $sources)
    {
    }

    public function __invoke(): void
    {
        $this->importer->import($this->sources->official(), deactivateMissing: true);
    }
}
