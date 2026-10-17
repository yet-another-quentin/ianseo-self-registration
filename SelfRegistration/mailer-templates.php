<?php
/*
 * Email templates of the self-registration module, discovered by the Mailer module (Modules/Custom/Mailer) when it
 * is installed. Format: see Mailer/mailer-templates.php. The organiser can customise them per competition.
 */
$vars = array(
	'archer.firstName' => array('fr' => 'Prénom de l\'archer', 'en' => 'Archer\'s first name'),
	'archer.lastName' => array('fr' => 'Nom de l\'archer', 'en' => 'Archer\'s last name'),
	'archer.license' => array('fr' => 'Licence', 'en' => 'License'),
	'archer.club' => array('fr' => 'Club', 'en' => 'Club'),
	'category' => array('fr' => 'Arme et catégorie', 'en' => 'Bow type and category'),
	'face' => array('fr' => 'Blason', 'en' => 'Target face'),
	'sessions' => array('fr' => 'Départs (un par ligne)', 'en' => 'Sessions (one per line)'),
	'waitlist' => array('fr' => 'Départs en liste d\'attente (un par ligne)', 'en' => 'Sessions on the waiting list (one per line)'),
	'reference' => array('fr' => 'Référence de la demande', 'en' => 'Request reference'),
	'comment' => array('fr' => 'Message de l\'archer', 'en' => 'Archer\'s message'),
	'formUrl' => array('fr' => 'Lien du formulaire d\'inscription', 'en' => 'Registration form link'),
);
$sample = array(
	'archer.firstName' => 'Jean', 'archer.lastName' => 'DUPONT', 'archer.license' => '123456A', 'archer.club' => 'ARC CESTAS',
	'category' => array('fr' => 'Arc Classique – Senior 2 Homme', 'en' => 'Recurve – Senior 2 Men'),
	'face' => array('fr' => 'Trispot 40 cm', 'en' => 'Triple face 40 cm'),
	'sessions' => array(
		'fr' => "Départ 1 – Samedi matin – samedi 12 décembre 2026 à 10:00\nDépart 3 – Dimanche matin – dimanche 13 décembre 2026 à 10:00",
		'en' => "Session 1 – Saturday morning – Saturday 12 December 2026 at 10:00\nSession 3 – Sunday morning – Sunday 13 December 2026 at 10:00",
	),
	'waitlist' => array(
		'fr' => 'Départ 2 – Samedi après-midi – samedi 12 décembre 2026 à 14:00',
		'en' => 'Session 2 – Saturday afternoon – Saturday 12 December 2026 at 14:00',
	),
	'reference' => '5350D81E',
	'comment' => array('fr' => 'Je viens avec mon fils, possible d\'être sur la même cible ?', 'en' => 'I come with my son, could we be on the same target?'),
	'formUrl' => 'https://ianseo.example.org/Modules/Custom/SelfRegistration/register.php?c=TEST26',
);

