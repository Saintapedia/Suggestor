<?php

use MediaWiki\Extension\SaintapediaSuggest\Cargo\CargoFieldRegistry;
use MediaWiki\Extension\SaintapediaSuggest\SuggestionStore;
use MediaWiki\MediaWikiServices;

return [
	'SaintapediaSuggest.SuggestionStore' => static function ( MediaWikiServices $services ): SuggestionStore {
		return new SuggestionStore(
			$services->getDBLoadBalancer()
		);
	},

	'SaintapediaSuggest.CargoFieldRegistry' => static function ( MediaWikiServices $services ): CargoFieldRegistry {
		return new CargoFieldRegistry(
			$services->getMainConfig(),
			$services->getDBLoadBalancer()
		);
	},
];
