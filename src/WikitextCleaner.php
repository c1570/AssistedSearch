<?php

namespace MediaWiki\Extension\AssistedSearch;

use MediaWiki\Parser\Sanitizer;

class WikitextCleaner {

	public static function cleanText( string $text, ?int $maxLength = 2000 ): string {
		$text = Sanitizer::removeHTMLcomments( $text );

		$text = preg_replace( '/\[\[(?:Category|File|Image|Media|Datei|Bild|Kategorie):[^\]]*\]\]/i', '', $text );

		$text = preg_replace( '/^={1,6}\s*.+?\s*={1,6}\s*$/m', '', $text );

		$text = self::removeTemplates( $text );

		$text = preg_replace( '/\[\[([^\]|]+)\|([^\]]+)\]\]/', '$2', $text );
		$text = preg_replace( '/\[\[([^\]]+)\]\]/', '$1', $text );

		$text = preg_replace( '/\[https?:\/\/[^\s\]]+ ([^\]]+)\]/i', '$1', $text );
		$text = preg_replace( '/\[https?:\/\/[^\s\]]+\]/i', '', $text );
		$text = preg_replace( '/https?:\/\/[^\s<\]]+/', '', $text );

		$text = preg_replace( "/'{2,}/", '', $text );

		$text = Sanitizer::stripAllTags( $text );

		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{2,}/', "\n", $text );
		$text = trim( $text );

		$limit = $maxLength ?? 2000;
		if ( strlen( $text ) > $limit ) {
			$text = substr( $text, 0, $limit ) . '...';
		}

		return $text;
	}

	private static function removeTemplates( string $text ): string {
		$result = '';
		$i = 0;
		$len = strlen( $text );
		while ( $i < $len ) {
			if ( $i < $len - 1 && $text[$i] === '{' && $text[$i + 1] === '{' ) {
				$depth = 0;
				$j = $i;
				while ( $j < $len - 1 ) {
					if ( $text[$j] === '{' && $text[$j + 1] === '{' ) {
						$depth++;
						$j += 2;
					} elseif ( $text[$j] === '}' && $text[$j + 1] === '}' ) {
						$depth--;
						$j += 2;
						if ( $depth === 0 ) {
							break;
						}
					} else {
						$j++;
					}
				}
				$i = $j;
			} else {
				$result .= $text[$i];
				$i++;
			}
		}
		return $result;
	}
}
