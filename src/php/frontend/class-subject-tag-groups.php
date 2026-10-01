<?php
/**
 * Contains the Subject_Tag_Groups class.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Die, die, die!' );
}

/**
 * The subject-tag vocabulary as it was before it became editable: only used
 * once, to seed the tree in the database (Subject_Tag_Tree::seed_from_code())
 * and to convert tags stored by these slugs. Edit tags on the admin "Tags"
 * page, not here.
 */
final class Subject_Tag_Groups {

	// phpcs:disable SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder -- tags are in display order (Ochtend, Middag, Avond…).
	/**
	 * The fixed vocabulary: group key (stored as the tag row's category, max
	 * 20 chars) => Dutch label, the path of sections it's shown under (Wie,
	 * Wat › Graven, Waar, Wanneer, Hoe — the order here is the display
	 * order), whether one tag per photo, and slug => label. Only the
	 * display depends on the path: tags are stored by group key and slug.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility, SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- no-modifier matches the convention used elsewhere; the "multi constant" error is a PHPCSUtils false positive on a multi-line array value.
	const GROUPS = array(
		'overig'            => array(
			'label'  => 'Overig',
			'path'   => array( 'Wie' ),
			'single' => false,
			'tags'   => array(
				'kinderen' => 'Kinderen',
			),
		),
		'activiteit_graven' => array(
			'label'  => 'Activiteit',
			'path'   => array( 'Wat', 'Graven' ),
			'single' => false,
			'tags'   => array(
				'schaven'               => 'Schaven',
				'troffelen'             => 'Troffelen',
				'zeven'                 => 'Zeven',
				'meten'                 => 'Meten',
				'intekenen'             => 'Intekenen',
				'archeoloog_maakt_foto' => 'Fotograferen',
				'hand_met_vondst'       => 'Hand met vondst',
				'opruimen'              => 'Opruimen',
				'rondleiding'           => 'Rondleiding',
				'pauze'                 => 'Pauze',
			),
		),
		'sporen_vondsten'   => array(
			'label'  => 'Sporen & vondsten',
			'path'   => array( 'Wat', 'Graven' ),
			'single' => false,
			'tags'   => array(
				'vondst'        => 'Vondst',
				'scherf'        => 'Scherf',
				'potje'         => 'Potje',
				'kan'           => 'Kan',
				'urn'           => 'Urn',
				'wrijfschaal'   => 'Wrijfschaal',
				'munt'          => 'Munt',
				'fibula'        => 'Fibula',
				'metaal'        => 'Metaal',
				'belletje'      => 'Belletje',
				'botten'        => 'Botten',
				'skelet'        => 'Skelet',
				'crematiegraf'  => 'Crematiegraf',
				'afvalput'      => 'Afvalput',
				'haard'         => 'Haard',
				'paalgat'       => 'Paalgat',
				'muur'          => 'Muur',
				'vlak'          => 'Vlak',
				'profiel'       => 'Profiel',
				'coupe'         => 'Coupe',
				'kwadrant'      => 'Kwadrant',
				'reconstructie' => 'Reconstructie',
			),
		),
		'gereedschap'       => array(
			'label'  => 'Gereedschap',
			'path'   => array( 'Wat', 'Graven' ),
			'single' => false,
			'tags'   => array(
				'schop'     => 'Schop',
				'troffel'   => 'Troffel',
				'kruiwagen' => 'Kruiwagen',
				'zeef'      => 'Zeef',
				'schaal'    => 'Schaal',
			),
		),
		'activiteit_kamp'   => array(
			'label'  => 'Activiteit',
			'path'   => array( 'Wat', 'Kamp' ),
			'single' => false,
			'tags'   => array(
				'afbreken'              => 'Opbouwen / afbreken',
				'koken'                 => 'Koken',
				'feest'                 => 'Feest',
				'dans'                  => 'Dans',
				'muziek'                => 'Muziek',
				'lied'                  => 'Lied',
				'spel'                  => 'Spel',
				'kampvuur'              => 'Kampvuur',
				'speech'                => 'Speech',
				'gerrit'                => 'Gerrit',
				'tjoepke'               => 'Tjoepke',
				'kinderprogramma'       => 'Kinderprogramma',
				'zwemmen'               => 'Zwemmen',
				'wekstunt'              => 'Wekstunt',
				'voorwacht'             => 'Voorwacht',
				'tussen_graven_en_eten' => 'Tussen graven en eten',
			),
		),
		'eten'              => array(
			'label'  => 'Eten',
			'path'   => array( 'Wat', 'Kamp' ),
			'single' => false,
			'tags'   => array(
				'ontbijt'     => 'Ontbijt',
				'lunch'       => 'Lunch',
				'avondeten'   => 'Avondeten',
				'gerecht'     => 'Gerecht',
				'varkensmaal' => 'Varkensmaal',
				'varken'      => 'Varken',
				'drank'       => 'Drank',
				'aperitiefje' => 'Aperitiefje',
			),
		),
		'excursie'          => array(
			'label'  => 'Excursie',
			'path'   => array( 'Wat' ),
			'single' => false,
			'tags'   => array(
				'excursie'  => 'Excursie',
				'museum'    => 'Museum',
				'wandeling' => 'Wandeling',
				'gids'      => 'Gids',
			),
		),
		'soort_plek'        => array(
			'label'  => 'Soort plek',
			'path'   => array( 'Waar', 'Plek' ),
			'single' => true,
			'tags'   => array(
				'plek_kamp'      => 'Kamp',
				'plek_opgraving' => 'Opgraving',
				'plek_excursie'  => 'Excursie',
				'plek_reunie'    => 'Reünie',
			),
		),
		// The places at camp. Key stays "plek" (it's stored with each tag).
		'plek'              => array(
			'label'  => 'Kamp',
			'path'   => array( 'Waar', 'Plek' ),
			'single' => false,
			'tags'   => array(
				'grote_tent' => 'Grote tent',
				'keuken'     => 'Keuken',
				'wasplaats'  => 'Wasplaats',
				'wc'         => 'Wc',
				'container'  => 'Container',
				'zwembad'    => 'Zwembad',
				'trampoline' => 'Trampoline',
			),
		),
		'tijdstip'          => array(
			'label'  => 'Tijdstip',
			'path'   => array( 'Wanneer' ),
			'single' => true,
			'tags'   => array(
				'ochtend' => 'Ochtend',
				'middag'  => 'Middag',
				'avond'   => 'Avond',
				'nacht'   => 'Nacht',
			),
		),
		'weer'              => array(
			'label'  => 'Weer',
			'path'   => array( 'Wanneer' ),
			'single' => false,
			'tags'   => array(
				'zon'     => 'Zon',
				'bewolkt' => 'Bewolkt',
				'regen'   => 'Regen',
				'storm'   => 'Storm',
				'hitte'   => 'Hitte',
				'modder'  => 'Modder',
				'schaduw' => 'Schaduw',
			),
		),
		'soort_foto'        => array(
			'label'  => 'Soort',
			'path'   => array( 'Hoe' ),
			'single' => true,
			'tags'   => array(
				'portret'    => 'Portret',
				'groepsfoto' => 'Groepsfoto',
				'overzicht'  => 'Overzicht',
				'detail'     => 'Detail / close-up',
				'tafereel'   => 'Tafereel',
				'telelens'   => 'Telelens',
				'luchtfoto'  => 'Luchtfoto / drone',
			),
		),
		'karakter'          => array(
			'label'  => 'Karakter',
			'path'   => array( 'Hoe' ),
			'single' => false,
			'tags'   => array(
				'actie' => 'Actie',
				'sfeer' => 'Sfeer',
			),
		),
		'doel'              => array(
			'label'  => 'Doel',
			'path'   => array( 'Hoe' ),
			'single' => false,
			'tags'   => array(
				'documentatie' => 'Documentatie (schaal, noordpijl)',
				'vondstfoto'   => 'Vondstfoto',
			),
		),
	);
	// phpcs:enable SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
}
