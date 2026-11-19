<?php

declare(strict_types=1);

namespace App\Demo;

use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * T-901 — jeu de démonstration de la zone pilote (Q27, cadrage §8) : artisans et clients **fictifs**, tous marqués
 * « [DEMO] » (raison sociale, nom, textes), adresses `@exemple.test`, SIRET en 999…, mot de passe aléatoire affiché une
 * fois. Aucun avis, aucune note, aucun chiffre présenté comme réel. Refusé en production sans `--recette`, et si le
 * jeu est déjà chargé (base de recette à réinitialiser pour recommencer).
 */
#[AsCommand(name: 'app:demo:seed', description: 'Charge les données de démonstration [DEMO] (Normandie, fictives)')]
final class SeedDemoCommand extends Command
{
    public const string MARK = '[DEMO]';
    public const string EMAIL_PATTERN = 'demo.%@exemple.test';
    /** Octets aléatoires du mot de passe commun (16 caractères hexadécimaux). */
    private const int PASSWORD_BYTES = 8;
    private const int FREE_CREDITS = 10;
    private const int COMPLETION = 80;
    private const int RADIUS_KM = 30;
    private const int YEARS = 12;

    /** Villes pilotes : INSEE => [nom, code postal]. */
    private const array CITIES = [
        '76351' => ['Le Havre', '76600'], '76540' => ['Rouen', '76000'], '14118' => ['Caen', '14000'],
        '27229' => ['Évreux', '27000'], '50129' => ['Cherbourg-en-Cotentin', '50100'], '61001' => ['Alençon', '61000'],
    ];
    /** Artisans fictifs : [raison sociale, prénom, nom, métier principal, autre métier ou null, INSEE]. */
    private const array ARTISANS = [
        ['Plomberie Lemaire', 'Julien', 'Lemaire', 'plombier', 'chauffagiste', '76351'],
        ['Électricité Côte d’Albâtre', 'Sophie', 'Durand', 'electricien', null, '76351'],
        ['Peinture Seine & Couleurs', 'Karim', 'Benali', 'peintre', null, '76540'],
        ['Chauffage Rouennais', 'Claire', 'Martin', 'chauffagiste', 'plombier', '76540'],
        ['Carrelage du Calvados', 'Thomas', 'Leroy', 'carreleur', null, '14118'],
        ['Maçonnerie Normande', 'Hugo', 'Petit', 'macon', null, '14118'],
        ['Jardins d’Évreux', 'Léa', 'Moreau', 'paysagiste', null, '27229'],
        ['Électro Eure Services', 'Nicolas', 'Garnier', 'electricien', null, '27229'],
        ['Plombier du Cotentin', 'Camille', 'Roux', 'plombier', null, '50129'],
        ['Peintures de la Hague', 'Antoine', 'Faure', 'peintre', 'carreleur', '50129'],
        ['Maçons de l’Orne', 'Sarah', 'Blanc', 'macon', null, '61001'],
        ['Chauffage Alençonnais', 'Pierre', 'Lambert', 'chauffagiste', null, '61001'],
    ];
    /** Clients fictifs : [prénom, nom]. */
    private const array CLIENTS = [['Marie', 'Dubois'], ['Paul', 'Lefèvre'], ['Inès', 'Mercier']];
    /** Demandes fictives : [client, métier, INSEE, titre, description]. */
    private const array REQUESTS = [
        [0, 'plombier', '76351', 'Fuite sous l’évier', 'Fuite d’eau sous l’évier de la cuisine, à réparer rapidement.'],
        [1, 'peintre', '76540', 'Peinture d’un séjour', 'Peinture des murs et du plafond d’un séjour de 25 m².'],
        [2, 'electricien', '27229', 'Ajout de prises', 'Ajout de trois prises électriques dans une chambre.'],
        [0, 'macon', '14118', 'Muret de jardin', 'Construction d’un muret de 6 m en parpaings enduits.'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CommandBus $commands,
        private readonly PasswordHasherFactoryInterface $hashers,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('recette', null, InputOption::VALUE_NONE, 'Autorise le chargement sur un environnement « prod » de recette (jamais sur la production réelle).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environment && !$input->getOption('recette')) {
            $io->error('Environnement prod : ajoutez --recette pour un serveur de recette. Jamais sur la production réelle.');

            return Command::FAILURE;
        }
        if (false !== $this->connection->fetchOne('SELECT 1 FROM identity.user_account WHERE email_canonical LIKE :p LIMIT 1', ['p' => self::EMAIL_PATTERN])) {
            $io->warning('Le jeu de démonstration est déjà chargé.');

            return Command::SUCCESS;
        }
        $jobs = [];
        foreach ($this->connection->fetchAllAssociative('SELECT slug, id FROM catalog.job WHERE active') as $row) {
            $jobs[DbalValue::string($row['slug'])] = DbalValue::string($row['id']);
        }
        $password = $this->password();
        $hash = $this->hashers->getPasswordHasher(PasswordAuthenticatedUserInterface::class)->hash($password);
        $now = new \DateTimeImmutable();

        $this->connection->transactional(function () use ($jobs, $hash, $now): void {
            foreach (self::ARTISANS as $n => [$company, $first, $last, $main, $other, $insee]) {
                if (!isset($jobs[$main]) || (null !== $other && !isset($jobs[$other]))) {
                    throw new \RuntimeException(\sprintf('Métier absent du référentiel : %s. Importez le référentiel avant.', $main));
                }
                $this->artisan($n + 1, $company, $first, $last, $jobs[$main], null === $other ? null : $jobs[$other], $insee, $hash, $now);
            }
            foreach (self::CLIENTS as $n => [$first, $last]) {
                $this->user($this->clientId($n), \sprintf('demo.client.%02d@exemple.test', $n + 1), 'client', $first, self::MARK.' '.$last, $hash, $now);
            }
        });
        foreach (['app:artisan:rebuild-coverage', 'app:search:rebuild-artisans'] as $name) {
            $this->getApplication()?->find($name)->run(new ArrayInput([]), $output);
        }
        foreach (self::REQUESTS as [$client, $job, $insee, $title, $description]) {
            $id = Uuid::v7()->toRfc4122();
            $this->commands->dispatch(new SubmitQuoteRequest($id, $this->clientId($client), $jobs[$job], $insee, self::CITIES[$insee][1], '1 rue de la Démonstration', 'repair', self::MARK.' '.$description, [], null, 'month', null, title: self::MARK.' '.$title));
            $this->commands->dispatch(new DispatchQuoteRequest($id));
        }

        $io->success(\sprintf('%d artisans, %d clients et %d demandes de démonstration chargés.', \count(self::ARTISANS), \count(self::CLIENTS), \count(self::REQUESTS)));
        $io->text([
            'Comptes : demo.artisan.01@exemple.test … demo.artisan.12@exemple.test, demo.client.01@exemple.test … 03.',
            'Mot de passe commun (affiché une seule fois, à conserver hors du dépôt) : '.$password,
        ]);

        return Command::SUCCESS;
    }

    private function artisan(int $n, string $company, string $first, string $last, string $main, ?string $other, string $insee, string $hash, \DateTimeImmutable $now): void
    {
        $userId = Uuid::v7()->toRfc4122();
        $artisanId = Uuid::v7()->toRfc4122();
        $this->user($userId, \sprintf('demo.artisan.%02d@exemple.test', $n), 'artisan', $first, self::MARK.' '.$last, $hash, $now);
        $siret = \sprintf('999999%08d', $n);
        $city = self::CITIES[$insee][0];
        $jobs = [['job' => $main, 'main' => true]];
        if (null !== $other) {
            $jobs[] = ['job' => $other, 'main' => false];
        }
        $at = $now->format(\DATE_ATOM);
        $this->connection->insert('artisan.artisan_profile', [
            'id' => $artisanId, 'user_id' => $userId, 'status' => 'verified', 'completed_step' => 7,
            'siret' => $siret, 'siren' => substr($siret, 0, 9), 'company_name' => self::MARK.' '.$company, 'trade_name' => self::MARK.' '.$company,
            'legal_form' => 'EI', 'vat_status' => 'franchise', 'hq_postal_code' => self::CITIES[$insee][1], 'hq_city' => $city, 'hq_city_insee' => $insee,
            'jobs' => json_encode($jobs, \JSON_THROW_ON_ERROR), 'area_mode' => 'radius', 'area_center_insee' => $insee, 'area_radius_km' => self::RADIUS_KM,
            'insurances' => json_encode([['kind' => 'rc_pro', 'insurer' => 'Assureur fictif', 'policy' => 'DEMO-'.$n, 'valid_until' => $now->modify('+1 year')->format('Y-m-d')]], \JSON_THROW_ON_ERROR),
            'short_description' => self::MARK.' Artisan fictif à '.$city.' (compte de démonstration).',
            'presentation' => json_encode(['years' => self::YEARS, 'slogan' => 'Compte de démonstration', 'description' => 'Entreprise **fictive** créée pour la démonstration de TrouveMoi. Aucune prestation réelle.', 'languages' => ['fr'], 'clients' => ['particuliers'], 'strengths' => ['Devis détaillé (démonstration)']], \JSON_THROW_ON_ERROR),
            'services' => json_encode([['title' => 'Prestation de démonstration', 'price' => 'sur devis']], \JSON_THROW_ON_ERROR),
            'completion_rate' => self::COMPLETION, 'submitted_at' => $at, 'verified_at' => $at, 'siret_verified_at' => $at,
            'created_at' => $at, 'updated_at' => $at, 'status_changed_at' => $at,
        ]);
        $this->connection->insert('billing.credit_account', ['artisan_id' => $artisanId, 'user_id' => $userId, 'created_at' => $at]);
        $this->connection->insert('billing.credit_balance', ['artisan_id' => $artisanId, 'free' => self::FREE_CREDITS, 'free_granted' => self::FREE_CREDITS, 'updated_at' => $at]);
    }

    private function user(string $id, string $email, string $kind, string $first, string $last, string $hash, \DateTimeImmutable $now): void
    {
        $at = $now->format(\DATE_ATOM);
        $this->connection->insert('identity.user_account', [
            'id' => $id, 'email' => $email, 'email_canonical' => $email, 'password_hash' => $hash, 'kind' => $kind, 'status' => 'active',
            'first_name' => $first, 'last_name' => $last, 'email_verified_at' => $at, 'password_changed_at' => $at,
            'created_at' => $at, 'updated_at' => $at, 'passwordless' => 'false',
        ]);
    }

    /** Identifiants stables des clients (les demandes y renvoient). */
    private function clientId(int $n): string
    {
        return \sprintf('0190f5e4-0000-7000-8000-0000de%06d', $n + 1);
    }

    private function password(): string
    {
        return bin2hex(random_bytes(self::PASSWORD_BYTES));
    }
}
