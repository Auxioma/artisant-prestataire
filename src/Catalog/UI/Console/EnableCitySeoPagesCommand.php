<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Geo\Port\GeoReferentialStore;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** BO07.1 : activation par lot des pages villes SEO (ex. communes > 5 000 habitants). */
#[AsCommand(name: 'app:geo:seo-pages', description: 'Active les pages villes SEO au-delà d’un seuil de population')]
final class EnableCitySeoPagesCommand extends Command
{
    public function __construct(private readonly GeoReferentialStore $store, private readonly SettingsProvider $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('min-population', null, InputOption::VALUE_REQUIRED, 'Seuil (défaut : paramètre seo.city_pages_min_population)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('min-population');
        $min = \is_string($option) && ctype_digit($option) ? (int) $option : $this->settings->int(SettingKey::SeoCityPagesMinPopulation);
        $count = $this->store->enableSeoPages($min);
        new SymfonyStyle($input, $output)->success(\sprintf('%d page(s) ville activée(s) (population ≥ %d). L’indexation reste soumise à RG-023.', $count, $min));

        return Command::SUCCESS;
    }
}
