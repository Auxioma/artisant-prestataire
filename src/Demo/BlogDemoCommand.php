<?php

declare(strict_types=1);

namespace App\Demo;

use App\Content\Application\Blog\BlogCommand;
use App\Content\Application\Media\UploadMedia;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recette — articles de démonstration du blog (P15, maquettes Floriane V2) publiés par les vrais cas d'usage du BO
 * (catégories, médiathèque, création, enregistrement, publication), puis datés dans le passé. Relançable : les articles
 * déjà présents (même adresse) sont ignorés. Jamais sur la production réelle.
 */
#[AsCommand(name: 'app:demo:blog', description: 'Publie des articles de démonstration du blog (serveur de recette)')]
final class BlogDemoCommand extends Command
{
    private const string AUTHOR = 'Julie Martin, rédaction TrouveMoi';
    private const array CATEGORIES = ['Plomberie', 'Énergie', 'Rénovation', 'Jardin', 'Aides & primes'];
    /** image de couverture (assets/images) => [titre, texte alternatif] */
    private const array IMAGES = [
        'categories/plomberie.webp' => ['Plombier sous un lavabo', 'Plombier réparant le siphon d’un lavabo'],
        'categories/electricite.webp' => ['Électricien au tableau', 'Électricien contrôlant un tableau électrique'],
        'categories/renovation-et-construction.webp' => ['Maison rénovée', 'Façade de maison rénovée'],
        'categories/peinture-et-decoration.webp' => ['Peinture au rouleau', 'Rouleau de peinture sur un mur'],
        'categories/chauffage-et-climatisation.webp' => ['Chauffagiste', 'Chauffagiste intervenant sur une installation'],
        'categories/jardin-et-exterieur.webp' => ['Entretien du jardin', 'Tondeuse sur une pelouse'],
        'home/projet@1x.webp' => ['Projet de travaux', 'Artisan présentant un projet de travaux'],
    ];

