<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

use App\Shared\Application\Settings\SettingType as T;

/**
 * Catalogue des paramètres administrables (RG-014, CDC annexe G, BO13).
 * Les clés marquées `proposed: true` sont issues de docs/decisions/points-en-suspens.md : leur défaut applique
 * la recommandation de l'annexe I et reste modifiable sans redéploiement.
 *
 * Aucune valeur métier n'est lue ailleurs qu'ici (défauts) ou dans la table admin.setting (valeurs courantes).
 */
enum SettingKey: string
{
    // Crédits et forfaits (§4, annexe G)
    case CreditsFreeOnSignup = 'credits.free_on_signup';
    case CreditsFreeValidityDays = 'credits.free_validity_days';
    case CreditsAlertThresholds = 'credits.alert_thresholds';
    case CreditsPlanAlertPct = 'credits.plan_alert_pct';
    case CreditsConsumptionOrder = 'credits.consumption_order';
    case CreditsDebitEvent = 'credits.debit_event';
    case CreditsDebitAmount = 'credits.debit_amount';
    case CreditsRefundOnFraudDeletion = 'credits.refund_on_fraud_deletion';
    case CreditsRefundAbusiveCancelHours = 'credits.refund_abusive_cancel_hours';
    case CreditsKeepFreeOnSubscribe = 'credits.keep_free_on_subscribe';
    case CreditsExhaustedReceiveDirect = 'credits.exhausted_receive_direct';
    case CreditsExhaustedProfileVisible = 'credits.exhausted_profile_visible';
    case PlansRollover = 'plans.rollover';
    case BillingGraceDays = 'billing.grace_days';
    case BillingCancelAfterDays = 'billing.cancel_after_days';
    case BillingUpgradeCreditMode = 'billing.upgrade_credit_mode';
    case BillingProrationBehavior = 'billing.proration_behavior';
    case BillingInvoiceEngine = 'billing.invoice_engine';
    case BillingTaxEngine = 'billing.tax_engine';

    // Devis
    case QuotesMaxPerRequest = 'quotes.max_per_request';
    case QuotesDefaultValidityDays = 'quotes.default_validity_days';
    case QuotesWithdrawRefund = 'quotes.withdraw_refund';
    case QuotesAutoNotifyNotSelected = 'quotes.auto_notify_not_selected';
    case QuotesResendAfterWithdraw = 'quotes.resend_after_withdraw';
    case QuotesCancelAcceptanceMinHoursBeforeAppointment = 'quotes.cancel_acceptance_min_hours_before_appointment';
    case QuotesReminderAfterDays = 'quotes.reminder_after_days';
    case QuotesReminderMax = 'quotes.reminder_max';

    // Demandes
    case RequestsExpiryDays = 'requests.expiry_days';
    case RequestsMaxOpenPerClient = 'requests.max_open_per_client';
    case RequestsDuplicateWindowHours = 'requests.duplicate_window_hours';
    case RequestsReopenAllowed = 'requests.reopen_allowed';
    case RequestsReopenExcludePrevious = 'requests.reopen_exclude_previous';
    case RequestsDirectBroadcastDefault = 'requests.direct_broadcast_default';
    case RequestsMaxPhotos = 'requests.max_photos';
    case RequestsMaxFileMb = 'requests.max_file_mb';
    case RequestsDraftRetentionDays = 'requests.draft_retention_days';
    case RequestsDispatchMode = 'requests.dispatch_mode';
    case RequestsWaveAlertPct = 'requests.wave_alert_pct';
    case RequestsRenotifyOnSlotFreed = 'requests.renotify_on_slot_freed';
    case RequestsRetentionYears = 'requests.retention_years';

