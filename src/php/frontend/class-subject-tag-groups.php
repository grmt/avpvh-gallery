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
 * The fixed subject-tag vocabulary (see Subject_Tags), grouped by what the
 * tags describe. Tags are listed in display order, not alphabetically.
 */
final class Subject_Tag_Groups {

	// phpcs:disable SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder -- tags are in display order (Ochtend, Middag, Avond…).
	/**
	 * The fixed vocabulary: group key (stored as the tag row's category, max
	 * 20 chars) => Dutch label, whether one tag per photo, and slug => label.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.ClassConstantVisibility.MissingConstantVisibility, SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition.DisallowedMultiConstantDefinition -- no-modifier matches the convention used elsewhere; the "multi constant" error is a PHPCSUtils false positive on a multi-line array value.
	const GROUPS = array(
		'soort_foto'        => array(
			'label'  => 'Soort foto',
			'single' => true,
			'tags'   => array(
				'portret'      => 'Portret',
				'groepsfoto'   => 'Groepsfoto',
				'overzicht'    => 'Overzicht',
				'detail'       => 'Detail / close-up',
				'actie'        => 'Actie',
				'sfeer'        => 'Sfeer',
				'documentatie' => 'Documentatie (schaal, noordpijl)',
				'vondstfoto'   => 'Vondstfoto',
				'luchtfoto'    => 'Luchtfoto / drone',
			),
		),
		'tijdstip'          => array(
			'label'  => 'Tijdstip',
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
		'activiteit_graven' => array(
			'label'  => 'Activiteit – graven',
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
		'activiteit_kamp'   => array(
			'label'  => 'Activiteit – kamp',
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
				'kinderprogramma'       => 'Kinderprogramma',
				'zwemmen'               => 'Zwemmen',
				'wekstunt'              => 'Wekstunt',
				'voorwacht'             => 'Voorwacht',
				'tussen_graven_en_eten' => 'Tussen graven en eten',
			),
		),
		'excursie'          => array(
			'label'  => 'Excursie',
			'single' => false,
			'tags'   => array(
				'excursie'  => 'Excursie',
				'museum'    => 'Museum',
				'wandeling' => 'Wandeling',
				'gids'      => 'Gids',
			),
		),
		'eten'              => array(
			'label'  => 'Eten',
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
		'sporen_vondsten'   => array(
			'label'  => 'Sporen & vondsten',
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
			'single' => false,
			'tags'   => array(
				'schop'     => 'Schop',
				'troffel'   => 'Troffel',
				'kruiwagen' => 'Kruiwagen',
				'zeef'      => 'Zeef',
				'schaal'    => 'Schaal',
			),
		),
		'plek'              => array(
			'label'  => 'Plek',
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
		'overig'            => array(
			'label'  => 'Overig',
			'single' => false,
			'tags'   => array(
				'kinderen' => 'Kinderen',
				'tjoepke'  => 'Tjoepke',
			),
		),
	);
	// phpcs:enable SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys.IncorrectKeyOrder
}
