<?php
namespace Modules\Template\Data;

/**
 * Catalogue de modèles de formulaires prêts à l'emploi.
 *
 * Les modèles sont des données statiques (versionnées avec le code) : pas de
 * table, pas de migration, pas de CRUD d'administration. Le service
 * TemplateService les instancie en un vrai formulaire appartenant à
 * l'utilisateur en réutilisant FormModel / SectionModel / QuestionService.
 *
 * Structure d'un modèle :
 *   key         string   identifiant stable (slug)
 *   name        string   titre du formulaire créé
 *   category    string   clé de CATEGORIES
 *   description string   sous-titre affiché dans la galerie + description du formulaire
 *   icon        string   nom d'icône kbIcon() (voir utils.js)
 *   accent      string   couleur de thème appliquée (#RRGGBB)
 *   sections    array    [ ['title','description','questions'=>[ ... ]], ... ]
 *
 * Structure d'une question :
 *   type       short_text|long_text|email|phone|radio|checkbox|dropdown|date|time|linear_scale|grid
 *   label      string
 *   required   bool (défaut false)
 *   help_text  string (optionnel)
 *   options    array  (radio/checkbox/dropdown)
 *   scale_min / scale_max / scale_step  (linear_scale)
 *   grid_rows / grid_columns            (grid)
 *   phone_default_country               (phone, code ISO ex: 'CM')
 */
final class TemplateCatalog
{
    public const CATEGORIES = [
        'general'   => 'Général',
        'marketing' => 'Marketing & Campagnes',
        'feedback'  => 'Satisfaction & Feedback',
        'events'    => 'Événementiel',
        'hr'        => 'RH & Recrutement',
        'education' => 'Formation & Éducation',
        'support'   => 'Services & Support',
    ];