    // Professionnels, agenda, avis, classement
    case ProMaxJobs = 'pro.max_jobs';
    case ProMinCompletionFeatured = 'pro.min_completion_featured';
    case ProRadiusMinKm = 'pro.radius_min_km';
    case ProRadiusMaxKm = 'pro.radius_max_km';
    case ProPreviewBeforeVerified = 'pro.preview_before_verified';
    case ProPhonePublicAllowed = 'pro.phone_public_allowed';
    case ProVerificationDelayHours = 'pro.verification_delay_hours';
    case ProCompletionWeights = 'pro.completion_weights';
    case ProCompletionPhotosTarget = 'pro.completion_photos_target';
    case AvailabilityBadgeDays = 'availability.badge_days';
    case AppointmentsHoldHours = 'appointments.hold_hours';
    case ReviewsWindowDays = 'reviews.window_days';
    case ReviewsModerationMode = 'reviews.moderation_mode';
    case ReviewsEditDays = 'reviews.edit_days';
    case ReviewsExternalEnabled = 'reviews.external_enabled';
    case RankingMinReviews = 'ranking.min_reviews';
    case RankingBayesM = 'ranking.bayes_m';
    case RankingBayesC = 'ranking.bayes_c';
    case RankingSponsoredMaxPerPage = 'ranking.sponsored_max_per_page';

    // Recherche, SEO, accueil, catalogue
    case SearchDefaultRadiusKm = 'search.default_radius_km';
    case SearchRadiusOptions = 'search.radius_options';
    case SearchWeights = 'search.weights';
    case SearchPageSize = 'search.page_size';
    case SeoMinProsIndex = 'seo.min_pros_index';
    case SeoMinSample = 'seo.min_sample';
    case SeoCityPagesMinPopulation = 'seo.city_pages_min_population';
    case HomeCategoriesMax = 'home.categories_max';
    case HomeProsCount = 'home.pros_count';
    case CatalogPriceRangeSource = 'catalog.price_range_source';
    case CatalogPriceRangeMinSample = 'catalog.price_range_min_sample';
    case CatalogMegaMenuJobs = 'catalog.megamenu_jobs';
    case PlatformPilotDepartments = 'platform.pilot_departments';

    // Modération, messagerie, anti-fraude
    case ModerationOffersPrior = 'moderation.offers_prior';
    case ModerationRequestRiskThreshold = 'moderation.request_risk_threshold';
    case MessagesMaskContactsBeforeAccept = 'messages.mask_contacts_before_accept';
    case MessagesRetentionYears = 'messages.retention_years';
    case MessagesPreQuestionEnabled = 'messages.pre_question_enabled';
    case MessagesPreQuestionDailyMax = 'messages.pre_question_daily_max';
    case AntifraudSmsVerifyRequest = 'antifraud.sms_verify_request';
    case AntifraudSmsVerifyProSignup = 'antifraud.sms_verify_pro_signup';
    case AntifraudBlockDisposableEmails = 'antifraud.block_disposable_emails';

    // Authentification, administration, documents
    case AuthLoginMaxAttempts = 'auth.login_max_attempts';
    case AuthLoginWindowMin = 'auth.login_window_min';
    case AuthSocialProviders = 'auth.social_providers';
    case AuthRememberMeDays = 'auth.remember_me_days';
    case AuthPasswordResetTtlMin = 'auth.password_reset_ttl_min';
    case AuthPasswordMinLength = 'auth.password_min_length';
    case AuthEmailVerificationTtlHours = 'auth.email_verification_ttl_hours';
    case AuthEmailResendCooldownSec = 'auth.email_resend_cooldown_sec';
    case AuthPro2faEnabled = 'auth.pro_2fa_enabled';
    case AdminIdleTimeoutMin = 'admin.idle_timeout_min';
    case AdminReauthMaxAgeMin = 'admin.reauth_max_age_min';
    case AdminInvitationTtlHours = 'admin.invitation_ttl_hours';
    case AdminIpAllowlist = 'admin.ip_allowlist';
    case AdminSupportMaxManualCredits = 'admin.support_max_manual_credits';
    case AdminSupportMonthlyManualCredits = 'admin.support_monthly_manual_credits';
    case DocumentsDeleteIdAfterVerification = 'documents.delete_id_after_verification';
    case DocumentsRetentionAfterRelationYears = 'documents.retention_after_relation_years';
    case DocumentsSignedUrlTtlSec = 'documents.signed_url_ttl_sec';
    case DocumentsExpiryAlertDays = 'documents.expiry_alert_days';
    case AuditRetentionMonths = 'audit.retention_months';

