<?php

namespace Wikisource\IaUpload\DjvuMaker;

use Exception;
use pastuhov\Command\Command;
use ZipArchive;

/**
 * This class creates a DjVu file from the `*_djvu.xml` and `*_jp2.zip` files in an Internet
 * Archive item.
 * @package IaUpload
 */
class Jp2DjvuMaker extends DjvuMaker {

	/** @var int The number of pages in the DjVu, set in self::convertJp2ToDjvu(). */
	protected $pageCount = 0;

	/**
	 * @inheritDoc
	 * @return string
	 */
	public function createLocalDjvu() {
		$this->downloadFiles();
		$jp2Directory = $this->unzipDownloadedJp2Archive();
		$djvuFile = $this->convertJp2ToDjvu( $jp2Directory );
		$this->addXmlToDjvu( $djvuFile );
		$this->validateDjvu( $djvuFile );
		return $djvuFile;
	}

	/**
	 * Download metadata, xml, and zip files to the job directory. Check for each before
	 * downloading, in case this has been previously interrupted.
	 */
	protected function downloadFiles() {
		// Metadata.
		$metadataFile = $this->jobDir() . '/metadata.json';
		if ( !file_exists( $metadataFile ) ) {
			$this->log->info( "Saving IA metadata to $metadataFile" );
			$metadata = $this->iaClient->fileDetails( $this->itemId );
			file_put_contents( $metadataFile, \GuzzleHttp\json_encode( $metadata ) );
		}
		if ( !isset( $metadata ) ) {
			$metadata = \GuzzleHttp\json_decode( file_get_contents( $metadataFile ), true );
		}

		// Other files (JP2 and DjVu XML).
		$filesToDownload = preg_grep( '/.*(_djvu.xml|_jp2.zip)/', array_keys( $metadata['files'] ) );
		foreach ( $filesToDownload as $file ) {
			if ( !file_exists( $this->jobDir() . $file ) ) {
				$this->log->info( "Downloading $this->itemId$file" );
				$this->iaClient->downloadFile( $this->itemId . $file, $this->jobDir() . $file );
			}
		}
	}

	/**
	 * Unzip the _jp2.zip file to `<item>_jp2/` (which is the top-level directory that's within the
	 * zip) in the job directory.
	 * @return string The full path of the _jp2 directory, with no trailing slash.
	 * @throws Exception If the zip file doesn't exist.
	 */
	protected function unzipDownloadedJp2Archive() {
		$zipFiles = preg_grep( '/^.*_jp2\.zip$/', scandir( $this->jobDir() ) );
		if ( count( $zipFiles ) == 0 ) {
			throw new Exception( "JP2 zip file not found" );
		}
		$zipFile = $this->jobDir() . '/' . array_shift( $zipFiles );
		$this->log->info( "Unzipping $zipFile" );
		$zip = new ZipArchive();
		$zip->open( $zipFile );

		$outDir = dirname( $zipFile ) . '/' . pathinfo( $zipFile, PATHINFO_FILENAME );
		if ( is_dir( $outDir ) ) {
			// Directory already exists; check contents.
			// Minus 2 for dot and dot-dot.
			$numInDir = count( scandir( $outDir ) ) - 2;
			// Minus 1 for the top-level directory.
			$numInZip = $zip->numFiles - 1;
			if ( $numInDir === $numInZip ) {
				// All done.
				return $outDir;
			}
		}

		// Extract file.
		$zip->extractTo( $this->jobDir() );
		$this->log->debug( "Zip file extracted to $outDir" );
		return $outDir;
	}

	/**
	 * Convert each JP2 file to a DjVu in the same directory.
	 * @param string $itemDir The directory containing the JP2 files, with no trailing slash.
	 * @return string The full path to the single combined DjVu file.
	 * @throws Exception
	 */
	protected function convertJp2ToDjvu( $itemDir ) {
		$this->log->info( "Processing JP2 files" );
		$jp2Files = preg_grep( '/^.*\.jp2$/', scandir( $itemDir ) );
		if ( count( $jp2Files ) === 0 ) {
			throw new Exception( "No JP2 file found in " . $itemDir );
		}
		$djvuFiles = [];
		$this->log->info( "Converting " . count( $jp2Files ) . " individual JP2s to DjVus" );
		// Create a destination directory in which to create the intermediate files.
		$buildDir = $this->jobDir() . '/build';
		if ( !is_dir( $buildDir ) ) {
			mkdir( $buildDir );
		}
		foreach ( $jp2Files as $jp2FileNum => $jp2FileName ) {
			$jp2File = $itemDir . '/' . $jp2FileName;
			$this->log->debug( "Converting $jp2File..." );

			// Create Jpeg of this page.
			$jpgFile = $buildDir . '/' . $this->itemId . '_p' . $jp2FileNum . '.jpg';
			if ( !file_exists( $jpgFile ) ) {
				$this->log->debug( "...to $jpgFile" );
				$convertArgs = "-resize 1500x1500 \"$jp2File\" \"$jpgFile\"";
				$this->runCommand( 'gm convert', $convertArgs );
			}

			// Make DjVu file of this page. Use the item identifier as the filename instead of
			// matching the JP2 so we can later modify the XML more easily.
			$djvuFile = $buildDir . '/' . $this->itemId . '_p' . $jp2FileNum . '.djvu';
			if ( !file_exists( $djvuFile ) ) {
				$this->log->debug( "...to $djvuFile" );
				$this->runCommand( "c44", " \"$jpgFile\" \"$djvuFile\"" );
			}
			$djvuFiles[] = $djvuFile;
		}
		$this->pageCount = count( $djvuFiles );

		// Merge all DjVu files into one.
		$singleDjvuFile = $this->jobDir().'/'.$this->itemId.'.djvu';
		if ( !file_exists( $singleDjvuFile ) ) {
			$this->log->info( "Merging all DjVu files to $singleDjvuFile" );
			$djvuFileList = '"' . join( '" "', $djvuFiles ) . '"';
			$this->runCommand( "djvm", "-c \"$singleDjvuFile\" " . $djvuFileList );
		}
		return $singleDjvuFile;
	}

