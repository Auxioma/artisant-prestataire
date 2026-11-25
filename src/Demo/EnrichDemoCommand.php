<?php

declare(strict_types=1);

namespace App\Demo;

use App\ArtisanProfile\Application\Agenda\RefreshAgendas;
use App\ArtisanProfile\Application\Agenda\SaveWeeklyRules;
use App\ArtisanProfile\Application\Edit\UpdateAvailability;
use App\ArtisanProfile\Application\Portfolio\AddPortfolioPhoto;
use App\ArtisanProfile\Application\Portfolio\SavePortfolioProject;
use App\Billing\Application\Credit\CreditLedger;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Moderation\ModerateQuoteRequests;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Review\Application\Review\SubmitReview;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Moderation\ModerateReview;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Serveur de test / recette uniquement — enrichit la base pour apprécier le design : chaque artisan public reçoit des
 * prestations complètes jouées « à tour de rôle » par les vrais parcours (le client dépose une demande directe,
 * l'artisan envoie un devis, le client l'accepte, la prestation a lieu, le client laisse un avis, la modération le
 * publie), étalées sur 12 mois ; plus des réalisations photo, des horaires d'agenda et des offres. Réutilise les
 * clients existants et ajoute des clients [DEMO]. Lancer avec un mailer neutre (MAILER_DSN=null://null) et vider la
 * file des événements avec ce même mailer : aucun e-mail réel n'est envoyé. Refusé en prod sans --recette.
 *
 * @phpstan-type DemoArtisan array{id: string, user_id: string, name: string, job_id: string, category: string, insee: string, postal_code: string, demo: bool}
 * @phpstan-type DemoClient array{id: string, first_name: string}
 */
#[AsCommand(name: 'app:demo:enrich', description: 'Enrichit la base de recette : avis, devis, réalisations, agendas, offres (serveur de test)')]
final class EnrichDemoCommand extends Command
{
    private const string MARK = 'enrichissement démo';
    private const int MIN_REVIEWS = 5;
    private const int MAX_REVIEWS = 9;
    private const int CREDITS = 20;
    private const int HISTORY_DAYS = 330;

    private const array CLIENT_NAMES = [['Lucie', 'Bernard'], ['Mathis', 'Robert'], ['Chloé', 'Richard'], ['Louis', 'Simon'], ['Emma', 'Michel'], ['Nathan', 'Garcia'],
        ['Jade', 'David'], ['Hugo', 'Bertrand'], ['Manon', 'Fontaine'], ['Théo', 'Chevalier'], ['Zoé', 'Girard'], ['Raphaël', 'Bonnet']];
    /** [note, titre, texte] — avis plausibles, notes surtout hautes. */
    private const array REVIEWS = [
        [5, 'Intervention rapide et soignée', 'Professionnel ponctuel, explications claires et chantier laissé parfaitement propre. Le devis a été respecté à l’euro près.'],
        [5, 'Je recommande vivement', 'Très bon contact dès le premier échange, travail de qualité et finitions impeccables. Je ferai de nouveau appel à lui.'],
        [5, 'Travail impeccable', 'Délais tenus, conseils utiles pour l’entretien et un résultat au-delà de nos attentes.'],
        [4, 'Bon travail, petit retard', 'Résultat très satisfaisant. Arrivée avec un peu de retard le premier jour, mais prévenu à l’avance.'],
        [5, 'Dépannage efficace', 'Venu le lendemain de ma demande, panne trouvée en quelques minutes et réparée dans la foulée.'],
        [4, 'Sérieux et à l’écoute', 'Bon artisan, de bon conseil. Le chantier a duré une journée de plus que prévu mais le rendu est propre.'],
        [5, 'Excellent rapport qualité-prix', 'Devis clair et détaillé, aucune mauvaise surprise sur la facture. Travail propre et soigné.'],
        [3, 'Correct', 'Le travail est fait, mais la communication aurait pu être meilleure sur les horaires de passage.'],
        [5, 'Parfait du début à la fin', 'Prise de rendez-vous simple, intervention dans les temps et explications sur ce qui a été fait.'],
        [4, 'Satisfait', 'Prestation conforme au devis, artisan aimable et respectueux des lieux.'],
    ];
    /** [projet, prix HT de la main-d'œuvre] par catégorie racine (slug) ; défaut : première entrée. */
    private const array PROJECTS = [
        'plomberie' => [['Remplacement d’un mitigeur', 120], ['Recherche et réparation de fuite', 150], ['Rénovation d’une salle de bains', 2400]],
        'electricite' => [['Ajout de prises et d’un circuit', 280], ['Mise aux normes du tableau électrique', 950], ['Pose de luminaires', 180]],
        'chauffage-et-climatisation' => [['Entretien annuel de chaudière', 130], ['Remplacement de radiateurs', 760], ['Pose d’une pompe à chaleur', 6900]],
        'peinture-et-decoration' => [['Peinture d’un séjour', 1150], ['Pose de papier peint', 420], ['Rafraîchissement d’une chambre', 640]],
        'renovation-et-construction' => [['Construction d’un muret', 1350], ['Ouverture d’un mur porteur', 3200], ['Pose de carrelage', 1600]],
        'jardin-et-exterieur' => [['Taille de haies', 260], ['Création d’un massif', 780], ['Entretien de jardin', 190]],
    ];
    /** [type, valeur, prix de référence en centimes, titre] — RG-026 : prix de référence pour toute réduction. */
    private const array OFFERS = [
        ['percent', 10, 12000, 'Entretien annuel : −10 %'], ['free', null, 9000, 'Diagnostic offert'], ['amount', 150, 690000, 'Remise sur l’installation'],
        ['percent', 15, 80000, 'Rafraîchissement d’une pièce'], ['amount', 50, 40000, 'Remise première intervention'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CommandBus $commands,
        private readonly CreditLedger $credits,
        private readonly EntityManagerInterface $entities,
        private readonly FileUploader $uploader,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('recette', null, InputOption::VALUE_NONE, 'Autorise l’exécution sur un environnement « prod » de recette (jamais sur la production réelle).');
        $this->addOption('artisans', null, InputOption::VALUE_REQUIRED, 'Limite le nombre d’artisans traités (essai).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environment && !$input->getOption('recette')) {
            $io->error('Environnement prod : ajoutez --recette pour un serveur de recette. Jamais sur la production réelle.');

            return Command::FAILURE;
        }
        $admin = $this->connection->fetchOne("SELECT id FROM admin.admin_user WHERE status = 'active' ORDER BY role = 'super_admin' DESC, created_at LIMIT 1");
        if (!\is_string($admin)) {
            $io->error('Aucun administrateur actif pour publier les avis.');

            return Command::FAILURE;
        }
        if (false === $this->connection->fetchOne('SELECT 1 FROM identity.user_account WHERE email_canonical LIKE :p LIMIT 1', ['p' => SeedDemoCommand::EMAIL_PATTERN])) {
            $this->getApplication()?->find('app:demo:seed')->run(new ArrayInput([]), $output);
        }

        // SIRET [DEMO] des versions précédentes sans clé de Luhn valide : refusés à l'envoi d'un devis.
        foreach ($this->connection->fetchAllAssociative("SELECT p.id, u.email_canonical FROM artisan.artisan_profile p JOIN identity.user_account u ON u.id = p.user_id WHERE u.email_canonical LIKE 'demo.artisan.%@exemple.test'") as $row) {
            $siret = SeedDemoCommand::siret((int) preg_replace('/\\D/', '', DbalValue::string($row['email_canonical'])));
            $this->connection->executeStatement('UPDATE artisan.artisan_profile SET siret = :s, siren = :n WHERE id = :id', ['s' => $siret, 'n' => substr($siret, 0, 9), 'id' => $row['id']]);
            // Décennale fictive : exigée pour les métiers du bâtiment (maçon, carreleur…) à l'envoi d'un devis.
            $this->connection->executeStatement(
                "UPDATE artisan.artisan_profile SET insurances = insurances || jsonb_build_array(jsonb_build_object('kind', 'decennial', 'insurer', 'Assureur fictif', 'policy', 'DEMO-DEC', 'valid_until', CAST(current_date + 365 AS text)))
                 WHERE id = :id AND NOT insurances @> '[{\"kind\": \"decennial\"}]'",
                ['id' => $row['id']],
            );
        }
        $clients = $this->clients();
        $artisans = $this->artisans();
        if (null !== $input->getOption('artisans')) {
            $limit = $input->getOption('artisans');
            $artisans = \array_slice($artisans, 0, max(1, is_numeric($limit) ? (int) $limit : 1));
        }
        $io->text(\sprintf('%d clients et %d artisans publics.', \count($clients), \count($artisans)));

        $done = $failed = 0;
        $turn = 0;
        foreach ($artisans as $a => $artisan) {
            if ($artisan['demo']) {
                $this->completeDemoProfile($artisan, $io);
            }
            // Relançable : un artisan déjà servi (avis publiés) est passé.
            if (DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM review.review WHERE artisan_id = :a AND status = 'published'", ['a' => $artisan['id']])) >= self::MIN_REVIEWS) {
                continue;
            }
            $this->credits->grantFree(Uuid::v7()->toRfc4122(), $artisan['id'], $artisan['user_id'], self::CREDITS, null, new \DateTimeImmutable());
            $count = random_int(self::MIN_REVIEWS, self::MAX_REVIEWS);
            for ($i = 0; $i < $count; ++$i) {
                $client = $clients[$turn++ % \count($clients)];
                try {
                    $this->cycle($artisan, $client, $admin, $i, $turn);
                    ++$done;
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->text(\sprintf('  · %s : %s', $artisan['name'], $e->getMessage()));
                    if ($output->isVerbose()) {
                        $io->text(\sprintf('    %s %s:%d', $e::class, $e->getFile(), $e->getLine()));
                    }
                }
                $this->entities->clear();
            }
            $this->portfolio($artisan, $io);
            $this->agenda($artisan, $io);
            if (0 === $a % 2 && false === $this->connection->fetchOne('SELECT 1 FROM artisan.offer WHERE artisan_id = :a', ['a' => $artisan['id']])) {
                $this->offer($artisan, $a);
            }
        }
        $this->commands->dispatch(new RefreshAgendas());
        $this->getApplication()?->find('app:search:rebuild-artisans')->run(new ArrayInput([]), new NullOutput());

        $io->success(\sprintf('%d prestations avec avis publiées (%d échecs), réalisations, agendas et offres ajoutés.', $done, $failed));
        $io->text('Videz ensuite la file des événements avec MAILER_DSN=null://null (aucun e-mail réel).');

        return Command::SUCCESS;
    }

    /**
     * Une prestation complète, jouée par chaque rôle, puis antidatée (jusqu'à 11 mois) pour étaler l'historique.
     *
     * @param DemoArtisan $artisan
     * @param DemoClient  $client
     */
    private function cycle(array $artisan, array $client, string $admin, int $n, int $seq): void
    {
        $projects = self::PROJECTS[$artisan['category']] ?? self::PROJECTS['plomberie'];
        [$title, $price] = $projects[$n % \count($projects)];
        [$rating, $reviewTitle, $text] = self::REVIEWS[random_int(0, \count(self::REVIEWS) - 1)];
        $requestId = Uuid::v7()->toRfc4122();
        $quoteId = Uuid::v7()->toRfc4122();
        $reviewId = Uuid::v7()->toRfc4122();

        $this->commands->dispatch(new SubmitQuoteRequest(
            $requestId,
            $client['id'],
            $artisan['job_id'],
            $artisan['insee'],
            $artisan['postal_code'],
            \sprintf('%d rue des Lilas', $seq),
            'repair', // une adresse par dossier (anti-doublon)
            $title.' : intervention souhaitée dans les prochaines semaines. Accès facile, stationnement possible devant la maison ('.self::MARK.').',
            [],
            null,
            'month',
            null,
            targetArtisanId: $artisan['id'],
            broadcastToOthers: false,
            title: $title,
        ));
        // Rafale de demandes : l'anti-fraude peut les retenir ; l'administrateur les valide comme en BO04.
        if ('pending_moderation' === $this->connection->fetchOne('SELECT status FROM request.quote_request WHERE id = :id', ['id' => $requestId])) {
            $this->commands->dispatch(new ModerateQuoteRequests($admin, [$requestId], ModerateQuoteRequests::APPROVE));
            $this->entities->clear();
        }
        $this->commands->dispatch(new DispatchQuoteRequest($requestId));
        $this->commands->dispatch(new SaveQuoteDraft($artisan['user_id'], $requestId, $quoteId, ['subject' => $title, 'lines' => [
            ['type' => 'labour', 'label' => $title, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => (string) $price, 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '30', 'vat' => '2000'],
        ]]));
        $this->commands->dispatch(new SendQuote($artisan['user_id'], $requestId));
        $this->commands->dispatch(new AcceptQuote($client['id'], $quoteId));
        $this->entities->clear();
        $this->connection->executeStatement(
            "INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, completed_at, created_at)
             VALUES (:id, :q, now() - interval '2 days', now() - interval '2 days' + interval '2 hours', 'completed', 'client', now() - interval '4 days', now() - interval '1 day', now() - interval '5 days')",
            ['id' => Uuid::v7()->toRfc4122(), 'q' => $quoteId],
        );
        $this->commands->dispatch(new SubmitReview($client['id'], $reviewId, $requestId, $rating, [], $reviewTitle, $text, null, $rating >= 4));
        if ('published' !== $this->connection->fetchOne('SELECT status FROM review.review WHERE id = :id', ['id' => $reviewId])) {
            $this->commands->dispatch(new ModerateReview($admin, $reviewId, 'publish'));
        }

        // Étalement sur l'année : tout le dossier recule du même nombre de jours.
        $shift = \sprintf('%d days', random_int(3, self::HISTORY_DAYS));
        $this->connection->executeStatement('UPDATE request.quote_request SET submitted_at = submitted_at - CAST(:s AS interval), published_at = published_at - CAST(:s AS interval), status_changed_at = status_changed_at - CAST(:s AS interval), updated_at = updated_at - CAST(:s AS interval) WHERE id = :id', ['s' => $shift, 'id' => $requestId]);
        $this->connection->executeStatement('UPDATE quote.quote SET sent_at = sent_at - CAST(:s AS interval), viewed_at = viewed_at - CAST(:s AS interval), decided_at = decided_at - CAST(:s AS interval), created_at = created_at - CAST(:s AS interval), updated_at = updated_at - CAST(:s AS interval) WHERE id = :id', ['s' => $shift, 'id' => $quoteId]);
        $this->connection->executeStatement('UPDATE quote.appointment SET starts_at = starts_at - CAST(:s AS interval), ends_at = ends_at - CAST(:s AS interval), confirmed_at = confirmed_at - CAST(:s AS interval), completed_at = completed_at - CAST(:s AS interval), created_at = created_at - CAST(:s AS interval) WHERE quote_id = :id', ['s' => $shift, 'id' => $quoteId]);
        $this->connection->executeStatement('UPDATE review.review SET created_at = created_at - CAST(:s AS interval), updated_at = updated_at - CAST(:s AS interval), moderated_at = moderated_at - CAST(:s AS interval), service_date = service_date - CAST(:s AS interval) WHERE id = :id', ['s' => $shift, 'id' => $reviewId]);
    }

    /** @param DemoArtisan $artisan */
    private function portfolio(array $artisan, SymfonyStyle $io): void
    {
        $photos = DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM artisan.portfolio_photo ph JOIN artisan.portfolio_project pr ON pr.id = ph.project_id WHERE pr.artisan_id = :a', ['a' => $artisan['id']]));
        if ($photos > 0) {
            return;
        }
        $images = array_values(array_filter([
            $this->projectDir.'/assets/images/categories/'.$artisan['category'].'.webp',
            $this->projectDir.'/assets/images/home/projet@1x.webp',
        ], 'is_file'));
        if ([] === $images) {
            return;
        }
        try {
            $projectId = Uuid::v7()->toRfc4122();
            $title = (self::PROJECTS[$artisan['category']] ?? self::PROJECTS['plomberie'])[0][0];
            $this->commands->dispatch(new SavePortfolioProject($artisan['user_id'], $projectId, true, $title, 'Chantier réalisé récemment, photos prises en fin de travaux.', null, new \DateTimeImmutable('-2 months')->format('Y-m-d'), $artisan['job_id']));
            foreach ($images as $image) {
                $copy = tempnam(sys_get_temp_dir(), 'demo');
                if (false !== $copy && copy($image, $copy)) {
                    $this->commands->dispatch(new AddPortfolioPhoto($artisan['user_id'], $projectId, $copy, basename($image), 'photo'));
                }
            }
        } catch (\Throwable $e) {
            $io->text(\sprintf('  · réalisations %s : %s', $artisan['name'], $e->getMessage()));
        }
        $this->entities->clear();
    }

    /**
     * Horaires types (lun.–ven., 8 h–12 h et 14 h–18 h) pour les artisans sans agenda.
     *
     * @param DemoArtisan $artisan
     */
    private function agenda(array $artisan, SymfonyStyle $io): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM artisan.availability_rule WHERE artisan_id = :a LIMIT 1', ['a' => $artisan['id']])) {
            return;
        }
        $rules = [];
        foreach (range(1, 5) as $day) {
            $rules[] = ['weekday' => $day, 'start' => '08:00', 'end' => '12:00'];
            $rules[] = ['weekday' => $day, 'start' => '14:00', 'end' => '18:00'];
        }
        try {
            $this->commands->dispatch(new SaveWeeklyRules($artisan['user_id'], $rules));
        } catch (\Throwable $e) {
            $io->text(\sprintf('  · agenda %s : %s', $artisan['name'], $e->getMessage()));
        }
        $this->entities->clear();
    }

    /**
     * Profils [DEMO] seulement (jamais les comptes des testeurs) : logo à initiales généré, horaires d'ouverture et une
     * troisième photo, pour dépasser `pro.min_completion_featured` (RG-011) et apparaître dans les mises en avant.
     *
     * @param DemoArtisan $artisan
     */
    private function completeDemoProfile(array $artisan, SymfonyStyle $io): void
    {
        $row = $this->connection->fetchAssociative('SELECT logo_file_id, opening_hours::text AS hours FROM artisan.artisan_profile WHERE id = :id', ['id' => $artisan['id']]);
        if (!\is_array($row)) {
            return;
        }
        // Logo : même contrôle et même analyse qu'un envoi réel (FileUploader) ; rattaché directement, le domaine
        // n'autorisant le changement de logo que pendant l'inscription (le profil [DEMO] est déjà vérifié).
        if (null === $row['logo_file_id'] && null !== ($logo = $this->initialsLogo($artisan['name']))) {
            try {
                $file = $this->uploader->upload($logo, 'logo.png', FilePurpose::ArtisanLogo);
                $this->connection->executeStatement('UPDATE artisan.artisan_profile SET logo_file_id = :f WHERE id = :id', ['f' => $file->id, 'id' => $artisan['id']]);
            } catch (\Throwable $e) {
                $io->text(\sprintf('  · logo %s : %s', $artisan['name'], $e->getMessage()));
            }
        }
        $steps = [];
        $project = $this->connection->fetchOne('SELECT pr.id FROM artisan.portfolio_project pr WHERE pr.artisan_id = :a AND (SELECT count(*) FROM artisan.portfolio_photo ph WHERE ph.project_id = pr.id) = 2 LIMIT 1', ['a' => $artisan['id']]);
        $image = $this->projectDir.'/assets/images/categories/'.$artisan['category'].'.webp';
        if (\is_string($project) && is_file($image) && null !== ($mirror = $this->mirrored($image))) {
            $steps['photo'] = new AddPortfolioPhoto($artisan['user_id'], $project, $mirror, 'chantier.png', 'after');
        }
        if (!str_contains(\is_string($row['hours']) ? $row['hours'] : '', '08:00')) {
            // En dernier : l'événement de mise à jour du profil recalcule la complétude (logo compris).
            $days = array_fill_keys(['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'], ['08:00-12:00', '14:00-18:00']) + ['samedi' => ['09:00-12:00']];
            $steps['horaires'] = new UpdateAvailability($artisan['user_id'], $days, false, null, false);
        }
        foreach ($steps as $label => $command) {
            try {
                $this->commands->dispatch($command);
            } catch (\Throwable $e) {
                $io->text(\sprintf('  · %s %s : %s', $label, $artisan['name'], $e->getMessage()));
            }
            $this->entities->clear();
        }
    }

    /** Logo PNG 400 × 400 : initiales blanches sur fond bleu (aucune marque réelle). */
    private function initialsLogo(string $name): ?string
    {
        $words = preg_split('/[\\s\\-’\']+/u', trim(str_replace(SeedDemoCommand::MARK, '', $name))) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) > 2));
        $initials = mb_strtoupper(mb_substr($words[0] ?? 'T', 0, 1).mb_substr($words[1] ?? '', 0, 1));
        $font = $this->projectDir.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
        $image = imagecreatetruecolor(400, 400);
        $path = tempnam(sys_get_temp_dir(), 'logo');
        if (false === $image || false === $path || !is_file($font)) {
            return null;
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 87, 184));
        $box = imagettfbbox(150, 0, $font, $initials);
        $at = static fn (int $i): int => false !== $box && \is_int($box[$i] ?? null) ? $box[$i] : 0;
        [$left, $bottom, $right, $top] = [$at(0), $at(1), $at(2), $at(7)];
        imagettftext($image, 150, 0, (int) ((400 - ($right - $left)) / 2), (int) ((400 + ($bottom - $top)) / 2), (int) imagecolorallocate($image, 255, 255, 255), $font, $initials);
        imagepng($image, $path);

        return $path;
    }

    /** Variante miroir d'une photo (troisième vue du chantier). */
    private function mirrored(string $source): ?string
    {
        $image = imagecreatefromwebp($source);
        $path = tempnam(sys_get_temp_dir(), 'photo');
        if (false === $image || false === $path) {
            return null;
        }
        imageflip($image, \IMG_FLIP_HORIZONTAL);
        imagepng($image, $path);

        return $path;
    }

    /** @param DemoArtisan $artisan */
    private function offer(array $artisan, int $n): void
    {
        [$type, $value, $reference, $title] = self::OFFERS[intdiv($n, 2) % \count(self::OFFERS)];
        $this->connection->executeStatement(
            "INSERT INTO artisan.offer (id, artisan_id, title, description, type, value, reference_price_cents, job_ids, conditions, starts_on, ends_on, status, created_at, updated_at)
             VALUES (:id, :a, :t, 'Offre valable pour les particuliers, sur devis.', :type, :v, :ref, CAST(:jobs AS uuid[]), '1 par foyer', current_date - 5, current_date + CAST(:days AS int), 'active', now(), now())",
            ['id' => Uuid::v7()->toRfc4122(), 'a' => $artisan['id'], 't' => $title, 'type' => $type, 'v' => $value, 'ref' => $reference, 'jobs' => '{'.$artisan['job_id'].'}', 'days' => 20 + 15 * ($n % 4)],
        );
    }

    /** @return list<DemoClient> clients actifs existants + clients [DEMO] ajoutés */
    private function clients(): array
    {
        $hash = $this->connection->fetchOne("SELECT password_hash FROM identity.user_account WHERE email_canonical = 'demo.client.01@exemple.test'");
        $now = new \DateTimeImmutable()->format(\DATE_ATOM);
        foreach (self::CLIENT_NAMES as $i => [$first, $last]) {
            $email = \sprintf('demo.client.%02d@exemple.test', $i + 4);
            if (\is_string($hash) && false === $this->connection->fetchOne('SELECT 1 FROM identity.user_account WHERE email_canonical = :e', ['e' => $email])) {
                $this->connection->insert('identity.user_account', [
                    'id' => Uuid::v7()->toRfc4122(), 'email' => $email, 'email_canonical' => $email, 'password_hash' => $hash, 'kind' => 'client', 'status' => 'active',
                    'first_name' => $first, 'last_name' => SeedDemoCommand::MARK.' '.$last, 'email_verified_at' => $now, 'password_changed_at' => $now,
                    'created_at' => $now, 'updated_at' => $now, 'passwordless' => 'false',
                ]);
            }
        }

        return array_map(static fn (array $r): array => ['id' => DbalValue::string($r['id']), 'first_name' => DbalValue::string($r['first_name'])], $this->connection->fetchAllAssociative(
            "SELECT id, coalesce(first_name, 'Client') AS first_name FROM identity.user_account WHERE kind = 'client' AND status = 'active' AND anonymized_at IS NULL ORDER BY created_at",
        ));
    }

    /** @return list<DemoArtisan> */
    private function artisans(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT p.id, p.user_id, v.name, v.main_job_id AS job_id, p.hq_postal_code AS postal_code, u.email_canonical LIKE 'demo.artisan.%@exemple.test' AS demo,
                   -- Siège sans commune INSEE (ex. Paris) : commune la plus peuplée du code postal.
                   coalesce(p.hq_city_insee, (SELECT c.insee FROM catalog.commune c WHERE p.hq_postal_code = ANY(c.postal_codes) ORDER BY c.population DESC NULLS LAST LIMIT 1)) AS insee,
                   coalesce((SELECT coalesce(root.slug, c.slug) FROM catalog.job j JOIN catalog.category c ON c.id = j.category_id
                             LEFT JOIN catalog.category root ON root.id = c.parent_id WHERE j.id = v.main_job_id), '') AS category
            FROM search.artisan_search_view v
            JOIN artisan.artisan_profile p ON p.id = v.artisan_id
            JOIN identity.user_account u ON u.id = p.user_id AND u.status = 'active'
            WHERE v.main_job_id IS NOT NULL AND p.hq_postal_code IS NOT NULL
            ORDER BY v.name
            SQL);

        $rows = array_values(array_filter($rows, static fn (array $r): bool => \is_string($r['insee'])));

        return array_map(static fn (array $r): array => [
            'id' => DbalValue::string($r['id']), 'user_id' => DbalValue::string($r['user_id']), 'name' => DbalValue::string($r['name']), 'job_id' => DbalValue::string($r['job_id']),
            'category' => DbalValue::string($r['category']), 'insee' => DbalValue::string($r['insee']), 'postal_code' => DbalValue::string($r['postal_code']),
            'demo' => true === $r['demo'],
        ], $rows);
    }
}