    /** [titre, titre court, catégorie, image, métiers (slugs), à la une, jours depuis la publication, résumé, sections [intertitre, html]] */
    private const array ARTICLES = [
        ['MaPrimeRénov’ 2027 : ce qui change pour vos travaux', 'MaPrimeRénov’ 2027', 'Énergie', 'categories/electricite.webp', ['pompe-a-chaleur', 'renovation-complete'], true, 12,
            'Montants, plafonds de ressources et artisans RGE : tout ce qu’il faut savoir avant de lancer votre projet de rénovation énergétique.', [
                ['Qui peut en bénéficier ?', '<p>MaPrimeRénov’ s’adresse aux propriétaires occupants et bailleurs d’un logement construit depuis au moins quinze ans. Le montant dépend des revenus du foyer et du gain énergétique attendu.</p>'],
                ['Les travaux concernés', '<p>Isolation, chauffage performant (pompe à chaleur, chaudière biomasse), ventilation : les travaux doivent être réalisés par une entreprise <strong>RGE</strong> (Reconnu garant de l’environnement).</p><ul><li>Rénovation par geste : un équipement ou un poste de travaux.</li><li>Rénovation d’ampleur : un bouquet de travaux accompagné par un Accompagnateur Rénov’.</li></ul>'],
                ['Les étapes à suivre', '<p>Faites établir des devis, déposez votre dossier <strong>avant</strong> de signer et de commencer les travaux, puis transmettez la facture une fois le chantier terminé.</p>'],
                ['Où vérifier les barèmes ?', '<p>Les plafonds et montants sont fixés chaque année par l’État. Consultez le site officiel <a href="https://france-renov.gouv.fr">france-renov.gouv.fr</a> pour les barèmes en vigueur.</p>'],
            ]],
        ['Fuite d’eau : les bons réflexes avant l’arrivée du plombier', 'Fuite d’eau', 'Plomberie', 'categories/plomberie.webp', ['plombier', 'depannage-sanitaire', 'debouchage'], false, 3,
            'Couper l’eau, localiser la fuite, limiter les dégâts et prévenir votre assurance : la marche à suivre en attendant le professionnel.', [
                ['Couper l’arrivée d’eau', '<p>Le robinet d’arrêt général se trouve souvent près du compteur, dans la cuisine, la cave ou un placard technique. Fermez-le dès que vous constatez une fuite pour éviter un dégât des eaux important.</p>'],
                ['Localiser la fuite', '<p>Observez les traces d’humidité, le compteur d’eau (qui tourne alors que tout est fermé) et les raccords visibles sous les éviers, derrière les toilettes et près du chauffe-eau.</p>'],
                ['Limiter les dégâts', '<p>Épongez, protégez les meubles et coupez l’électricité de la pièce si l’eau approche des prises ou des appareils.</p>'],
                ['Prévenir votre assurance', '<p>Prenez des photos et déclarez le sinistre à votre assureur dans les délais prévus par votre contrat, en général cinq jours ouvrés.</p>'],
                ['Faire appel à un plombier', '<p>Décrivez précisément la situation et demandez un devis : un professionnel vérifié vous indique le coût de l’intervention avant de commencer.</p>'],
            ]],
        ['Combien coûte la rénovation d’une salle de bains ?', 'Rénover sa salle de bains', 'Rénovation', 'categories/renovation-et-construction.webp', ['salle-de-bains', 'carreleur', 'plombier'], false, 3,
            'Surface, équipements, carrelage et plomberie : les postes qui font varier le budget et nos conseils pour bien le préparer.', [
                ['Les postes de dépense', '<p>Le budget dépend de la surface, du choix des équipements (douche à l’italienne, baignoire, meuble vasque), des revêtements et de l’ampleur des travaux de plomberie.</p>'],
                ['Rafraîchir ou tout refaire ?', '<p>Changer les équipements en gardant les arrivées d’eau coûte beaucoup moins cher qu’une rénovation complète avec déplacement des évacuations.</p>'],
                ['Comparer les devis', '<p>Demandez plusieurs devis détaillés poste par poste : main-d’œuvre, fournitures, évacuation des gravats et délais.</p>'],
            ]],
        ['Repeindre un salon : les étapes et le budget', 'Repeindre un salon', 'Rénovation', 'categories/peinture-et-decoration.webp', ['peintre', 'papier-peint'], false, 3,
            'Préparation des murs, choix de la peinture, nombre de couches : ce qu’il faut prévoir pour un résultat durable.', [
                ['Bien préparer les supports', '<p>Rebouchage, ponçage et lessivage représentent souvent la moitié du temps passé : c’est ce qui garantit une finition sans défaut.</p>'],
                ['Choisir la bonne peinture', '<p>Mat pour masquer les imperfections, satiné pour les pièces de passage : demandez conseil à votre peintre selon l’usage de la pièce.</p>'],
                ['Estimer le budget', '<p>Le prix dépend de la surface à peindre, de l’état des murs et du nombre de couches. Un devis au mètre carré permet de comparer les offres.</p>'],
            ]],
        ['Pompe à chaleur ou chaudière : comment choisir ?', 'Pompe à chaleur ou chaudière', 'Énergie', 'categories/chauffage-et-climatisation.webp', ['pompe-a-chaleur', 'chauffagiste', 'entretien-chaudiere'], false, 3,
            'Coût d’installation, consommation, aides et entretien : les critères pour choisir votre prochain mode de chauffage.', [
                ['Le coût d’installation', '<p>Une pompe à chaleur coûte plus cher à l’achat qu’une chaudière, mais peut ouvrir droit à des aides si elle est posée par un professionnel RGE.</p>'],
                ['La consommation au quotidien', '<p>La pompe à chaleur produit plus de chaleur qu’elle ne consomme d’électricité ; son rendement dépend toutefois de l’isolation du logement et du climat.</p>'],
                ['L’entretien', '<p>Les deux équipements nécessitent un entretien régulier par un professionnel pour conserver leurs performances et leur garantie.</p>'],
            ]],
        ['Élagage : quand et comment tailler ses arbres', 'Élagage', 'Jardin', 'categories/jardin-et-exterieur.webp', ['elagage', 'paysagiste'], false, 3,
            'Période idéale, sécurité et règles de voisinage : nos conseils avant de faire élaguer vos arbres.', [
                ['La bonne période', '<p>On élague de préférence hors période de montée de sève et en dehors de la nidification des oiseaux, généralement en fin d’automne ou en hiver.</p>'],
                ['Sécurité avant tout', '<p>Les travaux en hauteur et à proximité des lignes électriques sont dangereux : confiez-les à un élagueur équipé et assuré.</p>'],
                ['Les règles de voisinage', '<p>Les branches qui dépassent chez le voisin doivent être coupées par le propriétaire de l’arbre : renseignez-vous auprès de votre mairie sur les distances à respecter.</p>'],
            ]],
        ['Tableau électrique : quand faut-il le remplacer ?', 'Tableau électrique', 'Rénovation', 'categories/electricite.webp', ['tableau-electrique', 'electricien', 'mise-aux-normes'], false, 3,
            'Disjonctions fréquentes, fusibles à broche, absence de différentiel : les signes qui doivent alerter.', [
                ['Les signes d’alerte', '<p>Des disjonctions répétées, des fusibles anciens ou l’absence de dispositif différentiel 30 mA sont autant de raisons de faire contrôler votre installation.</p>'],
                ['La mise aux normes', '<p>La norme NF C 15-100 encadre les installations électriques des logements : un électricien vous indique les travaux nécessaires pour votre sécurité.</p>'],
                ['Le diagnostic', '<p>Lors d’une vente ou d’une location, un diagnostic électrique est exigé pour les installations de plus de quinze ans.</p>'],
            ]],
        ['Aides à la rénovation : bien préparer son dossier', 'Aides à la rénovation', 'Aides & primes', 'home/projet@1x.webp', ['renovation-complete', 'pompe-a-chaleur'], false, 6,
            'Devis, artisans RGE, calendrier : les points à vérifier pour ne pas perdre le bénéfice d’une aide.', [
                ['Vérifier son éligibilité', '<p>Chaque aide a ses propres conditions de ressources, de logement et de travaux : simulez vos droits sur les sites officiels avant de vous engager.</p>'],
                ['Choisir un artisan qualifié', '<p>La plupart des aides à la rénovation énergétique exigent un professionnel RGE : vérifiez sa qualification et sa date de validité.</p>'],
                ['Respecter le calendrier', '<p>Le dossier doit en général être déposé <strong>avant</strong> la signature du devis : ne commencez pas les travaux trop tôt.</p>'],
            ]],
        ['Combien coûte un plombier ?', 'Prix d’un plombier', 'Plomberie', 'categories/plomberie.webp', ['plombier', 'depannage-sanitaire'], false, 9,
            'Déplacement, main-d’œuvre, majorations d’urgence : comprendre le prix d’une intervention de plomberie.', [
                ['Ce qui compose le prix', '<p>Le prix d’une intervention comprend le déplacement, la main-d’œuvre (souvent à l’heure) et les fournitures.</p>'],
                ['Urgences, soirs et week-ends', '<p>Les interventions en urgence ou en dehors des heures ouvrées sont souvent majorées : demandez le tarif avant le déplacement.</p>'],
                ['Toujours un devis', '<p>Au-delà d’un certain montant, le devis est obligatoire ; dans tous les cas, il vous protège en fixant le prix avant les travaux.</p>'],
            ]],
        ['Chauffe-eau : électrique ou thermodynamique ?', 'Choisir son chauffe-eau', 'Énergie', 'categories/chauffage-et-climatisation.webp', ['chauffe-eau', 'plombier'], false, 15,
            'Prix d’achat, consommation et encombrement : les deux technologies comparées.', [
                ['Le chauffe-eau électrique', '<p>Peu cher à l’achat et simple à installer, il consomme davantage d’électricité, surtout pour une famille nombreuse.</p>'],
                ['Le chauffe-eau thermodynamique', '<p>Plus cher à l’achat, il récupère les calories de l’air et réduit nettement la consommation ; il demande un local adapté.</p>'],
                ['Bien dimensionner', '<p>La capacité dépend du nombre de personnes et des usages : un professionnel vous conseille le volume adapté.</p>'],
            ]],
        ['Entretien annuel de chaudière : obligatoire ?', 'Entretien de chaudière', 'Énergie', 'categories/chauffage-et-climatisation.webp', ['entretien-chaudiere', 'chauffagiste'], false, 21,
            'Ce que prévoit la réglementation, ce que contrôle le professionnel et ce que vous devez conserver.', [
                ['Ce que dit la réglementation', '<p>L’entretien annuel des chaudières (gaz, fioul, bois) de 4 à 400 kW est obligatoire ; il est à la charge de l’occupant du logement.</p>'],
                ['Ce que contrôle le professionnel', '<p>Nettoyage, réglages, contrôle des émissions et de la sécurité : l’intervention améliore le rendement et prolonge la durée de vie de l’appareil.</p>'],
                ['L’attestation d’entretien', '<p>Conservez l’attestation remise par le professionnel : elle peut vous être demandée par votre assureur ou votre bailleur.</p>'],
            ]],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CommandBus $commands,
        private readonly IdGenerator $ids,
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
            $io->error('Aucun administrateur actif pour publier les articles.');

            return Command::FAILURE;
        }

