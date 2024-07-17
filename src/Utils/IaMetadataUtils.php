<?php

namespace Wikisource\IaUpload\Utils;

/**
 * General utilities for handling IA metadata
 *
 * @file
 * @ingroup IaUpload
 *
 * @license GPL-2.0-or-later
 */
class IaMetadataUtils {

	/**
	 * Sanitise a name from an IA creator metadata record
	 *
	 * This strips common junk out of the name, for example:
	 * * Bloggs, J. (Joe) -> Bloggs, Joe
	 * * Bloogs, Joe, 1901?-1945
	 * * Bloggs, Joe, b. 1901.
	 *
	 * @param string $author string directly from IA metadata
	 * @return string sanitised string
	 */
	public static function sanitiseName( string $author ): string {
		$author = preg_replace( '/\s*\[from old catalog\]\s*/i', '', $author );

		// strip out dates
		$author = preg_replace( '/(?:, )?\(?(ca.\s*)?\d+\??-(\d+\??)?\)?,?/', '', $author );
		// birth/death dates
		$author = preg_replace( '/(?:, )?\(?\b(b|d)\. (\d+\??)\)?,?/', '', $author );

		// strip out initial expansions like A. B. (Arthur Bradley)
		// at the IA, these should be after a comma
		$author = preg_replace( '/(?<=,\s).*?\((\w{2,}.*?)\)/', '$1', $author );

		// remove ", author" suffixes
		$author = preg_replace( '/, (author|editor|ed\.?|illust?(\.|rator)?|trans(|\.|lator)?)\b/i',
			'', $author );
		return $author;
	}