    /** @return array<string,array> indexé par key */
    public static function all(): array
    {
        $list = [
            self::contact(),
            self::suggestions(),
            self::leadB2B(),
            self::newsletter(),
            self::waitlist(),
            self::marketResearch(),
            self::csat(),
            self::nps(),
            self::productFeedback(),
            self::eventRegistration(),
            self::eventFeedback(),
            self::callForSpeakers(),
            self::jobApplication(),
            self::leaveRequest(),
            self::trainingEval(),
            self::courseRegistration(),
            self::supportTicket(),
            self::appointment(),
        ];

        $indexed = [];
        foreach ($list as $tpl) {
            $indexed[$tpl['key']] = $tpl;
        }
        return $indexed;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Catalogue complet pour la galerie ET l'aperçu : métadonnées, compteurs
     * et l'arbre des sections / questions (lecture seule côté client).
     * @return array<int,array>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::all() as $tpl) {
            $questionCount = 0;
            foreach ($tpl['sections'] as $s) {
                $questionCount += count($s['questions'] ?? []);
            }
            $out[] = [
                'key'            => $tpl['key'],
                'name'           => $tpl['name'],
                'category'       => $tpl['category'],
                'category_label' => self::CATEGORIES[$tpl['category']] ?? $tpl['category'],
                'description'    => $tpl['description'],
                'icon'           => $tpl['icon'],
                'accent'         => $tpl['accent'],
                'section_count'  => count($tpl['sections']),
                'question_count' => $questionCount,
                'sections'       => $tpl['sections'],
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GÉNÉRAL
    // ─────────────────────────────────────────────────────────────────────────

    private static function contact(): array
    {
        return [
            'key' => 'contact', 'name' => 'Formulaire de contact', 'category' => 'general',
            'description' => "Recevez les demandes de vos visiteurs : identité, sujet et message.",
            'icon' => 'mail', 'accent' => '#4F46E5',
            'sections' => [[
                'title' => 'Vos coordonnées',
                'description' => "Nous revenons vers vous sous 48 h ouvrées.",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Nom complet', 'required' => true],
                    ['type' => 'email', 'label' => 'Adresse e-mail', 'required' => true],
                    ['type' => 'phone', 'label' => 'Téléphone', 'phone_default_country' => 'CM',
                        'help_text' => 'Optionnel — si vous préférez être rappelé.'],
                    ['type' => 'radio', 'label' => 'Objet de votre demande', 'required' => true,
                        'options' => ['Question commerciale', 'Support technique', 'Partenariat', 'Presse', 'Autre']],
                    ['type' => 'long_text', 'label' => 'Votre message', 'required' => true],
                    ['type' => 'checkbox', 'label' => 'Consentement',
                        'options' => ["J'accepte d'être recontacté au sujet de ma demande"], 'required' => true],
                ],
            ]],
        ];
    }

    private static function suggestions(): array
    {
        return [
            'key' => 'suggestions', 'name' => 'Boîte à idées', 'category' => 'general',
            'description' => "Collectez les suggestions d'amélioration de vos équipes ou de vos clients.",
            'icon' => 'sparkles', 'accent' => '#7C3AED',
            'sections' => [[
                'title' => 'Votre idée',
                'description' => "Toutes les propositions sont lues. Merci d'être précis.",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Titre de la suggestion', 'required' => true],
                    ['type' => 'dropdown', 'label' => 'Domaine concerné', 'required' => true,
                        'options' => ['Produit', 'Service client', 'Processus interne', 'Communication', 'Autre']],
                    ['type' => 'long_text', 'label' => 'Décrivez votre idée et le problème qu\'elle résout', 'required' => true],
                    ['type' => 'linear_scale', 'label' => 'Impact estimé', 'scale_min' => 1, 'scale_max' => 5, 'scale_step' => 1,
                        'help_text' => '1 = mineur, 5 = transformateur'],
                    ['type' => 'short_text', 'label' => 'Votre nom (facultatif)'],
                ],
            ]],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MARKETING & CAMPAGNES
    // ─────────────────────────────────────────────────────────────────────────

    private static function leadB2B(): array
    {
        return [
            'key' => 'lead_b2b', 'name' => 'Génération de leads B2B', 'category' => 'marketing',
            'description' => "Qualifiez vos prospects entreprise : besoin, budget, échéance et décideur.",
            'icon' => 'building', 'accent' => '#2563EB',
            'sections' => [
                [
                    'title' => 'Contact professionnel',
                    'description' => "",
                    'questions' => [
                        ['type' => 'short_text', 'label' => 'Prénom', 'required' => true],
                        ['type' => 'short_text', 'label' => 'Nom', 'required' => true],
                        ['type' => 'email', 'label' => 'E-mail professionnel', 'required' => true],
                        ['type' => 'short_text', 'label' => 'Entreprise', 'required' => true],
                        ['type' => 'short_text', 'label' => 'Fonction / poste', 'required' => true],
                        ['type' => 'phone', 'label' => 'Téléphone direct', 'phone_default_country' => 'CM'],
                    ],
                ],
                [
                    'title' => 'Votre projet',
                    'description' => "Ces informations nous permettent de préparer un échange utile.",
                    'questions' => [
                        ['type' => 'dropdown', 'label' => 'Taille de l\'entreprise', 'required' => true,
                            'options' => ['1–10', '11–50', '51–200', '201–1000', '1000+']],
                        ['type' => 'checkbox', 'label' => 'Quels besoins souhaitez-vous couvrir ?', 'required' => true,
                            'options' => ['Collecte de données', 'Enquêtes clients', 'Inscriptions événements', 'Automatisation / intégrations', 'Conformité & sécurité']],
                        ['type' => 'dropdown', 'label' => 'Budget annuel envisagé', 'required' => true,
                            'options' => ['< 1 000 €', '1 000 – 5 000 €', '5 000 – 20 000 €', '> 20 000 €', 'Non défini']],
                        ['type' => 'radio', 'label' => 'Échéance du projet', 'required' => true,
                            'options' => ['Immédiate', 'Sous 1 mois', '1 à 3 mois', '3 à 6 mois', 'Simple veille']],
                        ['type' => 'radio', 'label' => 'Êtes-vous le décideur sur ce sujet ?',
                            'options' => ['Oui', 'Non', 'Décision partagée']],
                        ['type' => 'long_text', 'label' => 'Contexte ou attentes particulières'],
                    ],
                ],
            ],
        ];
    }

    private static function newsletter(): array
    {
        return [
            'key' => 'newsletter', 'name' => 'Inscription à la newsletter', 'category' => 'marketing',
            'description' => "Un formulaire court pour faire grandir votre liste d'abonnés.",
            'icon' => 'bell', 'accent' => '#0EA5E9',
            'sections' => [[
                'title' => 'Restez informé',
                'description' => "Une newsletter par mois, désinscription en un clic.",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Prénom', 'required' => true],
                    ['type' => 'email', 'label' => 'Adresse e-mail', 'required' => true],
                    ['type' => 'checkbox', 'label' => 'Sujets qui vous intéressent',
                        'options' => ['Nouveautés produit', 'Conseils & bonnes pratiques', 'Études de cas', 'Événements & webinaires']],
                    ['type' => 'checkbox', 'label' => 'Consentement', 'required' => true,
                        'options' => ["J'accepte de recevoir la newsletter et la politique de confidentialité"]],
                ],
            ]],
        ];
    }

    private static function waitlist(): array
    {
        return [
            'key' => 'waitlist', 'name' => "Liste d'attente — lancement produit", 'category' => 'marketing',
            'description' => "Mesurez l'intérêt avant un lancement et constituez une liste d'early adopters.",
            'icon' => 'rocket', 'accent' => '#DB2777',
            'sections' => [[
                'title' => 'Rejoignez la liste d\'attente',
                'description' => "Les inscrits sont prévenus en priorité et bénéficient d'un tarif de lancement.",
                'questions' => [
                    ['type' => 'email', 'label' => 'Adresse e-mail', 'required' => true],
                    ['type' => 'short_text', 'label' => 'Prénom'],
                    ['type' => 'radio', 'label' => 'Vous êtes plutôt…', 'required' => true,
                        'options' => ['Particulier', 'Indépendant / TPE', 'PME', 'Grande entreprise']],
                    ['type' => 'linear_scale', 'label' => "À quel point ce produit vous manque-t-il aujourd'hui ?",
                        'scale_min' => 1, 'scale_max' => 10, 'scale_step' => 1, 'required' => true,
                        'help_text' => '1 = pas du tout, 10 = indispensable'],
                    ['type' => 'long_text', 'label' => 'Quel problème aimeriez-vous qu\'il règle en priorité ?'],
                    ['type' => 'checkbox', 'label' => 'Bêta-test',
                        'options' => ["Je souhaite participer à la phase de test privée"]],
                ],
            ]],
        ];
    }

    private static function marketResearch(): array
    {
        return [
            'key' => 'market_research', 'name' => 'Étude de marché', 'category' => 'marketing',
            'description' => "Comprenez votre cible : habitudes, freins, budget et concurrence.",
            'icon' => 'chart-bar', 'accent' => '#0D9488',
            'sections' => [
                [
                    'title' => 'Profil',
                    'description' => "",
                    'questions' => [
                        ['type' => 'radio', 'label' => 'Tranche d\'âge', 'required' => true,
                            'options' => ['moins de 18', '18–24', '25–34', '35–44', '45–54', '55+']],
                        ['type' => 'dropdown', 'label' => 'Situation professionnelle', 'required' => true,
                            'options' => ['Étudiant', 'Salarié', 'Indépendant', 'Chef d\'entreprise', 'Sans emploi', 'Retraité']],
                        ['type' => 'short_text', 'label' => 'Ville / région'],
                    ],
                ],
                [
                    'title' => 'Habitudes & besoins',
                    'description' => "",
                    'questions' => [
                        ['type' => 'radio', 'label' => 'À quelle fréquence utilisez-vous ce type de produit / service ?', 'required' => true,
                            'options' => ['Tous les jours', 'Chaque semaine', 'Chaque mois', 'Rarement', 'Jamais']],
                        ['type' => 'checkbox', 'label' => 'Quels critères comptent le plus dans votre choix ?', 'required' => true,
                            'options' => ['Prix', 'Qualité', 'Rapidité', 'Service client', 'Marque / réputation', 'Recommandations']],
                        ['type' => 'long_text', 'label' => 'Qu\'est-ce qui vous freine aujourd\'hui ?'],
                        ['type' => 'dropdown', 'label' => 'Budget mensuel consacré à ce poste', 'required' => true,
                            'options' => ['0 €', '< 20 €', '20 – 50 €', '50 – 100 €', '> 100 €']],
                        ['type' => 'short_text', 'label' => 'Quelle(s) solution(s) utilisez-vous actuellement ?'],
                        ['type' => 'linear_scale', 'label' => 'Satisfaction vis-à-vis de votre solution actuelle',
                            'scale_min' => 1, 'scale_max' => 5, 'scale_step' => 1],
                    ],
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SATISFACTION & FEEDBACK
    // ─────────────────────────────────────────────────────────────────────────

    private static function csat(): array
    {
        return [
            'key' => 'csat', 'name' => 'Enquête de satisfaction client', 'category' => 'feedback',
            'description' => "Mesurez la satisfaction après un achat ou une interaction avec le support.",
            'icon' => 'check-square', 'accent' => '#16A34A',
            'sections' => [[
                'title' => 'Votre expérience',
                'description' => "5 minutes suffisent — merci pour votre retour.",
                'questions' => [
                    ['type' => 'linear_scale', 'label' => 'Quel est votre niveau de satisfaction global ?',
                        'scale_min' => 1, 'scale_max' => 5, 'scale_step' => 1, 'required' => true,
                        'help_text' => '1 = très insatisfait, 5 = très satisfait'],
                    ['type' => 'grid', 'label' => 'Évaluez chaque aspect',
                        'grid_rows' => ['Qualité du produit / service', 'Rapidité', 'Amabilité de l\'équipe', 'Rapport qualité-prix'],
                        'grid_columns' => ['Très insatisfait', 'Insatisfait', 'Neutre', 'Satisfait', 'Très satisfait']],
                    ['type' => 'radio', 'label' => 'Votre problème a-t-il été résolu ?', 'required' => true,
                        'options' => ['Oui, complètement', 'En partie', 'Non']],
                    ['type' => 'long_text', 'label' => 'Qu\'aurions-nous pu faire mieux ?'],
                    ['type' => 'radio', 'label' => 'Recommanderiez-vous notre entreprise ?', 'required' => true,
                        'options' => ['Oui', 'Peut-être', 'Non']],
                ],
            ]],
        ];
    }

    private static function nps(): array
    {
        return [
            'key' => 'nps', 'name' => 'Net Promoter Score (NPS)', 'category' => 'feedback',
            'description' => "La question NPS standard, plus une relance ouverte selon la note.",
            'icon' => 'chart-line', 'accent' => '#F59E0B',
            'sections' => [[
                'title' => 'Une seule question (ou presque)',
                'description' => "",
                'questions' => [
                    ['type' => 'linear_scale', 'label' => 'Quelle est la probabilité que vous nous recommandiez à un proche ou un collègue ?',
                        'scale_min' => 0, 'scale_max' => 10, 'scale_step' => 1, 'required' => true,
                        'help_text' => '0 = pas du tout probable, 10 = extrêmement probable'],
                    ['type' => 'long_text', 'label' => 'Quelle est la principale raison de votre note ?', 'required' => true],
                    ['type' => 'short_text', 'label' => 'Que faudrait-il pour vous faire gagner 1 ou 2 points ?'],
                ],
            ]],
        ];
    }

    private static function productFeedback(): array
    {
        return [
            'key' => 'product_feedback', 'name' => "Retour d'expérience produit", 'category' => 'feedback',
            'description' => "Recueillez les impressions d'usage, les bugs et les fonctionnalités attendues.",
            'icon' => 'chat', 'accent' => '#4F46E5',
            'sections' => [[
                'title' => 'Votre usage',
                'description' => "",
                'questions' => [
                    ['type' => 'radio', 'label' => 'Depuis combien de temps utilisez-vous le produit ?', 'required' => true,
                        'options' => ['Moins d\'une semaine', '1 à 4 semaines', '1 à 6 mois', 'Plus de 6 mois']],
                    ['type' => 'radio', 'label' => 'À quelle fréquence l\'utilisez-vous ?', 'required' => true,
                        'options' => ['Tous les jours', 'Plusieurs fois par semaine', 'Chaque semaine', 'Moins souvent']],
                    ['type' => 'linear_scale', 'label' => 'À quel point le produit répond-il à vos besoins ?',
                        'scale_min' => 1, 'scale_max' => 5, 'scale_step' => 1, 'required' => true],
                    ['type' => 'long_text', 'label' => 'Qu\'est-ce que vous appréciez le plus ?'],
                    ['type' => 'long_text', 'label' => 'Qu\'est-ce qui vous frustre ou vous ralentit ?'],
                    ['type' => 'long_text', 'label' => 'Quelle fonctionnalité aimeriez-vous voir ajoutée ?'],
                    ['type' => 'radio', 'label' => 'Avez-vous rencontré un bug récemment ?',
                        'options' => ['Oui', 'Non']],
                ],
            ]],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉVÉNEMENTIEL
    // ─────────────────────────────────────────────────────────────────────────

    private static function eventRegistration(): array
    {
        return [
            'key' => 'event_registration', 'name' => 'Inscription à un événement', 'category' => 'events',
            'description' => "Participants, choix d'ateliers, régime alimentaire et logistique.",
            'icon' => 'calendar', 'accent' => '#7C3AED',
            'sections' => [
                [
                    'title' => 'Participant',
                    'description' => "",
                    'questions' => [
                        ['type' => 'short_text', 'label' => 'Nom complet', 'required' => true],
                        ['type' => 'email', 'label' => 'E-mail', 'required' => true],
                        ['type' => 'phone', 'label' => 'Téléphone', 'phone_default_country' => 'CM', 'required' => true],
                        ['type' => 'short_text', 'label' => 'Organisation / entreprise'],
                        ['type' => 'short_text', 'label' => 'Fonction'],
                    ],
                ],
                [
                    'title' => 'Participation',
                    'description' => "",
                    'questions' => [
                        ['type' => 'radio', 'label' => 'Format de participation', 'required' => true,
                            'options' => ['Sur place', 'En ligne']],
                        ['type' => 'checkbox', 'label' => 'Ateliers souhaités (matinée)',
                            'options' => ['Atelier A — Découverte', 'Atelier B — Approfondissement', 'Atelier C — Cas pratiques']],
                        ['type' => 'dropdown', 'label' => 'Régime alimentaire (déjeuner sur place)',
                            'options' => ['Aucune restriction', 'Végétarien', 'Végétalien', 'Sans gluten', 'Halal', 'Autre']],
                        ['type' => 'radio', 'label' => 'Avez-vous besoin d\'une attestation de présence ?',
                            'options' => ['Oui', 'Non']],
                        ['type' => 'long_text', 'label' => 'Besoins spécifiques (accessibilité, etc.)'],
                    ],
                ],
            ],
        ];
    }

    private static function eventFeedback(): array
    {
        return [
            'key' => 'event_feedback', 'name' => 'Évaluation post-événement', 'category' => 'events',
            'description' => "Recueillez à chaud l'avis des participants sur le contenu et l'organisation.",
            'icon' => 'sparkles', 'accent' => '#DB2777',
            'sections' => [[
                'title' => 'Votre avis compte',
                'description' => "",
                'questions' => [
                    ['type' => 'linear_scale', 'label' => 'Note globale de l\'événement',
                        'scale_min' => 1, 'scale_max' => 5, 'scale_step' => 1, 'required' => true],
                    ['type' => 'grid', 'label' => 'Évaluez les différents aspects',
                        'grid_rows' => ['Qualité des intervenants', 'Pertinence du contenu', 'Organisation & logistique', 'Lieu / plateforme', 'Networking'],
                        'grid_columns' => ['1', '2', '3', '4', '5']],
                    ['type' => 'radio', 'label' => 'La durée était…', 'options' => ['Trop courte', 'Parfaite', 'Trop longue']],
                    ['type' => 'long_text', 'label' => 'Ce que vous avez préféré'],
                    ['type' => 'long_text', 'label' => 'Ce qui pourrait être amélioré'],
                    ['type' => 'radio', 'label' => 'Reviendriez-vous à une prochaine édition ?', 'required' => true,
                        'options' => ['Oui', 'Peut-être', 'Non']],
                    ['type' => 'short_text', 'label' => 'Thèmes que vous aimeriez voir la prochaine fois'],
                ],
            ]],
        ];
    }

    private static function callForSpeakers(): array
    {
        return [
            'key' => 'call_for_speakers', 'name' => 'Appel à intervenants', 'category' => 'events',
            'description' => "Collectez les propositions de conférences : sujet, format, bio et besoins techniques.",
            'icon' => 'users', 'accent' => '#2563EB',
            'sections' => [
                [
                    'title' => 'Intervenant',
                    'description' => "",
                    'questions' => [
                        ['type' => 'short_text', 'label' => 'Nom complet', 'required' => true],
                        ['type' => 'email', 'label' => 'E-mail', 'required' => true],
                        ['type' => 'short_text', 'label' => 'Organisation'],
                        ['type' => 'short_text', 'label' => 'Profil LinkedIn / site web'],
                        ['type' => 'long_text', 'label' => 'Courte biographie (3–4 phrases)', 'required' => true],
                    ],
                ],
                [
                    'title' => 'Proposition',
                    'description' => "",
                    'questions' => [
                        ['type' => 'short_text', 'label' => 'Titre de l\'intervention', 'required' => true],
                        ['type' => 'long_text', 'label' => 'Résumé (200 mots max)', 'required' => true],
                        ['type' => 'radio', 'label' => 'Format', 'required' => true,
                            'options' => ['Conférence 20 min', 'Conférence 40 min', 'Atelier 90 min', 'Table ronde']],
                        ['type' => 'dropdown', 'label' => 'Niveau visé',
                            'options' => ['Débutant', 'Intermédiaire', 'Avancé', 'Tous niveaux']],
                        ['type' => 'checkbox', 'label' => 'Besoins techniques',
                            'options' => ['Micro-cravate', 'Connexion internet', 'Prise HDMI', 'Paperboard', 'Aucun']],
                        ['type' => 'radio', 'label' => 'Avez-vous déjà présenté ce sujet ?',
                            'options' => ['Oui', 'Non']],
                    ],
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RH & RECRUTEMENT
    // ─────────────────────────────────────────────────────────────────────────

    private static function jobApplication(): array
    {
        return [
            'key' => 'job_application', 'name' => 'Candidature à un poste', 'category' => 'hr',
            'description' => "Formulaire de candidature : parcours, disponibilité, prétentions et motivation.",
            'icon' => 'user', 'accent' => '#4F46E5',
            'sections' => [
                [
                    'title' => 'Identité & contact',
                    'description' => "",
                    'questions' => [
                        ['type' => 'short_text', 'label' => 'Prénom', 'required' => true],
                        ['type' => 'short_text', 'label' => 'Nom', 'required' => true],
                        ['type' => 'email', 'label' => 'E-mail', 'required' => true],
                        ['type' => 'phone', 'label' => 'Téléphone', 'phone_default_country' => 'CM', 'required' => true],
                        ['type' => 'short_text', 'label' => 'Ville de résidence'],
                        ['type' => 'short_text', 'label' => 'Lien portfolio / LinkedIn'],
                    ],
                ],
                [
                    'title' => 'Parcours',
                    'description' => "",
                    'questions' => [
                        ['type' => 'short_text', 'label' => 'Poste visé', 'required' => true],
                        ['type' => 'dropdown', 'label' => 'Années d\'expérience', 'required' => true,
                            'options' => ['Moins de 1 an', '1–3 ans', '3–5 ans', '5–10 ans', 'Plus de 10 ans']],
                        ['type' => 'dropdown', 'label' => 'Niveau d\'études le plus élevé',
                            'options' => ['Baccalauréat', 'Bac+2', 'Bac+3 / Licence', 'Bac+5 / Master', 'Doctorat']],
                        ['type' => 'date', 'label' => 'Date de disponibilité', 'required' => true],
                        ['type' => 'radio', 'label' => 'Type de contrat recherché', 'required' => true,
                            'options' => ['CDI', 'CDD', 'Stage', 'Alternance', 'Freelance']],
                        ['type' => 'short_text', 'label' => 'Prétentions salariales (annuel brut)'],
                        ['type' => 'long_text', 'label' => 'Lettre de motivation', 'required' => true],
                        ['type' => 'radio', 'label' => 'Acceptez-vous le télétravail partiel ?',
                            'options' => ['Oui', 'Non', 'Indifférent']],
                    ],
                ],
            ],
        ];
    }

    private static function leaveRequest(): array
    {
        return [
            'key' => 'leave_request', 'name' => 'Demande de congé', 'category' => 'hr',
            'description' => "Formulaire interne : type d'absence, dates, remplaçant et validation manager.",
            'icon' => 'clock', 'accent' => '#0D9488',
            'sections' => [[
                'title' => 'Votre demande',
                'description' => "À soumettre au moins 2 semaines avant la date souhaitée.",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Nom et prénom', 'required' => true],
                    ['type' => 'short_text', 'label' => 'Service / équipe', 'required' => true],
                    ['type' => 'short_text', 'label' => 'Manager', 'required' => true],
                    ['type' => 'dropdown', 'label' => 'Type d\'absence', 'required' => true,
                        'options' => ['Congés payés', 'RTT', 'Congé sans solde', 'Congé maladie', 'Événement familial', 'Autre']],
                    ['type' => 'date', 'label' => 'Date de début', 'required' => true],
                    ['type' => 'date', 'label' => 'Date de reprise', 'required' => true],
                    ['type' => 'radio', 'label' => 'Demi-journée ?',
                        'options' => ['Non', 'Oui — matin', 'Oui — après-midi']],
                    ['type' => 'short_text', 'label' => 'Personne assurant l\'intérim'],
                    ['type' => 'long_text', 'label' => 'Commentaire (facultatif)'],
                ],
            ]],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORMATION & ÉDUCATION
    // ─────────────────────────────────────────────────────────────────────────

    private static function trainingEval(): array
    {
        return [
            'key' => 'training_eval', 'name' => 'Évaluation de formation', 'category' => 'education',
            'description' => "Évaluation à chaud d'une session : contenu, formateur, organisation, acquis.",
            'icon' => 'clipboard', 'accent' => '#16A34A',
            'sections' => [[
                'title' => 'Bilan de la session',
                'description' => "",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Intitulé de la formation', 'required' => true],
                    ['type' => 'date', 'label' => 'Date de la session'],
                    ['type' => 'grid', 'label' => 'Notez les éléments suivants',
                        'grid_rows' => ['Clarté des objectifs', 'Qualité du contenu', 'Pédagogie du formateur', 'Supports fournis', 'Rythme', 'Organisation matérielle'],
                        'grid_columns' => ['Très insuffisant', 'Insuffisant', 'Correct', 'Bien', 'Excellent']],
                    ['type' => 'linear_scale', 'label' => 'Dans quelle mesure allez-vous pouvoir appliquer ces acquis ?',
                        'scale_min' => 1, 'scale_max' => 5, 'scale_step' => 1, 'required' => true],
                    ['type' => 'long_text', 'label' => 'Points forts de la formation'],
                    ['type' => 'long_text', 'label' => 'Points à améliorer'],
                    ['type' => 'short_text', 'label' => 'Autres besoins de formation identifiés'],
                    ['type' => 'radio', 'label' => 'Recommanderiez-vous cette formation à un collègue ?', 'required' => true,
                        'options' => ['Oui', 'Peut-être', 'Non']],
                ],
            ]],
        ];
    }

    private static function courseRegistration(): array
    {
        return [
            'key' => 'course_registration', 'name' => 'Inscription à une formation', 'category' => 'education',
            'description' => "Inscrivez des apprenants : session choisie, niveau, prise en charge et attentes.",
            'icon' => 'doc-text', 'accent' => '#2563EB',
            'sections' => [[
                'title' => 'Inscription',
                'description' => "",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Nom complet', 'required' => true],
                    ['type' => 'email', 'label' => 'E-mail', 'required' => true],
                    ['type' => 'phone', 'label' => 'Téléphone', 'phone_default_country' => 'CM'],
                    ['type' => 'short_text', 'label' => 'Entreprise / établissement'],
                    ['type' => 'dropdown', 'label' => 'Session souhaitée', 'required' => true,
                        'options' => ['Session de janvier', 'Session de mars', 'Session de juin', 'Session de septembre']],
                    ['type' => 'radio', 'label' => 'Niveau actuel sur le sujet', 'required' => true,
                        'options' => ['Débutant', 'Intermédiaire', 'Avancé']],
                    ['type' => 'radio', 'label' => 'Modalité', 'required' => true,
                        'options' => ['Présentiel', 'Distanciel', 'Hybride']],
                    ['type' => 'dropdown', 'label' => 'Financement',
                        'options' => ['Personnel', 'Employeur', 'CPF / OPCO', 'Pôle emploi', 'Autre']],
                    ['type' => 'long_text', 'label' => 'Vos attentes vis-à-vis de la formation'],
                ],
            ]],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SERVICES & SUPPORT
    // ─────────────────────────────────────────────────────────────────────────

    private static function supportTicket(): array
    {
        return [
            'key' => 'support_ticket', 'name' => "Demande d'assistance", 'category' => 'support',
            'description' => "Ouvrez un ticket support : produit concerné, priorité, description et pièces jointes.",
            'icon' => 'wrench', 'accent' => '#DC2626',
            'sections' => [[
                'title' => 'Décrivez votre problème',
                'description' => "Plus votre description est précise, plus vite nous pourrons aider.",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Nom', 'required' => true],
                    ['type' => 'email', 'label' => 'E-mail du compte', 'required' => true],
                    ['type' => 'dropdown', 'label' => 'Produit / module concerné', 'required' => true,
                        'options' => ['Compte & connexion', 'Éditeur de formulaire', 'Réponses & export', 'Intégrations', 'Facturation', 'Autre']],
                    ['type' => 'radio', 'label' => 'Priorité', 'required' => true,
                        'options' => ['Basse — question', 'Normale — gêne', 'Haute — blocage partiel', 'Critique — service indisponible']],
                    ['type' => 'long_text', 'label' => 'Description du problème', 'required' => true,
                        'help_text' => 'Que faisiez-vous ? Qu\'attendiez-vous ? Que s\'est-il passé ?'],
                    ['type' => 'long_text', 'label' => 'Étapes pour reproduire'],
                    ['type' => 'short_text', 'label' => 'Navigateur / appareil'],
                    ['type' => 'checkbox', 'label' => 'Impact',
                        'options' => ['Cela bloque mon travail', 'Cela affecte mon équipe', 'Cela affecte mes clients']],
                ],
            ]],
        ];
    }

    private static function appointment(): array
    {
        return [
            'key' => 'appointment', 'name' => 'Prise de rendez-vous', 'category' => 'support',
            'description' => "Laissez vos clients réserver un créneau : motif, date, heure et canal.",
            'icon' => 'calendar', 'accent' => '#7C3AED',
            'sections' => [[
                'title' => 'Votre rendez-vous',
                'description' => "Vous recevrez une confirmation par e-mail.",
                'questions' => [
                    ['type' => 'short_text', 'label' => 'Nom complet', 'required' => true],
                    ['type' => 'email', 'label' => 'E-mail', 'required' => true],
                    ['type' => 'phone', 'label' => 'Téléphone', 'phone_default_country' => 'CM', 'required' => true],
                    ['type' => 'dropdown', 'label' => 'Motif du rendez-vous', 'required' => true,
                        'options' => ['Premier contact', 'Démonstration', 'Suivi de dossier', 'Support', 'Autre']],
                    ['type' => 'date', 'label' => 'Date souhaitée', 'required' => true],
                    ['type' => 'time', 'label' => 'Heure souhaitée', 'required' => true],
                    ['type' => 'radio', 'label' => 'Canal', 'required' => true,
                        'options' => ['Sur place', 'Téléphone', 'Visioconférence']],
                    ['type' => 'long_text', 'label' => 'Précisez votre besoin (facultatif)'],
                ],
            ]],
        ];
    }
}