        $categories = [];
        foreach (self::CATEGORIES as $position => $name) {
            $id = $this->connection->fetchOne('SELECT id FROM content.blog_category WHERE name = :n', ['n' => $name]);
            if (!\is_string($id)) {
                $id = $this->ids->next();
                $this->commands->dispatch(new BlogCommand('create_category', $id, $admin, ['name' => $name, 'position' => $position]));
            }
            $categories[$name] = $id;
        }
        $jobs = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, slug FROM catalog.job') as $row) {
            $jobs[DbalValue::string($row['slug'])] = DbalValue::string($row['id']);
        }

        $media = [];
        $published = 0;
        foreach (self::ARTICLES as [$title, $short, $category, $image, $jobSlugs, $featured, $daysAgo, $excerpt, $sections]) {
            if (false !== $this->connection->fetchOne('SELECT 1 FROM content.blog_article WHERE title = :t', ['t' => $title])) {
                continue;
            }
            $media[$image] ??= $this->media($image, $admin);
            $id = $this->ids->next();
            $html = implode("\n", array_map(static fn (array $s): string => '<h2>'.htmlspecialchars($s[0]).'</h2>'.$s[1], $sections));
            $this->commands->dispatch(new BlogCommand('create', $id, $admin, ['title' => $title]));
            $this->commands->dispatch(new BlogCommand('save', $id, $admin, [
                'title' => $title, 'short_title' => $short, 'excerpt' => $excerpt, 'category_id' => $categories[$category],
                'author_name' => self::AUTHOR, 'cover_media_id' => $media[$image], 'content_html' => $html, 'featured' => $featured,
                'job_ids' => array_values(array_filter(array_map(static fn (string $slug): ?string => $jobs[$slug] ?? null, $jobSlugs))),
            ]));
            $this->commands->dispatch(new BlogCommand('publish', $id, $admin));
            // Datation de démonstration (liste « Il y a N jours », « mis à jour le »).
            $this->connection->executeStatement(
                'UPDATE content.blog_article SET published_at = now() - make_interval(days => :d), created_at = now() - make_interval(days => :d), updated_at = now() - make_interval(days => :u) WHERE id = :id',
                ['d' => $daysAgo, 'u' => max(0, $daysAgo - 2), 'id' => $id],
            );
            ++$published;
        }
        $io->success(\sprintf('%d article(s) publié(s) dans %d catégories. Les images sont prêtes après le passage du worker (variantes WebP/AVIF).', $published, \count($categories)));

        return Command::SUCCESS;
    }

    /** Dépose l'image dans la médiathèque (même contrôle qu'un envoi du BO), une seule fois par image. */
    private function media(string $image, string $admin): string
    {
        [$title, $alt] = self::IMAGES[$image];
        $existing = $this->connection->fetchOne('SELECT id FROM content.media WHERE title = :t', ['t' => $title]);
        if (\is_string($existing)) {
            return $existing;
        }
        $copy = tempnam(sys_get_temp_dir(), 'blog');
        if (false === $copy || !copy($this->projectDir.'/assets/images/'.$image, $copy)) {
            throw new \RuntimeException('Image de démonstration illisible : '.$image);
        }
        $id = $this->ids->next();
        $this->commands->dispatch(new UploadMedia($id, $admin, $copy, basename($image), $title, $alt));

        return $id;
    }
}