return array(
	'selfreg.received' => array(
		'name' => array('fr' => 'Demande d\'inscription reçue', 'en' => 'Registration request received'),
		'description' => array(
			'fr' => 'Accusé de réception envoyé à l\'archer dès qu\'il envoie le formulaire d\'inscription en ligne.',
			'en' => 'Acknowledgement sent to the archer as soon as the online registration form is sent.',
		),
		'to' => 'archer',
		'vars' => $vars,
		'sample' => $sample,
		'subject' => array(
			'fr' => 'Demande d\'inscription reçue – {competition.name}',
			'en' => 'Registration request received – {competition.name}',
		),
		'body' => array(
			'fr' => "Bonjour {archer.firstName},\n\n"
				. "Nous avons bien reçu votre demande d'inscription à **{competition.name}** ({competition.dates}, {competition.place}).\n\n"
				. "- Référence : {reference}\n- Licence : {archer.license}\n- Arme et catégorie : {category}\n{?face}- Blason : {face}\n{/face}\n"
				. "{?sessions}**Départ(s) demandé(s)**\n{sessions}\n\n{/sessions}"
				. "{?waitlist}**Liste d'attente**\n{waitlist}\n\nLes départs en liste d'attente sont complets : si une place se libère, l'organisateur pourra vous inscrire et vous recevrez un email.\n\n{/waitlist}"
				. "{?sessions}Votre inscription sera définitive après validation par l'organisateur : vous recevrez alors un nouvel email.{/sessions}{?comment}\n\n"
				. "Votre message : {comment}{/comment}\n\n"
				. "{?competition.contactEmail}Pour toute question, répondez simplement à cet email.\n\n{/competition.contactEmail}"
				. "Sportivement,\n{competition.contactName}",
			'en' => "Hello {archer.firstName},\n\n"
				. "We have received your registration request for **{competition.name}** ({competition.dates}, {competition.place}).\n\n"
				. "- Reference: {reference}\n- License: {archer.license}\n- Bow type and category: {category}\n{?face}- Target face: {face}\n{/face}\n"
				. "{?sessions}**Requested session(s)**\n{sessions}\n\n{/sessions}"
				. "{?waitlist}**Waiting list**\n{waitlist}\n\nThe sessions on the waiting list are full: if a place is freed, the organiser may enter you and you will receive an email.\n\n{/waitlist}"
				. "{?sessions}Your registration becomes final once validated by the organiser: you will then receive another email.{/sessions}{?comment}\n\n"
				. "Your message: {comment}{/comment}\n\n"
				. "{?competition.contactEmail}For any question, simply reply to this email.\n\n{/competition.contactEmail}"
				. "Best regards,\n{competition.contactName}",
		),
	),

	'selfreg.validated' => array(
		'name' => array('fr' => 'Inscription validée', 'en' => 'Registration validated'),
		'description' => array(
			'fr' => 'Envoyé à l\'archer quand l\'organisateur a validé sa demande et que l\'archer apparaît dans les participants.',
			'en' => 'Sent to the archer once the organiser validated the request and the archer appears in the entries.',
		),
		'to' => 'archer',
		'vars' => $vars,
		'sample' => $sample,
		'subject' => array(
			'fr' => 'Inscription validée – {competition.name}',
			'en' => 'Registration confirmed – {competition.name}',
		),
		'body' => array(
			'fr' => "Bonjour {archer.firstName},\n\n"
				. "Bonne nouvelle : votre inscription à **{competition.name}** est validée.\n\n"
				. "- Arme et catégorie : {category}\n{?face}- Blason : {face}\n{/face}- Référence : {reference}\n\n"
				. "**Départ(s)**\n{sessions}\n\n"
				. "{?waitlist}Vous restez en liste d'attente pour :\n{waitlist}\n\n{/waitlist}"
				. "Lieu : {competition.place}\n\n"
				. "Le placement sur les cibles vous sera communiqué par l'organisateur.{?competition.contactEmail} Pour toute question : {competition.contactEmail}.{/competition.contactEmail}\n\n"
				. "À bientôt sur le pas de tir,\n{competition.contactName}",
			'en' => "Hello {archer.firstName},\n\n"
				. "Good news: your registration for **{competition.name}** is confirmed.\n\n"
				. "- Bow type and category: {category}\n{?face}- Target face: {face}\n{/face}- Reference: {reference}\n\n"
				. "**Session(s)**\n{sessions}\n\n"
				. "{?waitlist}You stay on the waiting list for:\n{waitlist}\n\n{/waitlist}"
				. "Venue: {competition.place}\n\n"
				. "The organiser will let you know your target assignment.{?competition.contactEmail} For any question: {competition.contactEmail}.{/competition.contactEmail}\n\n"
				. "See you on the shooting line,\n{competition.contactName}",
		),
	),

	'selfreg.promoted' => array(
		'name' => array('fr' => 'Place libérée (liste d\'attente)', 'en' => 'Place freed (waiting list)'),
		'description' => array(
			'fr' => 'Envoyé à l\'archer inscrit depuis la liste d\'attente, une fois qu\'il apparaît dans les participants (remplace « Inscription validée »).',
			'en' => 'Sent to an archer entered from the waiting list, once the archer appears in the entries (instead of "Registration validated").',
		),
		'to' => 'archer',
		'vars' => $vars,
		'sample' => $sample,
		'subject' => array(
			'fr' => 'Une place s\'est libérée : inscription validée – {competition.name}',
			'en' => 'A place was freed: registration confirmed – {competition.name}',
		),
		'body' => array(
			'fr' => "Bonjour {archer.firstName},\n\n"
				. "Une place s'est libérée : vous étiez en liste d'attente et votre inscription à **{competition.name}** est maintenant validée.\n\n"
				. "- Arme et catégorie : {category}\n{?face}- Blason : {face}\n{/face}- Référence : {reference}\n\n"
				. "**Départ(s)**\n{sessions}\n\n"
				. "{?waitlist}Vous restez en liste d'attente pour :\n{waitlist}\n\n{/waitlist}"
				. "Lieu : {competition.place}\n\n"
				. "Si vous ne pouvez plus venir, merci de prévenir l'organisateur au plus vite pour que la place profite à un autre archer.{?competition.contactEmail} Contact : {competition.contactEmail}.{/competition.contactEmail}\n\n"
				. "À bientôt sur le pas de tir,\n{competition.contactName}",
			'en' => "Hello {archer.firstName},\n\n"
				. "A place was freed: you were on the waiting list and your registration for **{competition.name}** is now confirmed.\n\n"
				. "- Bow type and category: {category}\n{?face}- Target face: {face}\n{/face}- Reference: {reference}\n\n"
				. "**Session(s)**\n{sessions}\n\n"
				. "{?waitlist}You stay on the waiting list for:\n{waitlist}\n\n{/waitlist}"
				. "Venue: {competition.place}\n\n"
				. "If you can no longer come, please tell the organiser as soon as possible so that another archer gets the place.{?competition.contactEmail} Contact: {competition.contactEmail}.{/competition.contactEmail}\n\n"
				. "See you on the shooting line,\n{competition.contactName}",
		),
	),

	'selfreg.refused' => array(
		'name' => array('fr' => 'Inscription refusée', 'en' => 'Registration refused'),
		'description' => array(
			'fr' => 'Envoyé à l\'archer quand l\'organisateur refuse sa demande (pour un ou plusieurs départs).',
			'en' => 'Sent to the archer when the organiser refuses the request (for one or several sessions).',
		),
		'to' => 'archer',
		'vars' => $vars,
		'sample' => $sample,
		'subject' => array(
			'fr' => 'Demande d\'inscription non retenue – {competition.name}',
			'en' => 'Registration request not accepted – {competition.name}',
		),
		'body' => array(
			'fr' => "Bonjour {archer.firstName},\n\n"
				. "Nous sommes désolés : votre demande d'inscription à **{competition.name}** ({competition.dates}) n'a pas pu être retenue pour le(s) départ(s) suivant(s) :\n{sessions}\n\n"
				. "Référence : {reference}\n\n"
				. "{?waitlist}Vous restez en liste d'attente pour :\n{waitlist}\n\n{/waitlist}"
				. "{?competition.contactEmail}Pour en savoir plus, répondez à cet email ou écrivez à {competition.contactEmail}.\n\n{/competition.contactEmail}"
				. "Sportivement,\n{competition.contactName}",
			'en' => "Hello {archer.firstName},\n\n"
				. "We are sorry: your registration request for **{competition.name}** ({competition.dates}) could not be accepted for the following session(s):\n{sessions}\n\n"
				. "Reference: {reference}\n\n"
				. "{?waitlist}You stay on the waiting list for:\n{waitlist}\n\n{/waitlist}"
				. "{?competition.contactEmail}To know more, reply to this email or write to {competition.contactEmail}.\n\n{/competition.contactEmail}"
				. "Best regards,\n{competition.contactName}",
		),
	),

	'selfreg.new' => array(
		'name' => array('fr' => 'Nouvelle demande (organisateur)', 'en' => 'New request (organiser)'),
		'description' => array(
			'fr' => 'Envoyé au contact de la compétition à chaque nouvelle demande d\'inscription. Répondre à cet email écrit à l\'archer.',
			'en' => 'Sent to the competition contact for each new registration request. Replying to it writes to the archer.',
		),
		'to' => 'organiser',
		'vars' => $vars + array(
			'email' => array('fr' => 'Email de l\'archer', 'en' => 'Archer\'s email'),
			'adminUrl' => array('fr' => 'Lien de la page des demandes', 'en' => 'Requests page link'),
		),
		'sample' => $sample + array(
			'email' => 'jean.dupont@example.org',
			'adminUrl' => 'https://ianseo.example.org/Modules/Custom/SelfRegistration/index.php',
		),
		'subject' => array(
			'fr' => 'Nouvelle demande d\'inscription – {archer.lastName} {archer.firstName} ({category})',
			'en' => 'New registration request – {archer.lastName} {archer.firstName} ({category})',
		),
		'body' => array(
			'fr' => "Une nouvelle demande d'inscription est arrivée pour **{competition.name}**.\n\n"
				. "- Archer : {archer.lastName} {archer.firstName}\n- Licence : {archer.license}\n- Club : {archer.club}\n- Arme et catégorie : {category}\n{?face}- Blason : {face}\n{/face}- Email : {email}\n- Référence : {reference}\n\n"
				. "{?sessions}**Départ(s) demandé(s)**\n{sessions}\n\n{/sessions}{?waitlist}**Liste d'attente**\n{waitlist}\n\n{/waitlist}{?comment}**Message de l'archer**\n{comment}\n\n{/comment}"
				. "{?adminUrl}Pour la valider ou la refuser (compétition ouverte dans IANSEO) : {adminUrl}{/adminUrl}",
			'en' => "A new registration request arrived for **{competition.name}**.\n\n"
				. "- Archer: {archer.lastName} {archer.firstName}\n- License: {archer.license}\n- Club: {archer.club}\n- Bow type and category: {category}\n{?face}- Target face: {face}\n{/face}- Email: {email}\n- Reference: {reference}\n\n"
				. "{?sessions}**Requested session(s)**\n{sessions}\n\n{/sessions}{?waitlist}**Waiting list**\n{waitlist}\n\n{/waitlist}{?comment}**Archer's message**\n{comment}\n\n{/comment}"
				. "{?adminUrl}To validate or refuse it (with the competition open in IANSEO): {adminUrl}{/adminUrl}",
		),
	),
);