	/**
	 * @param string $djvuFile Full path to the main DjVu file.
	 * @throws Exception
	 */
	public function addXmlToDjvu( $djvuFile ) {
		$djvuXmlFiles = preg_grep( '/^.*_djvu\.xml$/', scandir( $this->jobDir() ) );
		if ( count( $djvuXmlFiles ) === 0 ) {
			throw new Exception( "No '*_djvu.xml' file found" );
		}
		$djvuXmlFile = $this->jobDir() . '/' . array_shift( $djvuXmlFiles );
		if ( !file_exists( $djvuXmlFile ) ) {
			throw new Exception( "File not found: $djvuXmlFile" );
		}

		$newDjvuXmlFile = $djvuXmlFile . '_new.xml';
		if ( file_exists( $newDjvuXmlFile ) ) {
			// Assume that if the modified XML file exists, it's also been merged into the DjVu.
			return;
		}

		// Replace URLs in the XML. Every OBJECT element has a 'data' attribute which is a URI.
		$this->log->info( "Modifying DjVu XML file $djvuXmlFile to add $djvuFile" );
		$xml = simplexml_load_file( $djvuXmlFile, null, LIBXML_NOENT );
		// If we can't load the XML file, just return because at least a plain DjVu will have
		// been created (this is usually for languages not supported by IA OCR).
		if ( $xml === false || !isset( $xml->BODY ) ) {
			$this->log->info( "Unable to load XML file or no BODY element found in '$xml'" );
			return;
		}
		$pageNum = 0;
		foreach ( $xml->BODY->OBJECT as $object ) {
			$object['data'] = 'file://localhost'.$djvuFile;
			// The first PARAM is always 'PAGE'.
			$object->PARAM[0]['value'] = $this->itemId . '_p' . $pageNum . '.djvu';
			$pageNum++;
		}
		// Save modified XML to a new file.
		$xml->asXML( $newDjvuXmlFile );

		// Modify the DjVu file with the contents of the XML file. (The target DjVu file is the file
		// referenced by the OBJECT element of the XML file).
		$this->log->info( "Merging modified XML into full DjVu file" );
		$this->runCommand( 'djvuxmlparser', '"'. $newDjvuXmlFile . '"' );
	}

	/**
	 * Validate the DjVu and remove any corrupted pages.
	 * @param string $djvuFile Full path to the full, text-layer'd DjVu file.
	 * @return bool False if validation failed.
	 */
	public function validateDjvu( $djvuFile ) {
		// Not using self::runCommand() in this method because we want the return values.
		$this->log->info( "Validating text layer of DjVu" );

		// First check the whole file.
		exec( "djvused -u '$djvuFile' -e 'select; output-txt' 2>&1", $out, $retVar );
		if ( $retVar === 0 ) {
			$this->log->debug( "Text layer OK" );
			return true;
		}
		if ( $retVar !== 10 ) {
			$this->log->error( "Unable to validate DjVu: $djvuFile" );
			return false;
		}

		// If the whole file didn't validate, loop through each page looking for and fixing any errors.
		for ( $pageNum = 1; $pageNum <= $this->pageCount; $pageNum++ ) {
			// Check this page.
			exec( "djvused -u '$djvuFile' -e 'select $pageNum; output-txt' 2>&1", $out, $retVar );
			if ( $retVar === 0 ) {
				// Page is okay.
				continue;
			}
			if ( $retVar !== 10 ) {
				// Page has some other sort of error.
				$this->log->error( "Unable to validate DjVu page $pageNum in $djvuFile" );
				continue;
			}

			// Try to fix this page by removing the text from it.
			$this->log->info( "Fixing page $pageNum (1-indexed)" );
			exec( "djvused -u '$djvuFile' -e 'select $pageNum; remove-txt; save'", $out, $retVar );
			if ( $retVar !== 0 ) {
				$this->log->error( "Unable to fix page $pageNum in $djvuFile" );
				continue;
			}
		}
		$this->log->info( "Validation complete" );
	}

	/**
	 * Run an external command, first checking that it exists by using 'which'.
	 * @param string $command The command to run.
	 * @param string $args The arguments to the command. Will be appended to the command as-is.
	 * @throws Exception If the command can't be found.
	 */
	protected function runCommand( $command, $args ) {
		if ( !Command::exec( "which $command" ) ) {
			throw new Exception( "Command $command not found" );
		}
		$commandOutput = Command::exec( "$command $args" );
		if ( $commandOutput ) {
			$this->log->debug( $commandOutput );
		}
	}
}
