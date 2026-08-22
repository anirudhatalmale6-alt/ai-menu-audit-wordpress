<?php
/**
 * Pulls menu text out of an uploaded file.
 *
 * Plain text, CSV and Word documents are read directly. PDFs are decoded where
 * the text layer allows it. Photos and scanned PDFs fall back to the vision
 * model, which reads the menu off the image itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_Extract {

	const MAX_BYTES = 8388608; // 8 MB

	public static function allowed_types() {
		return array(
			'txt'  => 'text/plain',
			'md'   => 'text/plain',
			'csv'  => 'text/csv',
			'rtf'  => 'application/rtf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'gif'  => 'image/gif',
		);
	}

	/**
	 * @return array|WP_Error  array( 'text' => string, 'image' => array|null, 'filename' => string )
	 */
	public static function from_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'ma_upload', 'The file could not be read.' );
		}

		if ( $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'ma_upload_size', 'That file is larger than 8 MB. Please upload a smaller file or paste the menu instead.' );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_types() );

		if ( empty( $check['ext'] ) ) {
			return new WP_Error( 'ma_upload_type', 'That file type is not supported. Please upload a PDF, Word document, text file or a photo of your menu.' );
		}

		$ext      = strtolower( $check['ext'] );
		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$result   = array(
			'text'     => '',
			'image'    => null,
			'filename' => sanitize_file_name( $file['name'] ),
		);

		switch ( $ext ) {
			case 'jpg':
			case 'jpeg':
			case 'png':
			case 'webp':
			case 'gif':
				$result['image'] = array(
					'media_type' => $check['type'],
					'data'       => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				);
				break;

			case 'docx':
				$result['text'] = self::from_docx( $file['tmp_name'] );
				break;

			case 'pdf':
				$result['text'] = self::from_pdf( $contents );

				// No usable text layer — it is almost certainly a scan or an
				// export, so hand the first page to the vision model instead.
				if ( strlen( trim( $result['text'] ) ) < 40 ) {
					$result['text']  = '';
					$result['image'] = array(
						'media_type' => 'application/pdf',
						'data'       => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					);
				}
				break;

			case 'rtf':
				$result['text'] = self::from_rtf( $contents );
				break;

			case 'doc':
				$result['text'] = self::from_doc( $contents );
				break;

			default:
				$result['text'] = self::clean( $contents );
		}

		if ( '' === trim( $result['text'] ) && ! $result['image'] ) {
			return new WP_Error( 'ma_upload_empty', 'No menu text could be read from that file. Please paste the menu into the box instead.' );
		}

		return $result;
	}

	/**
	 * .docx is a zip — the body lives in word/document.xml.
	 */
	private static function from_docx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( ! $xml ) {
			return '';
		}

		// Paragraph and line breaks become newlines so sections survive.
		$xml = str_replace( array( '</w:p>', '<w:br/>', '<w:br />' ), "\n", $xml );
		$xml = str_replace( '</w:tr>', "\n", $xml );
		$xml = str_replace( '</w:tc>', "\t", $xml );

		return self::clean( wp_strip_all_tags( $xml ) );
	}

	/**
	 * Best-effort text layer extraction from an uncompressed or flate-compressed PDF.
	 */
	private static function from_pdf( $contents ) {
		$text = '';

		if ( preg_match_all( '/stream\r?\n?(.*?)endstream/s', $contents, $matches ) ) {
			foreach ( $matches[1] as $stream ) {
				$decoded = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

				if ( false === $decoded ) {
					$decoded = @gzinflate( substr( $stream, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}

				if ( false === $decoded ) {
					$decoded = $stream;
				}

				$text .= self::pdf_strings( $decoded ) . "\n";
			}
		}

		return self::clean( $text );
	}

	/**
	 * Pull the literal strings out of a PDF content stream's text operators.
	 */
	private static function pdf_strings( $stream ) {
		if ( false === strpos( $stream, 'Tj' ) && false === strpos( $stream, 'TJ' ) ) {
			return '';
		}

		$out = '';

		// Each text-showing operator becomes a line; ( ... ) holds the glyphs.
		if ( preg_match_all( '/\(((?:\\\\.|[^\\\\()])*)\)\s*(Tj|TJ|\')/', $stream, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$out .= stripcslashes( $match[1] ) . ' ';
			}
		}

		// TD/Td/T* move the cursor to a new line.
		$out = preg_replace( '/\s{3,}/', "\n", $out );

		return $out;
	}

	private static function from_rtf( $contents ) {
		$text = preg_replace( '/\\\\par[d]?/', "\n", $contents );
		$text = preg_replace( '/\\\\[a-z]{1,32}(-?\d{1,10})?[ ]?/', '', $text );
		$text = str_replace( array( '{', '}' ), '', $text );

		return self::clean( $text );
	}

	/**
	 * Legacy .doc is a binary format; salvage the readable runs.
	 */
	private static function from_doc( $contents ) {
		$text = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]+/', "\n", $contents );

		return self::clean( $text );
	}

	private static function clean( $text ) {
		$text = preg_replace( '/\r\n?/', "\n", (string) $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}
}
