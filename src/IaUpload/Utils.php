<?php

namespace IaUpload;

use Guzzle\Common\Collection;
use Guzzle\Http\Client;

/**
 * Client for Commons API
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class Utils {

	/**
	 * Normalize a language code
	 * Very hacky
	 *
	 * @param string $language
	 * @return string
	 */
	public function normalizeLanguageCode( $language ) {
		global $languageCategories;

		if(strlen($language) == 2) {
			return $language;
		} elseif(strlen($language) == 3) {
			return $language[0] . $language[1];
		} else {
			foreach($languageCategories as $id => $name) {
				if( $language == $name ) {
					return $id;
				}
			}
			return $language;
		}
	}
}