	/**
	 * Determine if a name found in an IA creator field looks human
	 *
	 * Certain words indicate that this is a non-human author
	 * (usually a library, GLAM institution or an organisational author
	 *
	 * @param string $name the candidate name
	 * @return bool true if the name isn't obviously non-human
	 */
	public static function nameLooksHuman( $name ) {
		// sometimes a work can be "by" an institutions, but even then we don't
		// need it in the title
		//
		// note: watch out for ranks like "secretary" and "general", which can
		// be appended to real name
		$bogus = [
			// misc junk from IA metadata
			'/unknown/',

			// organisations
			'/university/',
			'/college/',
			'/school/',
			'/librar(ies|y)/',
			'/institut(e|ion)/',
			'/museum/',
			'/collection/',
			'/cent(re|er)/',
			'/press/',
			'/company/',
			'/agenc(y|ies)/',
			'/\b(inc|co|ltd|ag|gmbh|corp|dept)\.?\b/',
			'/assoc(\.?\b|iation)/',
			'/soci(al|ety|eties|edad|ete|été)/',
			'/corporation/',
			'/committee/',
			'/\bboards?\b/',
			'/department/',
			'/division/',
			'/council/',
			'/na[ct]ional/',
			'/\bpublic\b/',
			'/office/',
			'/bureau/',
			'/exhibitions?/',
			'/\bbody\b/',
			'/\bunions?\b/',
			'/research/',
			'/service/',
			'/program(me)?/',
			'/project/',
			'/counsel/',
			'/organi[sz]ation/',
			'/charit(y|ies)/',
			'/commission/',
			'/comisi[oó]n/',
			'/affairs/',
			'/district/',
			'/senate/',
			'/parliament/',
			// "house" can be a name
			'/house of/',
			'/congress/',
			// "church" can be a name
			'/church (in|of)/',
			'/\bregion/',
			'/volume/',
			'/state/',
			// also confederate
			'/federa(l|tion|te)/',
			'/administration/',
			'/ministr(y|ies)/',
			'/official/',

			// publications/events
			'/conference/',
			'/transactions/',
			'/proceedings/',
			'/server/',
			'/publications/',
			'/journal/',
			'/periodical/',
			'/serial/',
			'/newspaper/',

			// places
			// note: some places like "England/English" can actually be valid names
			// so we hope they occur with other banned words
			'/american/',
			'/u\.\s?s\.\s?a\./',
			'/united/',
			'/british/',
			'/canad(a|ian)/',
			'/japan/',
			'/chin(a|ese)/',
			'/ital(y|ia)/',
			'/fran[çc]ais/',
			// NESW by themselves can be names
			'/(south|north)(east|west)/',

			// subjects
			'/auction/',
			'/biblical/',
			'/broadcast/',
			'/education(|al)/',
			'/histor(y|ia)/',
			'/industr(y|ial|ies)/',
			'/insurance/',
			'/learning/',
			'/patent/',
			'/record/',
			'/science/',
			'/securit(y|ies)/',
			'/socialis(t|m)/',
			'/transport/',
		];

		foreach ( $bogus as $b ) {
			if ( preg_match( $b, strtolower( $name ) ) === 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extract the surname from an author
	 *
	 * * Bloggs, Joe -> Bloggs
	 * * Joe Bloggs -> Bloggs
	 *
	 * This is a best-effort attempt and is not guaranteed for all names in
	 * the IA's highly variable metadata.
	 *
	 * @param string $author author string (ideally sanitised first)
	 * @return string|null what appears to be the surname
	 */
	public static function extractAuthorSurname( string $author ): ?string {
		// first heuristic: if there's a comma, the first bit is the surname
		if ( strpos( $author, ',' ) !== false ) {
			return preg_replace( '/,.*$/', '', $author );
		}

		// otherwise, split the string on spaces and return the last one
		// that doesn't contain a number
		$words = array_reverse( explode( ' ', $author ) );

		foreach ( $words as $word ) {
			if ( preg_match( '/\d/', $word ) === 1 ) {
				continue;
			}
			return $word;
		}

		// didn't find anything useful at all
		return null;
	}

	/**
	 * Get a list of author surnames that are (hopefully) suitable for use in
	 * the file name,
	 *
	 * Names that are ill-formed or look non-human are dropped.
	 *
	 * @param array $iaAuthors array of author strings as found in the IA metadata
	 * @return array array of surnames for names that look human
	 */
	public static function extractAuthorSurnamesForTitle( array $iaAuthors ): array {
		if ( !$iaAuthors ) {
			return '';
		}

		$names = [];

		foreach ( $iaAuthors as $author ) {
			$name = self::sanitiseName( $author );

			// filter out non-humans
			if ( self::nameLooksHuman( $name ) ) {
				$name = self::extractAuthorSurname( $name );
				if ( $name ) {
					$names[] = $name;
				}
			}
		}
		return $names;
	}

	/**
	 * Construct a best-effort title for Commons from the IA metadata
	 *
	 * Not strong guarantees are made about the suitability of the name, since
	 * the IA metadata is of extremely variable quality.
	 *
	 * @param array $iaData array of IA data, including at least 'metadata'
	 * @param string $sep the field separator
	 * @return string|null a filename (without extension) or null if none can be generated
	 */
	public static function getCommonsNameFromIaData( array $iaData, string $sep ): ?string {
		$meta = $iaData['metadata'];
		// var_dump( $meta );

		if ( !$meta ) {
			return null;
		}

		$name = $meta['title'][0];

		// not much we can do if the title doesn't even exist
		if ( !$name ) {
			return null;
		}

		// subtitles are sometimes separated by ; or :, these are easy to trim
		$name = preg_replace( '/\s*[:;].*$/', '', $name );

		// keep the name reasonable
		$name = substr( $name, 0, 200 );

		$authorNames = self::extractAuthorSurnamesForTitle( $meta['creator'] );

		if ( $authorNames ) {
			$name .= $sep . implode( $authorNames, ', ' );
		}

		if ( $meta['date'][0] ) {
			$year = preg_replace( '/-.*$/', '', $meta['date'][0] );

			if ( $year ) {
				$name .= $sep . $year;
			}
		}

		return $name;
	}
}