    // RGPD, notifications, cookies, fournisseurs
    case GdprDeletionGraceDays = 'gdpr.deletion_grace_days';
    case GdprInactiveYears = 'gdpr.inactive_years';
    case NotificationsMessageEmailThrottleMin = 'notifications.message_email_throttle_min';
    case NotificationsSmsDailyCap = 'notifications.sms_daily_cap';
    case NotificationsSmsEnabled = 'notifications.sms_enabled';
    case CookiesConsentMonths = 'cookies.consent_months';
    case CookiesPolicyVersion = 'cookies.policy_version';
    case NewsletterProvider = 'newsletter.provider';

    // Identité de la plateforme (BO13) — réseaux sociaux du pied de page (vides = non affichés)
    case SocialFacebook = 'social.facebook';
    case SocialInstagram = 'social.instagram';
    case SocialYoutube = 'social.youtube';
    case SocialTiktok = 'social.tiktok';
    case SocialLinkedin = 'social.linkedin';

    // Fonctionnalités désactivées au MVP (annexe H)
    case FeaturesRequestReopen = 'features.request_reopen';
    case FeaturesSponsored = 'features.sponsored';
    case FeaturesCustomAdminRoles = 'features.custom_admin_roles';

    public function definition(): SettingDefinition
    {
        $d = static fn (T $type, mixed $default, string $label, ?int $min = null, ?int $max = null, ?array $choices = null, bool $proposed = false, bool $exhaustive = false): SettingDefinition => new SettingDefinition($type, $default, $label, $min, $max, $choices, $proposed, $exhaustive);

        return match ($this) {
            self::CreditsFreeOnSignup => $d(T::Integer, 100, 'Devis gratuits attribués à la vérification', 0, 10_000),
            self::CreditsFreeValidityDays => $d(T::NullableInteger, null, 'Validité du crédit gratuit en jours (vide = illimitée)', 1, 3650),
            self::CreditsAlertThresholds => $d(T::IntegerList, [80, 90, 100], 'Seuils d\'alerte (devis gratuits consommés)', 1, 10_000),
            self::CreditsPlanAlertPct => $d(T::IntegerList, [80, 100], 'Seuils d\'alerte forfait (%)', 1, 100),
            self::CreditsConsumptionOrder => $d(T::StringList, ['free', 'plan', 'extra'], 'Ordre de consommation des crédits', choices: ['free', 'plan', 'extra'], exhaustive: true),
            self::CreditsDebitEvent => $d(T::Choice, 'quote_first_send', 'Événement consommant un crédit (Q1)', choices: ['quote_first_send'], proposed: true),
            self::CreditsDebitAmount => $d(T::Integer, 1, 'Crédits débités par envoi de devis', 1, 100, proposed: true),
            self::CreditsRefundOnFraudDeletion => $d(T::Boolean, true, 'Restitution si demande supprimée pour fraude (Q2)', proposed: true),
            self::CreditsRefundAbusiveCancelHours => $d(T::Integer, 24, 'Fenêtre de restitution sur annulation abusive (h)', 0, 720, proposed: true),
            self::CreditsKeepFreeOnSubscribe => $d(T::Boolean, true, 'Conserver les crédits gratuits à la souscription (Q4)', proposed: true),
            self::CreditsExhaustedReceiveDirect => $d(T::Boolean, true, 'Recevoir les demandes directes à 0 crédit (Q8)', proposed: true),
            self::CreditsExhaustedProfileVisible => $d(T::Boolean, true, 'Profil visible à 0 crédit (Q8)', proposed: true),
            self::PlansRollover => $d(T::Boolean, false, 'Report des crédits de forfait non consommés'),
            self::BillingGraceDays => $d(T::Integer, 7, 'Période de grâce après échec de paiement (j)', 0, 60),
            self::BillingCancelAfterDays => $d(T::Integer, 30, 'Annulation définitive après impayé (j)', 1, 120),
            self::BillingUpgradeCreditMode => $d(T::Choice, 'new_minus_consumed', 'Calcul des crédits à l\'upgrade (Q7)', choices: ['new_minus_consumed', 'new_full'], proposed: true),
            self::BillingProrationBehavior => $d(T::Choice, 'create_prorations', 'Prorata Stripe', choices: ['create_prorations', 'always_invoice', 'none'], proposed: true),
            self::BillingInvoiceEngine => $d(T::Choice, 'stripe', 'Moteur de facturation (Q24)', choices: ['stripe', 'internal'], proposed: true),
            self::BillingTaxEngine => $d(T::Choice, 'stripe_tax', 'Calcul de TVA (P-04)', choices: ['stripe_tax', 'internal'], proposed: true),

            self::QuotesMaxPerRequest => $d(T::Integer, 3, 'Devis maximum par demande', 1, 20),
            self::QuotesDefaultValidityDays => $d(T::Integer, 30, 'Validité par défaut d\'un devis (j)', 1, 365),
            self::QuotesWithdrawRefund => $d(T::Boolean, false, 'Restitution du crédit en cas de retrait'),
            self::QuotesAutoNotifyNotSelected => $d(T::Boolean, true, 'Notifier les devis non retenus'),
            self::QuotesResendAfterWithdraw => $d(T::Boolean, false, 'Renvoi d\'un devis après retrait (P-08)', proposed: true),
            self::QuotesCancelAcceptanceMinHoursBeforeAppointment => $d(T::Integer, 48, 'Annulation d\'acceptation : délai minimum avant RDV (h)', 0, 720, proposed: true),
            self::QuotesReminderAfterDays => $d(T::Integer, 3, 'Relance possible après N jours sans consultation', 1, 60, proposed: true),
            self::QuotesReminderMax => $d(T::Integer, 1, 'Nombre de relances gratuites', 0, 5, proposed: true),

            self::RequestsExpiryDays => $d(T::Integer, 30, 'Expiration d\'une demande (j)', 1, 365),
            self::RequestsMaxOpenPerClient => $d(T::Integer, 10, 'Demandes ouvertes simultanées par client', 1, 100),
            self::RequestsDuplicateWindowHours => $d(T::Integer, 72, 'Fenêtre anti-doublon (h)', 0, 720),
            self::RequestsReopenAllowed => $d(T::Integer, 1, 'Réouvertures autorisées d\'une demande complète', 0, 5),
            self::RequestsReopenExcludePrevious => $d(T::Boolean, true, 'Réouverture : exclure les artisans déjà refusés (Q9)', proposed: true),
            self::RequestsDirectBroadcastDefault => $d(T::Boolean, true, 'Demande directe aussi diffusée aux autres'),
            self::RequestsMaxPhotos => $d(T::Integer, 10, 'Photos par demande', 0, 30),
            self::RequestsMaxFileMb => $d(T::Integer, 10, 'Poids maximum par fichier (Mo)', 1, 50),
            self::RequestsDraftRetentionDays => $d(T::Integer, 30, 'Conservation des brouillons (j)', 1, 365),
            self::RequestsDispatchMode => $d(T::Choice, 'all', 'Mode de diffusion (Q10)', choices: ['all', 'waves'], proposed: true),
            self::RequestsWaveAlertPct => $d(T::Integer, 30, 'Alerte KPI « complète avant réponse » (%)', 1, 100, proposed: true),
            self::RequestsRenotifyOnSlotFreed => $d(T::Boolean, true, 'Notifier à nouveau quand une place se libère (P-09)', proposed: true),
            self::RequestsRetentionYears => $d(T::Integer, 3, 'Conservation des demandes et devis (années)', 1, 10, proposed: true),

            self::ProMaxJobs => $d(T::Integer, 5, 'Métiers maximum par artisan', 1, 20),
            self::ProMinCompletionFeatured => $d(T::Integer, 80, 'Complétude minimale pour la mise en avant (%)', 0, 100),
            self::ProRadiusMinKm => $d(T::Integer, 5, 'Rayon d\'intervention minimum (km)', 1, 500),
            self::ProRadiusMaxKm => $d(T::Integer, 150, 'Rayon d\'intervention maximum (km)', 1, 1000),
            self::ProPreviewBeforeVerified => $d(T::Boolean, true, 'Aperçu des demandes avant vérification'),
            self::ProPhonePublicAllowed => $d(T::Boolean, true, 'Option « afficher mon téléphone » (Q13)', proposed: true),
            self::ProCompletionWeights => $d(T::WeightMap, ['logo' => 0.15, 'description' => 0.15, 'photos' => 0.20, 'hours' => 0.10, 'zone' => 0.15, 'services' => 0.15, 'labels' => 0.10], 'Pondérations de la jauge de complétude (PR08.1)', choices: ['logo', 'description', 'photos', 'hours', 'zone', 'services', 'labels'], proposed: true),
            self::ProCompletionPhotosTarget => $d(T::Integer, 5, 'Photos de réalisations attendues pour la complétude (PR08.1)', 1, 50),
            self::ProVerificationDelayHours => $d(T::Integer, 48, 'Délai de vérification annoncé aux artisans (heures ouvrées) — CDC A03.7', 1, 720),
            self::AvailabilityBadgeDays => $d(T::Integer, 7, 'Fenêtre du badge Disponible (j)', 1, 60),
            self::AppointmentsHoldHours => $d(T::Integer, 24, 'Verrouillage d\'un créneau proposé (h)', 1, 168),
            self::ReviewsWindowDays => $d(T::Integer, 90, 'Fenêtre de dépôt d\'avis (j)', 1, 730),
            self::ReviewsModerationMode => $d(T::Choice, 'a_priori', 'Modération des avis', choices: ['a_priori', 'a_posteriori']),
            self::ReviewsEditDays => $d(T::Integer, 7, 'Modification d\'un avis (j)', 0, 90),
            self::ReviewsExternalEnabled => $d(T::Boolean, false, 'Avis hors plateforme (V3)', proposed: true),
            self::RankingMinReviews => $d(T::Integer, 5, 'Avis minimum pour « mieux notés »', 1, 100),
            self::RankingBayesM => $d(T::NullableDecimal, null, 'Note bayésienne : m (vide = moyenne globale)'),
            self::RankingBayesC => $d(T::Integer, 10, 'Note bayésienne : C', 0, 1000),
            self::RankingSponsoredMaxPerPage => $d(T::Integer, 2, 'Positions sponsorisées par page (V2)', 0, 10, proposed: true),

            self::SearchDefaultRadiusKm => $d(T::Integer, 30, 'Rayon de recherche par défaut (km)', 1, 500),
            self::SearchRadiusOptions => $d(T::IntegerList, [5, 10, 20, 30, 50, 100], 'Rayons proposés (km)', 1, 500),
            // « À calibrer » (annexe G) : répartition initiale proposée, à confirmer avec le porteur.
            self::SearchWeights => $d(T::WeightMap, ['job' => 0.30, 'distance' => 0.25, 'rating' => 0.20, 'responsiveness' => 0.10, 'completion' => 0.10, 'availability' => 0.05], 'Pondérations de pertinence (RG-020)', choices: ['job', 'distance', 'rating', 'responsiveness', 'completion', 'availability']),
            self::SearchPageSize => $d(T::Integer, 20, 'Résultats par page', 5, 100),
            self::SeoMinProsIndex => $d(T::Integer, 3, 'Artisans minimum pour indexer métier × ville', 0, 100),
            self::SeoCityPagesMinPopulation => $d(T::Integer, 5000, 'Population minimale pour activer une page ville (BO07.1)', 0, 1_000_000, proposed: true),
            self::SeoMinSample => $d(T::Integer, 5, 'Échantillon minimum pour statistiques locales', 1, 1000),
            self::HomeCategoriesMax => $d(T::Integer, 12, 'Cartes catégories sur l\'accueil', 1, 48),
            self::HomeProsCount => $d(T::Integer, 8, 'Cartes artisans sur l\'accueil', 1, 48),
            self::CatalogPriceRangeSource => $d(T::Choice, 'manual', 'Source des prix indicatifs (Q26)', choices: ['manual', 'computed'], proposed: true),
            // P01.1 : « 3 à 5 métiers les plus recherchés » par catégorie dans le méga-menu.
            self::CatalogMegaMenuJobs => $d(T::Integer, 5, 'Métiers par catégorie dans le méga-menu', 3, 5, proposed: true),
            self::CatalogPriceRangeMinSample => $d(T::Integer, 20, 'Échantillon minimum pour un prix calculé', 1, 1000, proposed: true),
            self::PlatformPilotDepartments => $d(T::StringList, ['14', '27', '50', '61', '76'], 'Départements pilotes (Q27)', proposed: true),

            self::ModerationOffersPrior => $d(T::Boolean, true, 'Modération a priori des offres'),
            self::ModerationRequestRiskThreshold => $d(T::Integer, 70, 'Seuil de mise en modération d\'une demande (/100)', 0, 100),
            self::MessagesMaskContactsBeforeAccept => $d(T::Boolean, true, 'Masquage des coordonnées avant acceptation'),
            self::MessagesRetentionYears => $d(T::Integer, 3, 'Conservation des messages (années)', 1, 10),
            self::MessagesPreQuestionEnabled => $d(T::Boolean, true, 'Question préalable hors demande (Q14)', proposed: true),
            self::MessagesPreQuestionDailyMax => $d(T::Integer, 3, 'Questions préalables par client et par jour', 0, 50, proposed: true),
            self::AntifraudSmsVerifyRequest => $d(T::Boolean, false, 'Vérification SMS au dépôt'),
            self::AntifraudSmsVerifyProSignup => $d(T::Boolean, false, 'Vérification SMS à l\'inscription pro (P-17)', proposed: true),
            self::AntifraudBlockDisposableEmails => $d(T::Boolean, true, 'Blocage des emails jetables'),

            self::AuthLoginMaxAttempts => $d(T::Integer, 5, 'Tentatives de connexion avant blocage', 3, 20),
            self::AuthLoginWindowMin => $d(T::Integer, 15, 'Fenêtre des tentatives de connexion (min)', 1, 1440),
            self::AuthSocialProviders => $d(T::StringList, [], 'Fournisseurs de connexion sociale (V2)', choices: ['google', 'apple', 'facebook']),
            self::AuthRememberMeDays => $d(T::Integer, 30, 'Durée de « Rester connecté » (j)', 1, 90, proposed: true),
            self::AuthPasswordResetTtlMin => $d(T::Integer, 60, 'Validité du lien de réinitialisation (min)', 10, 60, proposed: true),
            // Audit V2.1 : minimum 12 caractères, non abaissable.
            self::AuthPasswordMinLength => $d(T::Integer, 12, 'Longueur minimale du mot de passe', 12, 128, proposed: true),
            self::AuthEmailResendCooldownSec => $d(T::Integer, 60, 'Délai minimal entre deux envois du lien de vérification (s) — CDC A02', 30, 3600, proposed: true),
            self::AuthEmailVerificationTtlHours => $d(T::Integer, 24, 'Validité du lien de vérification d’e-mail (h) — CDC A02', 1, 72, proposed: true),
            self::AuthPro2faEnabled => $d(T::Boolean, false, '2FA pour les artisans (V2)', proposed: true),
            self::AdminIdleTimeoutMin => $d(T::Integer, 15, 'Déconnexion admin après inactivité (min)', 5, 60, proposed: true),
            self::AdminInvitationTtlHours => $d(T::Integer, 72, 'Validité d’une invitation administrateur (h)', 1, 168, proposed: true),
            self::AdminIpAllowlist => $d(T::StringList, [], 'Adresses IP / plages CIDR autorisées au back-office (vide = toutes)', proposed: true),
            self::AdminReauthMaxAgeMin => $d(T::Integer, 5, 'Ancienneté maximale de ré-authentification (min)', 1, 15, proposed: true),
            self::AdminSupportMaxManualCredits => $d(T::Integer, 20, 'Plafond de crédits par geste (Support)', 0, 1000, proposed: true),
            self::AdminSupportMonthlyManualCredits => $d(T::Integer, 100, 'Plafond mensuel de crédits (Support)', 0, 10_000, proposed: true),
            self::DocumentsDeleteIdAfterVerification => $d(T::Boolean, true, 'Supprimer la pièce d\'identité après vérification (Q16)', proposed: true),
            self::DocumentsRetentionAfterRelationYears => $d(T::Integer, 1, 'Conservation des justificatifs après la relation (années)', 0, 10, proposed: true),
            // Cadrage §5.5 : URLs signées ≤ 5 min.
            self::DocumentsExpiryAlertDays => $d(T::IntegerList, [30, 7, 0], 'Alertes avant expiration d\'un justificatif (jours) — RG-032', 0, 365),
            self::DocumentsSignedUrlTtlSec => $d(T::Integer, 300, 'Durée des URLs signées (s)', 30, 300, proposed: true),
            self::AuditRetentionMonths => $d(T::Integer, 12, 'Conservation des journaux (mois)', 12, 120, proposed: true),

            self::GdprDeletionGraceDays => $d(T::Integer, 14, 'Délai avant suppression définitive (j)', 0, 90),
            self::GdprInactiveYears => $d(T::Integer, 3, 'Inactivité avant anonymisation (années)', 1, 10),
            self::NotificationsMessageEmailThrottleMin => $d(T::Integer, 15, 'Regroupement des emails de messages (min)', 1, 1440),
            self::NotificationsSmsDailyCap => $d(T::Integer, 3, 'SMS maximum par utilisateur et par jour', 0, 50),
            self::NotificationsSmsEnabled => $d(T::Boolean, false, 'Notifications SMS (V2)', proposed: true),
            self::CookiesPolicyVersion => $d(T::Integer, 1, 'Version de la politique cookies (l\'incrémenter redemande le consentement)', 1, 10_000, proposed: true),
            self::CookiesConsentMonths => $d(T::Integer, 6, 'Durée du consentement cookies (mois)', 1, 13),
            self::NewsletterProvider => $d(T::Choice, 'brevo', 'Outil de newsletter (Q25)', choices: ['brevo', 'mailchimp', 'internal'], proposed: true),

            self::SocialFacebook => $d(T::NullableUrl, null, 'Page Facebook', choices: ['facebook.com'], proposed: true),
            self::SocialInstagram => $d(T::NullableUrl, null, 'Compte Instagram', choices: ['instagram.com'], proposed: true),
            self::SocialYoutube => $d(T::NullableUrl, null, 'Chaîne YouTube', choices: ['youtube.com'], proposed: true),
            self::SocialTiktok => $d(T::NullableUrl, null, 'Compte TikTok', choices: ['tiktok.com'], proposed: true),
            self::SocialLinkedin => $d(T::NullableUrl, null, 'Page LinkedIn', choices: ['linkedin.com'], proposed: true),

            self::FeaturesRequestReopen => $d(T::Boolean, false, 'Réouverture de demande (V2, P-12)', proposed: true),
            self::FeaturesSponsored => $d(T::Boolean, false, 'Mise en avant payante (V2)', proposed: true),
            self::FeaturesCustomAdminRoles => $d(T::Boolean, false, 'Rôles admin personnalisés (P-06)', proposed: true),
        };
    }
}
